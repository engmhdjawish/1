using System.Text.RegularExpressions;

namespace ExistingDb.Api.Services.Bills;

/// <summary>
/// Builds the reservation-invoice batches. Every procedure argument is named.
/// dbo.prcBillItem_Add parameter list observed on AmnDb2026 (pass every one by name;
/// do not rely on positional arguments or omitted defaults):
/// @Number float, @Qty float, @Order float, @OrderQnt float, @Unity float, @Price float,
/// @BonusQnt float, @Discount float, @BonusDisc float, @Extra float, @CurrencyVal float,
/// @Notes nvarchar, @Profits float, @Num1 float, @Num2 float, @Qty2 float, @Qty3 float,
/// @ClassPtr nvarchar, @ExpireDate datetime, @ProductionDate datetime, @Length float,
/// @Width float, @Height float, @GUID uniqueidentifier, @VAT float, @VATRatio float,
/// @ParentGUID uniqueidentifier, @MatGUID uniqueidentifier, @CurrencyGUID uniqueidentifier,
/// @StoreGUID uniqueidentifier, @CostGUID uniqueidentifier, @SOType int,
/// @SOGuid uniqueidentifier, @Count float, @SOGroup int, @TotalDiscountPercent float,
/// @TotalExtraPercent float, @ContractItemGuid uniqueidentifier, @ContractDiscount float,
/// @ClassPrice float, @IsDiscountValue bit, @IsExtraValue bit, @TaxCode int,
/// @ExciseTaxVal float, @PurchaseVal float, @ReversChargeVal float, @ExciseTaxPercent float,
/// @ExciseTaxCode int, @RelatedTo uniqueidentifier, @CustomsRate float, @OrginalTaxCode int,
/// @NetProfit float, @NetProfitAfterTax float, @SalesTax money, @ReversChargeTaxRatio float,
/// @Barcode nvarchar, @DiscAfterTaxValue float, @EDeclarationState int, @ProfitMargin float.
/// The @Order value is selected from an existing bi000 row into @OrderSentinel.
/// </summary>
public static class ReservationBillSql
{
    public const string EnableAbortOnError = "SET XACT_ABORT ON;";

    public static string BuildOwnershipScript() => """
        SET NOCOUNT ON;
        SELECT
            b.GUID,
            b.Number,
            b.TypeGUID,
            b.StoreGUID,
            b.IsPosted,
            (SELECT COUNT(*) FROM dbo.vwER_EntriesBills WHERE erBillGUID = b.GUID) AS EntryCount,
            (SELECT COUNT(*) FROM dbo.pt000 WHERE RefGUID = b.GUID) AS PaymentCount,
            (SELECT COUNT(*) FROM dbo.bi000 WHERE ParentGUID = b.GUID AND MatGUID IS NULL) AS NullMaterialLines
        FROM dbo.bu000 AS b WITH (UPDLOCK, HOLDLOCK)
        WHERE b.GUID = @BillGuid;

        SELECT
            MatGUID,
            SUM(CAST(Qty AS float)) AS Qty
        FROM dbo.bi000 WITH (UPDLOCK, HOLDLOCK)
        WHERE ParentGUID = @BillGuid
        GROUP BY MatGUID;
        """;

    public static string BuildCreateScript(int lineCount)
    {
        var sql = $"""
            SET NOCOUNT ON;
            {SessionSetup}
            DECLARE @TemplateGuid uniqueidentifier;
            DECLARE @NextNumber float;
            DECLARE @OrderSentinel float;
            DECLARE @SourceGuid uniqueidentifier;

            SELECT TOP (1) @TemplateGuid = GUID
            FROM dbo.bu000 WITH (UPDLOCK, HOLDLOCK)
            WHERE TypeGUID = @TypeGuid
              AND StoreGUID = @StoreGuid
              AND IsPosted = 1
            ORDER BY [Date] DESC, Number DESC;

            IF @TemplateGuid IS NULL
                THROW 50031, N'No posted reservation bill exists to copy the header from.', 1;

            SELECT @NextNumber = ISNULL(MAX(CAST(Number AS float)), 0) + 1
            FROM dbo.bu000 WITH (UPDLOCK, HOLDLOCK)
            WHERE TypeGUID = @TypeGuid;

            SELECT TOP (1) @OrderSentinel = bi.[Order]
            FROM dbo.bi000 AS bi
            INNER JOIN dbo.bu000 AS bu ON bu.GUID = bi.ParentGUID
            WHERE bu.TypeGUID = @TypeGuid
              AND bu.StoreGUID = @StoreGuid
              AND bi.[Order] IS NOT NULL
            ORDER BY bu.[Date] DESC, bu.Number DESC;

            IF @OrderSentinel IS NULL
                THROW 50030, N'No reservation line order value exists to copy.', 1;

            SET @SourceGuid = @TemplateGuid;
            {CopyCurrencyVal("@TemplateGuid")}
            {ConnectionsUpsert}
            {HeaderInsert()}
            {LineCalls(lineCount)}
            {UpdateNoteAndTotal}
            {PostBill};
            """;
        ReservationBillSqlSafety.EnsureSafe(sql);
        return sql;
    }

    public static string BuildUpdateScript(int lineCount)
    {
        var sql = $"""
            SET NOCOUNT ON;
            {SessionSetup}
            DECLARE @OrderSentinel float;
            DECLARE @SourceGuid uniqueidentifier;
            SET @SourceGuid = @BillGuid;

            SELECT TOP (1) @OrderSentinel = bi.[Order]
            FROM dbo.bi000 AS bi
            INNER JOIN dbo.bu000 AS bu ON bu.GUID = bi.ParentGUID
            WHERE bu.TypeGUID = @TypeGuid
              AND bu.StoreGUID = @StoreGuid
              AND bi.[Order] IS NOT NULL
            ORDER BY CASE WHEN bu.GUID = @BillGuid THEN 0 ELSE 1 END, bu.[Date] DESC, bu.Number DESC;

            IF @OrderSentinel IS NULL
                THROW 50030, N'No reservation line order value exists to copy.', 1;

            {CopyCurrencyVal("@BillGuid")}
            {ConnectionsUpsert}
            UPDATE dbo.bu000 SET IsPosted = 0 WHERE GUID = @BillGuid;
            DELETE FROM dbo.bi000 WHERE ParentGUID = @BillGuid;
            {LineCalls(lineCount)}
            {UpdateNoteAndTotal}
            {PostBill};
            """;
        ReservationBillSqlSafety.EnsureSafe(sql);
        return sql;
    }

    public static string BuildDeleteScript()
    {
        var sql = $"""
            SET NOCOUNT ON;
            {SessionSetup}
            DECLARE @SourceGuid uniqueidentifier;
            SET @SourceGuid = @BillGuid;
            {ConnectionsUpsert}
            {DeleteBill};
            """;
        ReservationBillSqlSafety.EnsureSafe(sql);
        return sql;
    }

    public static string BuildCreateObservationScript() => BillStateSelect;

    public static string BuildUpdateObservationScript() => BillStateSelect;

    public static string BuildDeleteObservationScript() => BillStateSelect;

    public static string BuildResetIgnoreWarnings() => """
        UPDATE dbo.connections
        SET IgnoreWarnings = 0,
            Exclusive = 0
        WHERE SPID = @@SPID;
        """;

    public static string LineParameterPrefix(int index) => $"@p{index}_";

    public static string StoreQuantityParameter(int index)
    {
        if (index < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(index));
        }

        return $"@m{index}";
    }

    /// <summary>
    /// Reads live <c>ms000</c> balances for the materials touched by this bill.
    /// A normal parameterized select stays valid after <c>prcBill_Post1</c> rolls
    /// the transaction back. Temp tables created in that transaction would not.
    /// </summary>
    public static string BuildStoreQuantityScript(int materialCount)
    {
        if (materialCount <= 0)
        {
            throw new ArgumentOutOfRangeException(nameof(materialCount));
        }

        var parameters = string.Join(", ", Enumerable.Range(0, materialCount).Select(StoreQuantityParameter));
        var sql = $"""
            SELECT
                MatGUID,
                StoreGUID,
                SUM(CAST(Qty AS float)) AS Qty
            FROM dbo.ms000 WITH (UPDLOCK, HOLDLOCK)
            WHERE MatGUID IN ({parameters})
            GROUP BY MatGUID, StoreGUID;
            """;
        ReservationBillSqlSafety.EnsureSafe(sql);
        return sql;
    }

    private const string BillStateSelect = """
        SELECT
            CASE WHEN b.GUID IS NULL THEN 0 ELSE 1 END AS BillExists,
            b.Number AS BillNumber,
            b.TypeGUID AS TypeGuid,
            b.StoreGUID AS StoreGuid,
            CASE WHEN b.IsPosted = 1 THEN 1 ELSE 0 END AS IsPosted,
            (SELECT COUNT(*) FROM dbo.vwER_EntriesBills WHERE erBillGUID = @BillGuid) AS EntryCount,
            (SELECT COUNT(*) FROM dbo.pt000 WHERE RefGUID = @BillGuid) AS PaymentCount
        FROM (SELECT @BillGuid AS BillGuid) AS requested
        LEFT JOIN dbo.bu000 AS b ON b.GUID = requested.BillGuid;
        """;

    private const string SessionSetup = """
        DECLARE @loginTime datetime;
        SELECT @loginTime = login_time
        FROM sys.sysprocesses
        WHERE spid = @@SPID;
        IF @loginTime IS NULL
            THROW 50033, N'Cannot read the session login_time for the connections row.', 1;
        """;

    /// <summary>
    /// prcConnections_SetIgnoreWarnings only updates an existing row, so this inserts when needed.
    /// Exclusive stays 0. IgnoreWarnings is cleared by <see cref="BuildResetIgnoreWarnings"/>
    /// after the transaction, before the connection returns to the pool.
    /// </summary>
    private const string ConnectionsUpsert = """
        DECLARE @connUser uniqueidentifier;
        DECLARE @connLanguage int;
        DECLARE @connBranch bigint;
        DECLARE @connUserNumber int;

        IF COL_LENGTH(N'dbo.bu000', N'UserGUID') IS NOT NULL
        BEGIN
            EXEC sp_executesql
                N'SELECT @u = UserGUID FROM dbo.bu000 WHERE GUID = @g',
                N'@g uniqueidentifier, @u uniqueidentifier OUTPUT',
                @g = @SourceGuid,
                @u = @connUser OUTPUT;
        END

        SELECT TOP (1)
            @connLanguage = Language,
            @connBranch = BranchMask,
            @connUserNumber = UserNumber
        FROM dbo.connections
        WHERE UserGUID = @connUser;

        IF @connUser IS NULL
        BEGIN
            SELECT TOP (1)
                @connUser = UserGUID,
                @connLanguage = Language,
                @connBranch = BranchMask,
                @connUserNumber = UserNumber
            FROM dbo.connections
            WHERE UserGUID IS NOT NULL
            ORDER BY Start DESC;
        END

        IF @connLanguage IS NULL SET @connLanguage = 0;
        IF @connBranch IS NULL SET @connBranch = 0;
        IF @connUserNumber IS NULL SET @connUserNumber = 0;
        IF @connUser IS NULL
            THROW 50034, N'Cannot resolve a user for the connections row.', 1;

        IF EXISTS (SELECT 1 FROM dbo.connections WHERE SPID = @@SPID)
        BEGIN
            UPDATE dbo.connections
            SET login_time = @loginTime,
                UserGUID = @connUser,
                Language = @connLanguage,
                Exclusive = 0,
                Start = GETDATE(),
                BranchMask = @connBranch,
                UserNumber = @connUserNumber,
                HostId = HOST_ID(),
                HostName = HOST_NAME(),
                IgnoreWarnings = 1
            WHERE SPID = @@SPID;
        END
        ELSE
        BEGIN
            INSERT INTO dbo.connections
                (SPID, login_time, UserGUID, Language, Exclusive, Start, BranchMask, UserNumber, HostId, HostName, IgnoreWarnings)
            VALUES
                (@@SPID, @loginTime, @connUser, @connLanguage, 0, GETDATE(), @connBranch, @connUserNumber, HOST_ID(), HOST_NAME(), 1);
        END
        """;

    private static string CopyCurrencyVal(string sourceGuidVariable) =>
        $"""
        DECLARE @CurrencyVal float = 1;
        IF COL_LENGTH(N'dbo.bu000', N'CurrencyVal') IS NOT NULL
        BEGIN
            DECLARE @copiedCurrencyVal float;
            EXEC sp_executesql
                N'SELECT @v = CONVERT(float, CurrencyVal) FROM dbo.bu000 WHERE GUID = @g',
                N'@g uniqueidentifier, @v float OUTPUT',
                @g = {sourceGuidVariable},
                @v = @copiedCurrencyVal OUTPUT;
            IF @copiedCurrencyVal IS NOT NULL AND @copiedCurrencyVal > 0
                SET @CurrencyVal = @copiedCurrencyVal;
        END
        """;

    /// <summary>
    /// Numeric header columns copied from the template and forced to zero.
    /// Al-Ameen compares this primary key with a case-insensitive collation, so
    /// VatValue and VATValue are the same key and must be inserted once.
    /// </summary>
    private static readonly string[] AmountColumnCandidates =
    [
        "TotalDisc", "Discount", "Disc", "TotalExtra", "Extra", "Profits",
        "VAT", "VATValue", "VatValue", "Paid", "Remain", "Cash",
        "Credit", "Debit", "Bonus", "BonusQnt", "NetProfit", "SalesTax",
        "TotalVAT", "DiscValue", "ExtraValue", "Additions", "TotalAdd",
        "Payed", "PaidValue", "FirstPayment", "TotalBonus"
    ];

    private static string HeaderInsert() =>
        $"""
        DECLARE @AmountColumns TABLE (Name sysname NOT NULL PRIMARY KEY);
        INSERT INTO @AmountColumns (Name) VALUES
            {AmountColumnValueList()};

        DECLARE @insertCols nvarchar(max);
        DECLARE @selectVals nvarchar(max);
        SELECT @insertCols = STUFF((
            SELECT N',' + QUOTENAME(c.name)
            FROM sys.columns AS c
            WHERE c.object_id = OBJECT_ID(N'dbo.bu000')
              AND c.is_computed = 0
              AND c.is_identity = 0
              AND c.system_type_id <> 189
            ORDER BY c.column_id
            FOR XML PATH(''), TYPE
        ).value(N'.', N'nvarchar(max)'), 1, 1, N'');

        SELECT @selectVals = STUFF((
            SELECT N',' +
                CASE
                    WHEN c.name = N'GUID' THEN N'@BillGuid'
                    WHEN c.name = N'Number' THEN N'@NextNumber'
                    WHEN c.name = N'Date' THEN N'CONVERT(datetime, CONVERT(date, GETDATE()))'
                    WHEN c.name = N'Notes' THEN N'@OwnerName'
                    WHEN c.name = N'Total' THEN N'@LineTotal'
                    WHEN c.name = N'Qty' THEN N'@TotalQty'
                    WHEN c.name = N'IsPosted' THEN N'0'
                    WHEN c.name = N'PhaseNumber' THEN N'0'
                    WHEN c.name = N'ActivityState' THEN N'0'
                    WHEN c.name = N'StoreGUID' THEN N'@StoreGuid'
                    WHEN c.name = N'TypeGUID' THEN N'@TypeGuid'
                    WHEN c.name = N'CurrencyGUID' THEN N'@BillCurrencyGuid'
                    WHEN c.name = N'PayType' THEN N'0'
                    WHEN c.name IN (N'CustGUID', N'CustomerGUID') THEN N'CAST(''00000000-0000-0000-0000-000000000000'' AS uniqueidentifier)'
                    WHEN EXISTS (SELECT 1 FROM @AmountColumns AS a WHERE a.Name = c.name)
                         AND c.system_type_id IN (48, 52, 56, 59, 60, 62, 106, 108, 122, 127)
                        THEN N'0'
                    ELSE N't.' + QUOTENAME(c.name)
                END
            FROM sys.columns AS c
            WHERE c.object_id = OBJECT_ID(N'dbo.bu000')
              AND c.is_computed = 0
              AND c.is_identity = 0
              AND c.system_type_id <> 189
            ORDER BY c.column_id
            FOR XML PATH(''), TYPE
        ).value(N'.', N'nvarchar(max)'), 1, 1, N'');

        IF @insertCols IS NULL OR @selectVals IS NULL
            THROW 50035, N'Could not read bu000 columns for the reservation header.', 1;

        DECLARE @insertSql nvarchar(max) = N'INSERT INTO dbo.bu000 (' + @insertCols
            + N') SELECT ' + @selectVals + N' FROM dbo.bu000 AS t WHERE t.GUID = @TemplateGuid';
        EXEC sp_executesql @insertSql,
            N'@BillGuid uniqueidentifier, @NextNumber float, @OwnerName nvarchar(1000), @LineTotal float, @TotalQty float, @StoreGuid uniqueidentifier, @TypeGuid uniqueidentifier, @BillCurrencyGuid uniqueidentifier, @TemplateGuid uniqueidentifier',
            @BillGuid = @BillGuid,
            @NextNumber = @NextNumber,
            @OwnerName = @OwnerName,
            @LineTotal = @LineTotal,
            @TotalQty = @TotalQty,
            @StoreGuid = @StoreGuid,
            @TypeGuid = @TypeGuid,
            @BillCurrencyGuid = @BillCurrencyGuid,
            @TemplateGuid = @TemplateGuid;
        """;

    private const string UpdateNoteAndTotal = """
        UPDATE dbo.bu000
        SET Notes = @OwnerName,
            Total = @LineTotal
        WHERE GUID = @BillGuid;

        IF COL_LENGTH(N'dbo.bu000', N'PayType') IS NOT NULL
            EXEC sp_executesql
                N'UPDATE dbo.bu000 SET PayType = 0 WHERE GUID = @BillGuid',
                N'@BillGuid uniqueidentifier',
                @BillGuid = @BillGuid;

        IF COL_LENGTH(N'dbo.bu000', N'CustGUID') IS NOT NULL
            EXEC sp_executesql
                N'UPDATE dbo.bu000 SET CustGUID = ''00000000-0000-0000-0000-000000000000'' WHERE GUID = @BillGuid',
                N'@BillGuid uniqueidentifier',
                @BillGuid = @BillGuid;

        IF COL_LENGTH(N'dbo.bu000', N'StoreGUID') IS NOT NULL
            EXEC sp_executesql
                N'UPDATE dbo.bu000 SET StoreGUID = @StoreGuid WHERE GUID = @BillGuid',
                N'@BillGuid uniqueidentifier, @StoreGuid uniqueidentifier',
                @BillGuid = @BillGuid,
                @StoreGuid = @StoreGuid;

        IF COL_LENGTH(N'dbo.bu000', N'CurrencyGUID') IS NOT NULL
            EXEC sp_executesql
                N'UPDATE dbo.bu000 SET CurrencyGUID = @BillCurrencyGuid WHERE GUID = @BillGuid',
                N'@BillGuid uniqueidentifier, @BillCurrencyGuid uniqueidentifier',
                @BillGuid = @BillGuid,
                @BillCurrencyGuid = @BillCurrencyGuid;

        EXEC dbo.prcupdatebuNote @Guid = @BillGuid, @Note = @OwnerName;
        """;

    // Al-Ameen posts with prcBill_Post1 after the header is saved unposted.
    // A direct IsPosted update still runs trg_bu000_UpdateCustomerPrice, and a NULL
    // CustGUID collides with cp000. The program stores the empty customer guid.
    private const string PostBill = """
        EXEC dbo.prcBill_Post1 @BillGuid, 1;
        """;

    private const string DeleteBill = """
        DECLARE @deleteObject int = OBJECT_ID(N'dbo.prcBill_delete');
        DECLARE @paramCount int = 0;
        DECLARE @guidCount int = 0;
        DECLARE @paramName sysname = NULL;

        IF @deleteObject IS NOT NULL
        BEGIN
            SELECT @paramCount = COUNT(*)
            FROM sys.parameters
            WHERE object_id = @deleteObject;

            SELECT
                @guidCount = COUNT(*),
                @paramName = MIN(name)
            FROM sys.parameters
            WHERE object_id = @deleteObject
              AND user_type_id = TYPE_ID(N'uniqueidentifier')
              AND is_output = 0;
        END

        IF @deleteObject IS NOT NULL
           AND @paramCount = 1
           AND @guidCount = 1
           AND @paramName LIKE N'@[A-Za-z]%'
           AND @paramName NOT LIKE N'%[^@0-9A-Za-z_]%'
        BEGIN
            DECLARE @deleteSql nvarchar(200) = N'EXEC dbo.prcBill_delete ' + @paramName + N' = @BillGuid;';
            EXEC sp_executesql @deleteSql, N'@BillGuid uniqueidentifier', @BillGuid = @BillGuid;
        END
        ELSE
        BEGIN
            UPDATE dbo.bu000 SET IsPosted = 0 WHERE GUID = @BillGuid;
            DELETE FROM dbo.bi000 WHERE ParentGUID = @BillGuid;
            DELETE FROM dbo.bu000 WHERE GUID = @BillGuid;
        END
        """;

    private static IReadOnlyList<string> DistinctAmountColumnNames()
    {
        var names = new List<string>(AmountColumnCandidates.Length);
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var name in AmountColumnCandidates)
        {
            if (!IsSqlIdentifier(name))
            {
                throw new InvalidOperationException($"Amount column '{name}' is not a single SQL identifier.");
            }

            if (seen.Add(name))
            {
                names.Add(name);
            }
        }

        return names;
    }

    private static string AmountColumnValueList()
    {
        var literals = DistinctAmountColumnNames().Select(name => $"(N'{name}')").ToArray();
        var lines = new List<string>();
        for (var index = 0; index < literals.Length; index += 6)
        {
            lines.Add(string.Join(", ", literals.Skip(index).Take(6)));
        }

        return string.Join(",\n            ", lines);
    }

    private static bool IsSqlIdentifier(string name)
    {
        if (name.Length == 0 || !(char.IsAsciiLetter(name[0]) || name[0] == '_'))
        {
            return false;
        }

        for (var index = 1; index < name.Length; index++)
        {
            if (!(char.IsAsciiLetterOrDigit(name[index]) || name[index] == '_'))
            {
                return false;
            }
        }

        return true;
    }

    private static string LineCalls(int lineCount)
    {
        var calls = new string[lineCount];
        for (var index = 0; index < lineCount; index++)
        {
            var prefix = LineParameterPrefix(index);
            calls[index] = $"""
                EXEC dbo.prcBillItem_Add
                    @Number = {prefix}Number,
                    @Qty = {prefix}Qty,
                    @Order = @OrderSentinel,
                    @OrderQnt = 0,
                    @Unity = {prefix}Unity,
                    @Price = {prefix}Price,
                    @BonusQnt = 0,
                    @Discount = 0,
                    @BonusDisc = 0,
                    @Extra = 0,
                    @CurrencyVal = @CurrencyVal,
                    @Notes = NULLIF({prefix}Notes, N''),
                    @Profits = 0,
                    @Num1 = 0,
                    @Num2 = 0,
                    @Qty2 = {prefix}Qty2,
                    @Qty3 = 0,
                    @ClassPtr = NULL,
                    @ExpireDate = NULL,
                    @ProductionDate = NULL,
                    @Length = 0,
                    @Width = 0,
                    @Height = 0,
                    @GUID = {prefix}Guid,
                    @VAT = 0,
                    @VATRatio = 0,
                    @ParentGUID = @BillGuid,
                    @MatGUID = {prefix}Mat,
                    @CurrencyGUID = @BillCurrencyGuid,
                    @StoreGUID = @StoreGuid,
                    @CostGUID = NULL,
                    @SOType = 0,
                    @SOGuid = NULL,
                    @Count = 0,
                    @SOGroup = 0,
                    @TotalDiscountPercent = 0,
                    @TotalExtraPercent = 0,
                    @ContractItemGuid = NULL,
                    @ContractDiscount = 0,
                    @ClassPrice = 0,
                    @IsDiscountValue = 0,
                    @IsExtraValue = 0,
                    @TaxCode = 0,
                    @ExciseTaxVal = 0,
                    @PurchaseVal = 0,
                    @ReversChargeVal = 0,
                    @ExciseTaxPercent = 0,
                    @ExciseTaxCode = 0,
                    @RelatedTo = NULL,
                    @CustomsRate = 0,
                    @OrginalTaxCode = 0,
                    @NetProfit = 0,
                    @NetProfitAfterTax = 0,
                    @SalesTax = 0,
                    @ReversChargeTaxRatio = 0,
                    @Barcode = NULL,
                    @DiscAfterTaxValue = 0,
                    @EDeclarationState = 0,
                    @ProfitMargin = 0;
                """;
        }

        return string.Join("\n", calls);
    }
}

public static partial class ReservationBillSqlSafety
{
    private static readonly string[] ForbiddenFragments =
    [
        "prcBill_genEntry",
        "prcBill_reGenEntry",
        "prc_InsertOrModifyMaturity",
        "fnFlag_isSet"
    ];

    public static void EnsureSafe(string sql)
    {
        if (string.IsNullOrWhiteSpace(sql))
        {
            throw new InvalidOperationException("Reservation SQL command is empty.");
        }

        foreach (var fragment in ForbiddenFragments)
        {
            if (sql.Contains(fragment, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException($"Reservation SQL must not call {fragment}.");
            }
        }

        if (ExclusiveOne().IsMatch(sql))
        {
            throw new InvalidOperationException("Reservation SQL must not set connections.Exclusive to 1.");
        }

        if (AbortOff().IsMatch(sql))
        {
            throw new InvalidOperationException("Reservation SQL must not turn XACT_ABORT off.");
        }
    }

    [GeneratedRegex(@"\bExclusive\s*=\s*(1|0x1)\b", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex ExclusiveOne();

    [GeneratedRegex(@"\bXACT_ABORT\s+OFF\b", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex AbortOff();
}
