-- تقرير جاهز: أعمار المواد (dbo.repGetAges_bi)
-- شغّله على MainDb في SSMS (نفس قاعدة ConnectionStrings:MainDb).
--
-- أداة الموقع (قرار تصريف الراكد + صور + توليد عرض):
--   /dashboard/stagnant-inventory.php  → API GET /api/inventory/ages
--
-- من وين الأسماء؟
--   العملات          → جدول my000  (Code / Name / LatinName)
--   مصادر التقرير    → جدول bt000  (نفس قائمة «الفواتير» في شاشة أعمار المواد)
--   GUID المصادر     → يُبنى هنا تلقائياً في RepSrcs ويضمّ كل أنواع bt000
--
-- النتيجة المتوقعة: Remaining, Age, MatPtr, Date, MatStore, …

SET NOCOUNT ON;
SET XACT_ABORT ON;

/* ========== 1) أسماء العملات ========== */
PRINT N'=== العملات (my000) — اختر GUID إن أردت غير الافتراضي ===';
SELECT
    [GUID] AS CurrencyGuid,
    [Code],
    [Name],
    [LatinName],
    [CurrencyVal]
FROM dbo.[my000]
ORDER BY [CurrencyVal], [Name];

/* ========== 2) أسماء مصادر التقرير = أنواع الفواتير ========== */
PRINT N'=== مصادر التقرير / الفواتير (bt000) — هذه هي الأسماء في شاشة أعمار المواد ===';
SELECT
    [GUID] AS BillTypeGuid,
    [Number],
    [Name],
    [LatinName],
    [Type],
    [BillType],
    [SortNum]
FROM dbo.[bt000]
ORDER BY [Number], [Name];

/* ========== 3) اختيار العملة الافتراضية (عادة CurrencyVal = 1) ========== */
DECLARE @CurrencyPtr UNIQUEIDENTIFIER;
DECLARE @CurrencyVal FLOAT;
DECLARE @CurrencyName NVARCHAR(256);

SELECT TOP (1)
    @CurrencyPtr  = [GUID],
    @CurrencyVal  = [CurrencyVal],
    @CurrencyName = [Name]
FROM dbo.[my000]
WHERE [CurrencyVal] = 1
ORDER BY [Number];

IF @CurrencyPtr IS NULL
BEGIN
    SELECT TOP (1)
        @CurrencyPtr  = [GUID],
        @CurrencyVal  = [CurrencyVal],
        @CurrencyName = [Name]
    FROM dbo.[my000]
    ORDER BY [Number];
END;

IF @CurrencyPtr IS NULL
BEGIN
    RAISERROR(N'لا توجد عملة في my000.', 16, 1);
    RETURN;
END;

PRINT N'العملة المستخدمة: ' + ISNULL(@CurrencyName, N'?') + N' | GUID=' + CONVERT(NVARCHAR(36), @CurrencyPtr)
    + N' | Val=' + CONVERT(NVARCHAR(32), @CurrencyVal);

/* ========== 4) بناء مصدر تقرير يضمّ كل أنواع الفواتير ========== */
IF OBJECT_ID(N'dbo.RepSrcs', N'U') IS NULL
BEGIN
    RAISERROR(N'جدول RepSrcs غير موجود — لا يمكن بناء @Billtypes تلقائياً.', 16, 1);
    RETURN;
END;

DECLARE @Billtypes UNIQUEIDENTIFIER = NEWID();
DECLARE @SrcCol SYSNAME;
DECLARE @TypeCol SYSNAME;
DECLARE @TblCol SYSNAME;
DECLARE @IdTblInt INT;
DECLARE @IdTblGuid UNIQUEIDENTIFIER;
DECLARE @TblType SYSNAME;
DECLARE @Sql NVARCHAR(MAX);
DECLARE @Inserted INT = 0;

SELECT @SrcCol = c.name
FROM sys.columns c
WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs')
  AND c.name IN (N'IdSrc', N'GUID', N'Guid', N'SrcGuid', N'SourceGuid', N'IdParent', N'ParentGUID')
ORDER BY CASE c.name
    WHEN N'IdSrc' THEN 1
    WHEN N'GUID' THEN 2
    WHEN N'Guid' THEN 3
    WHEN N'SrcGuid' THEN 4
    WHEN N'SourceGuid' THEN 5
    ELSE 9
END;

SELECT @TypeCol = c.name
FROM sys.columns c
WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs')
  AND c.name IN (N'IdType', N'TypeGuid', N'TypeGUID', N'BillTypeGuid', N'BtGuid', N'GUIDType')
ORDER BY CASE c.name
    WHEN N'IdType' THEN 1
    WHEN N'TypeGuid' THEN 2
    WHEN N'TypeGUID' THEN 3
    ELSE 9
END;

SELECT @TblCol = c.name
FROM sys.columns c
WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs')
  AND c.name IN (N'IdTbl', N'Tbl', N'TableId', N'TblId')
ORDER BY CASE c.name
    WHEN N'IdTbl' THEN 1
    ELSE 9
END;

PRINT N'RepSrcs columns:';
SELECT c.name AS ColumnName, t.name AS TypeName, c.is_nullable
FROM sys.columns c
INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs')
ORDER BY c.column_id;

IF @SrcCol IS NULL OR @TypeCol IS NULL OR @SrcCol = @TypeCol
BEGIN
    -- بعض نسخ الأمين: عمود واحد لنوع الفاتورة + المصدر يُمرَّر بطريقة أخرى.
    -- نعرض البنية ونتوقف لضبط يدوي إن لزم.
    RAISERROR(N'تعذّر اكتشاف أعمدة IdSrc/IdType في RepSrcs. انسخ نتيجة أعمدة RepSrcs أعلاه.', 16, 1);
    RETURN;
END;

-- IdTbl إلزامي في AmnDb2026: قد يكون INT أو UNIQUEIDENTIFIER حسب إصدار الأمين.
IF @TblCol IS NOT NULL
BEGIN
    SELECT @TblType = t.name
    FROM sys.columns c
    INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
    WHERE c.object_id = OBJECT_ID(N'dbo.RepSrcs') AND c.name = @TblCol;

    IF @TblType = N'uniqueidentifier'
    BEGIN
        SET @Sql = N'
SELECT TOP (1) @pIdTbl = r.' + QUOTENAME(@TblCol) + N'
FROM dbo.RepSrcs AS r
INNER JOIN dbo.[bt000] AS bt ON bt.[GUID] = r.' + QUOTENAME(@TypeCol) + N'
GROUP BY r.' + QUOTENAME(@TblCol) + N'
ORDER BY COUNT(*) DESC;';
        EXEC sys.sp_executesql @Sql, N'@pIdTbl uniqueidentifier OUTPUT', @pIdTbl = @IdTblGuid OUTPUT;
        IF @IdTblGuid IS NULL
        BEGIN
            RAISERROR(N'RepSrcs.IdTbl من نوع uniqueidentifier ولا توجد عيّنة مع bt000. افتح أعمار المواد في الأمين مع كل مصادر الفواتير مرة ثم أعد السكربت.', 16, 1);
            RETURN;
        END;
        PRINT N'RepSrcs.IdTbl (GUID) للفواتير = ' + CONVERT(NVARCHAR(36), @IdTblGuid);
    END
    ELSE
    BEGIN
        SET @Sql = N'
SELECT TOP (1) @pIdTbl = r.' + QUOTENAME(@TblCol) + N'
FROM dbo.RepSrcs AS r
INNER JOIN dbo.[bt000] AS bt ON bt.[GUID] = r.' + QUOTENAME(@TypeCol) + N'
GROUP BY r.' + QUOTENAME(@TblCol) + N'
ORDER BY COUNT(*) DESC;';
        EXEC sys.sp_executesql @Sql, N'@pIdTbl INT OUTPUT', @pIdTbl = @IdTblInt OUTPUT;
        IF @IdTblInt IS NULL SET @IdTblInt = 2;
        PRINT N'RepSrcs.IdTbl (INT) للفواتير = ' + CONVERT(NVARCHAR(12), @IdTblInt);
    END
END;

BEGIN TRY
    BEGIN TRAN;

    IF @TblCol IS NOT NULL AND @TblType = N'uniqueidentifier'
    BEGIN
        SET @Sql = N'
INSERT INTO dbo.RepSrcs (' + QUOTENAME(@SrcCol) + N', ' + QUOTENAME(@TypeCol) + N', ' + QUOTENAME(@TblCol) + N')
SELECT @pSrc, bt.[GUID], @pTbl
FROM dbo.[bt000] AS bt;';
        EXEC sys.sp_executesql @Sql, N'@pSrc uniqueidentifier, @pTbl uniqueidentifier', @pSrc = @Billtypes, @pTbl = @IdTblGuid;
    END
    ELSE IF @TblCol IS NOT NULL
    BEGIN
        SET @Sql = N'
INSERT INTO dbo.RepSrcs (' + QUOTENAME(@SrcCol) + N', ' + QUOTENAME(@TypeCol) + N', ' + QUOTENAME(@TblCol) + N')
SELECT @pSrc, bt.[GUID], @pTbl
FROM dbo.[bt000] AS bt;';
        EXEC sys.sp_executesql @Sql, N'@pSrc uniqueidentifier, @pTbl int', @pSrc = @Billtypes, @pTbl = @IdTblInt;
    END
    ELSE
    BEGIN
        SET @Sql = N'
INSERT INTO dbo.RepSrcs (' + QUOTENAME(@SrcCol) + N', ' + QUOTENAME(@TypeCol) + N')
SELECT @pSrc, bt.[GUID]
FROM dbo.[bt000] AS bt;';
        EXEC sys.sp_executesql @Sql, N'@pSrc uniqueidentifier', @pSrc = @Billtypes;
    END
    SET @Inserted = @@ROWCOUNT;

    IF @Inserted = 0
    BEGIN
        RAISERROR(N'لم يُدرج أي نوع فاتورة في RepSrcs — bt000 فارغ؟', 16, 1);
    END;

    PRINT N'مصادر التقرير: ALL bt000 → ' + CONVERT(NVARCHAR(10), @Inserted)
        + N' نوع | @Billtypes=' + CONVERT(NVARCHAR(36), @Billtypes);

    /* سياق مستخدم أمين (مطلوب غالباً لأمان التقارير) */
    DECLARE @AdminGuid UNIQUEIDENTIFIER =
        (SELECT TOP (1) [GUID] FROM dbo.[us000] WHERE [bAdmin] = 1 ORDER BY [Number]);
    IF @AdminGuid IS NOT NULL AND OBJECT_ID(N'dbo.prcConnections_add', N'P') IS NOT NULL
    BEGIN
        EXEC dbo.prcConnections_add @AdminGuid;
        PRINT N'تم prcConnections_add لمستخدم Admin.';
    END;

    /* ========== 5) تشغيل أعمار المواد ========== */
    DECLARE
        @MatPtr         UNIQUEIDENTIFIER = 0x0,
        @MatGroup       UNIQUEIDENTIFIER = 0x0,
        @StorePtr       UNIQUEIDENTIFIER = 0x0,   -- لاحقاً: استبعد مستودعات الهالك
        @UntilDate      DATETIME         = CAST(GETDATE() AS DATE),
        @Detailed       BIT              = 1,
        @NumOfPeriods   INT              = 3,
        @PeriodLength   INT              = 30,
        @ShowBonus      BIT              = 0,
        @UseUnit        INT              = 4,
        @ShowEmpty      BIT              = 0,
        @MatCondGuid    UNIQUEIDENTIFIER = 0x0,
        @ProcessInBlill BIT              = 0,
        @DetailByClass  BIT              = 0,
        @DetailByStore  BIT              = 1;

    PRINT N'=== تشغيل dbo.repGetAges_bi ===';
    EXEC dbo.repGetAges_bi
        @MatPtr         = @MatPtr,
        @MatGroup       = @MatGroup,
        @StorePtr       = @StorePtr,
        @UntilDate      = @UntilDate,
        @Detailed       = @Detailed,
        @NumOfPeriods   = @NumOfPeriods,
        @PeriodLength   = @PeriodLength,
        @ShowBonus      = @ShowBonus,
        @UseUnit        = @UseUnit,
        @CurrencyPtr    = @CurrencyPtr,
        @CurrencyVal    = @CurrencyVal,
        @Billtypes      = @Billtypes,
        @ShowEmpty      = @ShowEmpty,
        @MatCondGuid    = @MatCondGuid,
        @ProcessInBlill = @ProcessInBlill,
        @DetailByClass  = @DetailByClass,
        @DetailByStore  = @DetailByStore;

    /* تنظيف صفوف المصدر المؤقت */
    SET @Sql = N'DELETE FROM dbo.RepSrcs WHERE ' + QUOTENAME(@SrcCol) + N' = @pSrc;';
    EXEC sys.sp_executesql @Sql, N'@pSrc uniqueidentifier', @pSrc = @Billtypes;

    COMMIT TRAN;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRAN;
    DECLARE @Err NVARCHAR(4000) = ERROR_MESSAGE();
    RAISERROR(N'فشل تشغيل أعمار المواد: %s', 16, 1, @Err);
END CATCH;
