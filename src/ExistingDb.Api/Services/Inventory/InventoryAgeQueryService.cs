using System.Data;
using System.Globalization;
using System.Security.Claims;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Inventory;
using ExistingDb.Api.Data;
using ExistingDb.Api.Services.Materials;
using Microsoft.Data.SqlClient;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Inventory;

public sealed class InventoryAgeQueryService(MainDbContext mainDbContext, IPermissionService permissionService)
{
    private const string MaterialsResourceCode = "materials";

    public async Task<InventoryAgePagedResponse> QueryAsync(
        ClaimsPrincipal user,
        int minAgeDays,
        DateTime? untilDate,
        DateTime? yearStartDate,
        IReadOnlyCollection<Guid> groupGuids,
        string? sort,
        int page,
        int pageSize,
        CancellationToken cancellationToken)
    {
        minAgeDays = Math.Clamp(minAgeDays, 1, 3650);
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var effectiveUntil = (untilDate ?? DateTime.UtcNow).Date;
        var effectiveYearStart = (yearStartDate ?? new DateTime(effectiveUntil.Year, 1, 1)).Date;

        var ageRows = await ExecuteRepGetAgesAsync(minAgeDays, effectiveUntil, cancellationToken);
        var stagnantByMaterial = AggregateStagnant(ageRows, minAgeDays, effectiveYearStart);
        if (stagnantByMaterial.Count == 0)
        {
            return new InventoryAgePagedResponse(
                page,
                pageSize,
                0,
                minAgeDays,
                effectiveUntil,
                effectiveYearStart,
                Array.Empty<InventoryAgeItemResponse>());
        }

        var materialGuids = stagnantByMaterial.Keys.ToArray();
        var materialsQuery = mainDbContext.Materials
            .AsNoTracking()
            .Where(material => materialGuids.Contains(material.Guid));

        if (groupGuids.Count > 0)
        {
            materialsQuery = materialsQuery.Where(material =>
                material.GroupGuid.HasValue && groupGuids.Contains(material.GroupGuid.Value));
        }

        var materials = await materialsQuery.ToListAsync(cancellationToken);
        var fieldAccess = await permissionService.GetFieldAccessAsync(user, MaterialsResourceCode, cancellationToken);

        var items = materials
            .Select(material =>
            {
                var age = stagnantByMaterial[material.Guid];
                return new InventoryAgeItemResponse(
                    material.Guid,
                    material.Name,
                    material.Code,
                    material.GroupGuid,
                    MaterialPictureGuid.Normalize(material.PictureGuid),
                    age.StagnantRemainingQty,
                    age.TotalRemainingQty,
                    age.MaxAgeDays,
                    age.OldestPurchaseDate,
                    ResolveNumber(fieldAccess, "Half", material.Half),
                    ResolveNumber(fieldAccess, "EndUser", material.EndUser),
                    ResolveNumber(fieldAccess, "Whole", material.Whole));
            })
            .ToList();

        items = ApplySort(items, sort);
        var totalCount = items.Count;
        var pageItems = items.Skip((page - 1) * pageSize).Take(pageSize).ToList();

        return new InventoryAgePagedResponse(
            page,
            pageSize,
            totalCount,
            minAgeDays,
            effectiveUntil,
            effectiveYearStart,
            pageItems);
    }

    private static List<InventoryAgeItemResponse> ApplySort(List<InventoryAgeItemResponse> items, string? sort)
    {
        var key = (sort ?? "age:desc").Trim().ToLowerInvariant();
        return key switch
        {
            "name:asc" or "name" => items.OrderBy(i => i.Name ?? string.Empty, StringComparer.OrdinalIgnoreCase).ToList(),
            "name:desc" => items.OrderByDescending(i => i.Name ?? string.Empty, StringComparer.OrdinalIgnoreCase).ToList(),
            "price:asc" or "unitsalepriceusd:asc" => items.OrderBy(i => ToDouble(i.UnitSalePriceUsd) ?? 0).ToList(),
            "price:desc" or "unitsalepriceusd:desc" => items.OrderByDescending(i => ToDouble(i.UnitSalePriceUsd) ?? 0).ToList(),
            "purchase:asc" or "unitpurchasepriceusd:asc" => items.OrderBy(i => ToDouble(i.UnitPurchasePriceUsd) ?? 0).ToList(),
            "purchase:desc" or "unitpurchasepriceusd:desc" => items.OrderByDescending(i => ToDouble(i.UnitPurchasePriceUsd) ?? 0).ToList(),
            "qty:asc" => items.OrderBy(i => i.StagnantRemainingQty).ToList(),
            "qty:desc" => items.OrderByDescending(i => i.StagnantRemainingQty).ToList(),
            "age:asc" => items.OrderBy(i => i.MaxAgeDays).ToList(),
            _ => items.OrderByDescending(i => i.MaxAgeDays).ToList(),
        };
    }

    private static double? ToDouble(object? value) =>
        value switch
        {
            null => null,
            double d => d,
            float f => f,
            decimal m => (double)m,
            int i => i,
            long l => l,
            string s when double.TryParse(s, NumberStyles.Any, CultureInfo.InvariantCulture, out var parsed) => parsed,
            _ => null
        };

    private static object? ResolveNumber(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        string fieldCode,
        double? value)
    {
        if (value is null)
        {
            return null;
        }

        if (!fieldAccess.TryGetValue(fieldCode, out var decision))
        {
            return value;
        }

        return decision.ReadMode == FieldAccessMode.Deny ? null : value;
    }

    private sealed record AgeAggregate(
        double StagnantRemainingQty,
        double TotalRemainingQty,
        double MaxAgeDays,
        DateTime? OldestPurchaseDate);

    private static Dictionary<Guid, AgeAggregate> AggregateStagnant(
        IReadOnlyList<IReadOnlyDictionary<string, object?>> rows,
        int minAgeDays,
        DateTime yearStartDate)
    {
        var totals = new Dictionary<Guid, (double Remaining, double Stagnant, double MaxAge, DateTime? Oldest)>();

        foreach (var row in rows)
        {
            if (!TryGetGuid(row, out var matPtr, "MatPtr", "MaterialGuid", "MatGUID", "GUID"))
            {
                continue;
            }

            var remaining = TryGetDouble(row, "Remaining") ?? 0;
            if (remaining <= 0)
            {
                continue;
            }

            var age = TryGetDouble(row, "Age") ?? 0;
            var date = TryGetDate(row, "Date");

            // راكد = عمر الطبقة >= مدة الركود، مع إبقاء سياق «من أول السنة» في OldestPurchaseDate.
            _ = yearStartDate;
            var countsAsStagnant = age >= minAgeDays;

            if (!totals.TryGetValue(matPtr, out var current))
            {
                current = (0, 0, 0, null);
            }

            var oldest = current.Oldest;
            if (date.HasValue && (!oldest.HasValue || date.Value < oldest.Value))
            {
                oldest = date;
            }

            totals[matPtr] = (
                current.Remaining + remaining,
                current.Stagnant + (countsAsStagnant ? remaining : 0),
                Math.Max(current.MaxAge, age),
                oldest);
        }

        return totals
            .Where(pair => pair.Value.Stagnant > 0)
            .ToDictionary(
                pair => pair.Key,
                pair => new AgeAggregate(
                    pair.Value.Stagnant,
                    pair.Value.Remaining,
                    pair.Value.MaxAge,
                    pair.Value.Oldest));
    }

    private async Task<List<IReadOnlyDictionary<string, object?>>> ExecuteRepGetAgesAsync(
        int minAgeDays,
        DateTime untilDate,
        CancellationToken cancellationToken)
    {
        var connection = mainDbContext.Database.GetDbConnection();
        var sqlConnection = connection as SqlConnection
            ?? throw new InvalidOperationException("MainDb provider must be SQL Server.");

        var shouldClose = sqlConnection.State != ConnectionState.Open;
        if (shouldClose)
        {
            await sqlConnection.OpenAsync(cancellationToken);
        }

        var billTypesGuid = Guid.NewGuid();
        string? srcCol = null;

        try
        {
            var schema = await DiscoverRepSrcsSchemaAsync(sqlConnection, cancellationToken);
            srcCol = schema.SrcCol;
            var typeCol = schema.TypeCol;
            var tblCol = schema.TblCol;
            if (string.IsNullOrEmpty(srcCol) || string.IsNullOrEmpty(typeCol) || srcCol == typeCol)
            {
                var found = schema.AllColumns.Count == 0
                    ? "(لا أعمدة — هل الاتصال على MainDb الصحيحة؟)"
                    : string.Join(", ", schema.AllColumns.Select(c => c.Name + ":" + c.TypeName));
                throw new InvalidOperationException(
                    "تعذّر اكتشاف أعمدة RepSrcs (IdSrc/IdType). الأعمدة الموجودة: " + found);
            }

            object? idTblValue = null;
            SqlDbType? idTblSqlType = null;
            if (!string.IsNullOrEmpty(tblCol))
            {
                var resolved = await ResolveBillIdTblAsync(sqlConnection, typeCol, tblCol, cancellationToken);
                idTblValue = resolved.Value;
                idTblSqlType = resolved.SqlType;
                if (idTblValue is null)
                {
                    throw new InvalidOperationException(
                        "عمود RepSrcs.IdTbl إلزامي ولم يُعثر على قيمة مستخدمة مع bt000. من SSMS: افتح «أعمار المواد» مع كل مصادر الفواتير مرة، أو نفّذ: "
                        + $"SELECT TOP 5 {tblCol}, COUNT(*) FROM dbo.RepSrcs r INNER JOIN dbo.bt000 bt ON bt.GUID = r.{typeCol} GROUP BY r.{tblCol} ORDER BY 2 DESC");
                }
            }

            await using (var insertCmd = sqlConnection.CreateCommand())
            {
                if (!string.IsNullOrEmpty(tblCol) && idTblValue is not null && idTblSqlType.HasValue)
                {
                    insertCmd.CommandText = $@"
INSERT INTO dbo.RepSrcs ({QuoteIdent(srcCol)}, {QuoteIdent(typeCol)}, {QuoteIdent(tblCol)})
SELECT @pSrc, bt.[GUID], @pTbl
FROM dbo.[bt000] AS bt;";
                    insertCmd.Parameters.Add(new SqlParameter("@pSrc", SqlDbType.UniqueIdentifier) { Value = billTypesGuid });
                    insertCmd.Parameters.Add(new SqlParameter("@pTbl", idTblSqlType.Value) { Value = idTblValue });
                }
                else
                {
                    insertCmd.CommandText = $@"
INSERT INTO dbo.RepSrcs ({QuoteIdent(srcCol)}, {QuoteIdent(typeCol)})
SELECT @pSrc, bt.[GUID]
FROM dbo.[bt000] AS bt;";
                    insertCmd.Parameters.Add(new SqlParameter("@pSrc", SqlDbType.UniqueIdentifier) { Value = billTypesGuid });
                }

                try
                {
                    var inserted = await insertCmd.ExecuteNonQueryAsync(cancellationToken);
                    if (inserted <= 0)
                    {
                        throw new InvalidOperationException("bt000 فارغ — لا مصادر تقرير لإدراجها في RepSrcs.");
                    }
                }
                catch (SqlException ex)
                {
                    throw new InvalidOperationException(
                        "فشل إدراج مصادر التقرير في RepSrcs: " + ex.Message,
                        ex);
                }
            }

            await EnsureAmineConnectionAsync(sqlConnection, cancellationToken);

            var currency = await ResolveCurrencyAsync(sqlConnection, cancellationToken);
            var periodLength = Math.Max(1, Math.Min(minAgeDays, 365));
            var daysInYear = Math.Max(1, (untilDate - new DateTime(untilDate.Year, 1, 1)).Days + 1);
            var numOfPeriods = Math.Clamp((int)Math.Ceiling(daysInYear / (double)periodLength) + 1, 3, 40);

            await using var proc = sqlConnection.CreateCommand();
            proc.CommandText = "dbo.repGetAges_bi";
            proc.CommandType = CommandType.StoredProcedure;
            proc.CommandTimeout = 180;
            try
            {
                SqlCommandBuilder.DeriveParameters(proc);
            }
            catch (Exception ex)
            {
                throw new InvalidOperationException(
                    "تعذّر قراءة معاملات dbo.repGetAges_bi — هل الإجراء موجود على MainDb؟ " + ex.Message,
                    ex);
            }

            // Match Amine UI / SSMS template: bits as 0/1 (not bool) for SqlDbType compatibility.
            var parameterValues = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["@MatPtr"] = Guid.Empty,
                ["@MatGroup"] = Guid.Empty,
                ["@StorePtr"] = Guid.Empty,
                ["@UntilDate"] = untilDate,
                ["@Detailed"] = 1,
                ["@NumOfPeriods"] = numOfPeriods,
                ["@PeriodLength"] = periodLength,
                ["@ShowBonus"] = 0,
                ["@UseUnit"] = 4,
                ["@CurrencyPtr"] = currency.Guid,
                ["@CurrencyVal"] = currency.Val,
                ["@Billtypes"] = billTypesGuid,
                ["@ShowEmpty"] = 0,
                ["@MatCondGuid"] = Guid.Empty,
                ["@ProcessInBlill"] = 0,
                ["@DetailByClass"] = 0,
                ["@DetailByStore"] = 1,
            };

            foreach (SqlParameter parameter in proc.Parameters)
            {
                if (parameter.Direction == ParameterDirection.ReturnValue)
                {
                    continue;
                }

                if (parameterValues.TryGetValue(parameter.ParameterName, out var assigned))
                {
                    parameter.Value = assigned ?? DBNull.Value;
                }
                else
                {
                    parameter.Value = FallbackParameterValue(parameter);
                }
            }

            try
            {
                var rows = new List<IReadOnlyDictionary<string, object?>>();
                await using var reader = await proc.ExecuteReaderAsync(cancellationToken);
                do
                {
                    while (await reader.ReadAsync(cancellationToken))
                    {
                        var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                        for (var i = 0; i < reader.FieldCount; i++)
                        {
                            row[reader.GetName(i)] = await reader.IsDBNullAsync(i, cancellationToken)
                                ? null
                                : NormalizeSqlValue(reader.GetValue(i));
                        }

                        rows.Add(row);
                    }
                } while (await reader.NextResultAsync(cancellationToken));

                return rows;
            }
            catch (SqlException ex)
            {
                throw new InvalidOperationException(
                    "فشل dbo.repGetAges_bi: " + ex.Message,
                    ex);
            }
        }
        finally
        {
            if (!string.IsNullOrEmpty(srcCol))
            {
                try
                {
                    await using var deleteCmd = sqlConnection.CreateCommand();
                    deleteCmd.CommandText = $"DELETE FROM dbo.RepSrcs WHERE {QuoteIdent(srcCol)} = @pSrc;";
                    deleteCmd.Parameters.Add(new SqlParameter("@pSrc", SqlDbType.UniqueIdentifier) { Value = billTypesGuid });
                    await deleteCmd.ExecuteNonQueryAsync(CancellationToken.None);
                }
                catch
                {
                    // cleanup best-effort
                }
            }

            if (shouldClose)
            {
                await sqlConnection.CloseAsync();
            }
        }
    }

    private sealed record RepSrcsColumn(string Name, string TypeName);

    private sealed record RepSrcsSchema(
        string? SrcCol,
        string? TypeCol,
        string? TblCol,
        IReadOnlyList<RepSrcsColumn> AllColumns);

    private sealed record ResolvedIdTbl(object? Value, SqlDbType SqlType);

    private static async Task<RepSrcsSchema> DiscoverRepSrcsSchemaAsync(
        SqlConnection sqlConnection,
        CancellationToken cancellationToken)
    {
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandText = @"
SELECT c.name, t.name
FROM sys.columns c
INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs')
ORDER BY c.column_id;";
        var columns = new List<RepSrcsColumn>();
        await using (var reader = await cmd.ExecuteReaderAsync(cancellationToken))
        {
            while (await reader.ReadAsync(cancellationToken))
            {
                columns.Add(new RepSrcsColumn(reader.GetString(0), reader.GetString(1)));
            }
        }

        string? PickExact(params string[] candidates)
        {
            foreach (var candidate in candidates)
            {
                var match = columns.FirstOrDefault(c =>
                    string.Equals(c.Name, candidate, StringComparison.OrdinalIgnoreCase));
                if (match is not null)
                {
                    return match.Name;
                }
            }

            return null;
        }

        // SoftTech classic: IdSrc + IdType + IdTbl. Some builds rename GUID-like columns.
        var src = PickExact("IdSrc", "SrcGuid", "SourceGuid", "IdParent", "ParentGUID", "GUID", "Guid");
        var type = PickExact("IdType", "TypeGuid", "TypeGUID", "BillTypeGuid", "BtGuid", "GUIDType");
        var tbl = PickExact("IdTbl", "TableId", "TblId");

        // If IdType missing, take another uniqueidentifier column that is not src/tbl.
        if (type is null)
        {
            type = columns
                .Where(c => string.Equals(c.TypeName, "uniqueidentifier", StringComparison.OrdinalIgnoreCase))
                .Select(c => c.Name)
                .FirstOrDefault(name =>
                    !string.Equals(name, src, StringComparison.OrdinalIgnoreCase)
                    && !string.Equals(name, tbl, StringComparison.OrdinalIgnoreCase));
        }

        if (src is null)
        {
            src = columns
                .Where(c => string.Equals(c.TypeName, "uniqueidentifier", StringComparison.OrdinalIgnoreCase))
                .Select(c => c.Name)
                .FirstOrDefault(name =>
                    !string.Equals(name, type, StringComparison.OrdinalIgnoreCase)
                    && !string.Equals(name, tbl, StringComparison.OrdinalIgnoreCase));
        }

        return new RepSrcsSchema(src, type, tbl, columns);
    }

    /// <summary>
    /// Amine RepSrcs.IdTbl distinguishes source kind (bills vs entries…).
    /// AmnDb builds vary: IdTbl may be INT (classic SoftTech AmnTables) or UNIQUEIDENTIFIER.
    /// </summary>
    private static async Task<ResolvedIdTbl> ResolveBillIdTblAsync(
        SqlConnection sqlConnection,
        string typeCol,
        string tblCol,
        CancellationToken cancellationToken)
    {
        var sqlType = await GetRepSrcsColumnSqlDbTypeAsync(sqlConnection, tblCol, cancellationToken)
            ?? SqlDbType.Int;

        await using var sampleCmd = sqlConnection.CreateCommand();
        sampleCmd.CommandText = $@"
SELECT TOP (1) r.{QuoteIdent(tblCol)} AS IdTbl
FROM dbo.RepSrcs AS r
INNER JOIN dbo.[bt000] AS bt ON bt.[GUID] = r.{QuoteIdent(typeCol)}
GROUP BY r.{QuoteIdent(tblCol)}
ORDER BY COUNT(*) DESC;";
        var sampled = await sampleCmd.ExecuteScalarAsync(cancellationToken);
        if (sampled is not null and not DBNull)
        {
            if (sqlType == SqlDbType.UniqueIdentifier || sampled is Guid)
            {
                return sampled switch
                {
                    Guid g => new ResolvedIdTbl(g, SqlDbType.UniqueIdentifier),
                    string s when Guid.TryParse(s, out var parsed) => new ResolvedIdTbl(parsed, SqlDbType.UniqueIdentifier),
                    byte[] bytes when bytes.Length == 16 => new ResolvedIdTbl(new Guid(bytes), SqlDbType.UniqueIdentifier),
                    _ => new ResolvedIdTbl(null, SqlDbType.UniqueIdentifier)
                };
            }

            try
            {
                return new ResolvedIdTbl(Convert.ToInt32(sampled, CultureInfo.InvariantCulture), SqlDbType.Int);
            }
            catch (InvalidCastException)
            {
                // Fall through to defaults / null
            }
        }

        if (sqlType == SqlDbType.UniqueIdentifier)
        {
            // No prior bill-type rows to copy from — caller must seed via Amine UI once.
            return new ResolvedIdTbl(null, SqlDbType.UniqueIdentifier);
        }

        // SoftTech AmnTables.BILL is typically 2 for report-source rows under «الفواتير».
        return new ResolvedIdTbl(2, SqlDbType.Int);
    }

    private static async Task<SqlDbType?> GetRepSrcsColumnSqlDbTypeAsync(
        SqlConnection sqlConnection,
        string columnName,
        CancellationToken cancellationToken)
    {
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandText = @"
SELECT t.name
FROM sys.columns c
INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs')
  AND c.name = @name;";
        cmd.Parameters.Add(new SqlParameter("@name", SqlDbType.NVarChar, 128) { Value = columnName });
        var typeName = await cmd.ExecuteScalarAsync(cancellationToken) as string;
        return typeName?.ToLowerInvariant() switch
        {
            "uniqueidentifier" => SqlDbType.UniqueIdentifier,
            "int" => SqlDbType.Int,
            "smallint" => SqlDbType.SmallInt,
            "tinyint" => SqlDbType.TinyInt,
            "bigint" => SqlDbType.BigInt,
            _ => null
        };
    }

    private static async Task EnsureAmineConnectionAsync(SqlConnection sqlConnection, CancellationToken cancellationToken)
    {
        await using var adminCmd = sqlConnection.CreateCommand();
        adminCmd.CommandText = @"
SELECT TOP (1) [GUID]
FROM dbo.[us000]
WHERE [bAdmin] = 1
ORDER BY [Number];";
        var adminObj = await adminCmd.ExecuteScalarAsync(cancellationToken);
        if (adminObj is not Guid adminGuid || adminGuid == Guid.Empty)
        {
            return;
        }

        await using var existsCmd = sqlConnection.CreateCommand();
        existsCmd.CommandText = "SELECT OBJECT_ID(N'dbo.prcConnections_add', N'P');";
        var exists = await existsCmd.ExecuteScalarAsync(cancellationToken);
        if (exists is null or DBNull)
        {
            return;
        }

        await using var connCmd = sqlConnection.CreateCommand();
        connCmd.CommandText = "prcConnections_add";
        connCmd.CommandType = CommandType.StoredProcedure;
        try
        {
            SqlCommandBuilder.DeriveParameters(connCmd);
            foreach (SqlParameter parameter in connCmd.Parameters)
            {
                if (parameter.Direction == ParameterDirection.ReturnValue)
                {
                    continue;
                }

                var name = parameter.ParameterName.TrimStart('@');
                if (name.Contains("User", StringComparison.OrdinalIgnoreCase)
                    || name.Contains("GUID", StringComparison.OrdinalIgnoreCase)
                    || string.Equals(name, "GUID", StringComparison.OrdinalIgnoreCase))
                {
                    parameter.Value = adminGuid;
                }
                else
                {
                    parameter.Value = FallbackParameterValue(parameter);
                }
            }

            await connCmd.ExecuteNonQueryAsync(cancellationToken);
        }
        catch
        {
            // optional context; continue without it
        }
    }

    private static async Task<(Guid Guid, double Val)> ResolveCurrencyAsync(
        SqlConnection sqlConnection,
        CancellationToken cancellationToken)
    {
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandText = @"
SELECT TOP (1) [GUID], [CurrencyVal]
FROM dbo.[my000]
WHERE [CurrencyVal] = 1
ORDER BY [Number];";
        await using (var reader = await cmd.ExecuteReaderAsync(cancellationToken))
        {
            if (await reader.ReadAsync(cancellationToken))
            {
                return (reader.GetGuid(0), Convert.ToDouble(reader.GetValue(1), CultureInfo.InvariantCulture));
            }
        }

        await using var fallback = sqlConnection.CreateCommand();
        fallback.CommandText = @"
SELECT TOP (1) [GUID], [CurrencyVal]
FROM dbo.[my000]
ORDER BY [Number];";
        await using var fallbackReader = await fallback.ExecuteReaderAsync(cancellationToken);
        if (await fallbackReader.ReadAsync(cancellationToken))
        {
            return (
                fallbackReader.GetGuid(0),
                Convert.ToDouble(fallbackReader.GetValue(1), CultureInfo.InvariantCulture));
        }

        return (Guid.Empty, 1d);
    }

    private static object FallbackParameterValue(SqlParameter parameter)
    {
        if (parameter.IsNullable)
        {
            return DBNull.Value;
        }

        return parameter.SqlDbType switch
        {
            SqlDbType.UniqueIdentifier => Guid.Empty,
            SqlDbType.Bit => false,
            SqlDbType.Int or SqlDbType.SmallInt or SqlDbType.TinyInt or SqlDbType.BigInt => 0,
            SqlDbType.Float or SqlDbType.Real or SqlDbType.Decimal or SqlDbType.Money or SqlDbType.SmallMoney => 0d,
            SqlDbType.DateTime or SqlDbType.DateTime2 or SqlDbType.SmallDateTime or SqlDbType.Date => DateTime.UtcNow.Date,
            SqlDbType.NVarChar or SqlDbType.VarChar or SqlDbType.NChar or SqlDbType.Char or SqlDbType.Text or SqlDbType.NText => string.Empty,
            _ => DBNull.Value
        };
    }

    private static string QuoteIdent(string name) => "[" + name.Replace("]", "]]", StringComparison.Ordinal) + "]";

    private static object? NormalizeSqlValue(object value) =>
        value switch
        {
            DBNull => null,
            DateTime dt => DateTime.SpecifyKind(dt, DateTimeKind.Unspecified),
            byte[] bytes when bytes.Length == 16 => new Guid(bytes),
            _ => value
        };

    private static bool TryGetGuid(
        IReadOnlyDictionary<string, object?> row,
        out Guid guid,
        params string[] keys)
    {
        foreach (var key in keys)
        {
            if (!row.TryGetValue(key, out var value) || value is null)
            {
                continue;
            }

            switch (value)
            {
                case Guid g:
                    guid = g;
                    return guid != Guid.Empty;
                case string s when Guid.TryParse(s, out var parsed):
                    guid = parsed;
                    return guid != Guid.Empty;
                case byte[] bytes when bytes.Length == 16:
                    guid = new Guid(bytes);
                    return guid != Guid.Empty;
            }
        }

        guid = Guid.Empty;
        return false;
    }

    private static double? TryGetDouble(IReadOnlyDictionary<string, object?> row, string key)
    {
        if (!row.TryGetValue(key, out var value) || value is null)
        {
            return null;
        }

        return ToDouble(value);
    }

    private static DateTime? TryGetDate(IReadOnlyDictionary<string, object?> row, string key)
    {
        if (!row.TryGetValue(key, out var value) || value is null)
        {
            return null;
        }

        return value switch
        {
            DateTime dt => dt,
            string s when DateTime.TryParse(s, CultureInfo.InvariantCulture, DateTimeStyles.AssumeLocal, out var parsed) => parsed,
            _ => null
        };
    }
}
