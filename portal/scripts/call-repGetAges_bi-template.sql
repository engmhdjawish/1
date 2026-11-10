-- Confirmed Amine inventory-age report: dbo.repGetAges_bi
-- UI: تقارير المخزون → أعمار المواد (matches this procedure).
--
-- Result columns (from probe):
--   MatPtr, Price, Total, Qty, Date, Remaining, Age, MatClass, MatStore,
--   MatUnitName, BillGUID, BillType, BillNotes, BiGuid, BiNumber, Direction, ExpireDate
--
-- === مصادر التقرير (@Billtypes) — REQUIRED ===
-- Amine shows: «يجب اختيار مصادر التقرير» if none are selected.
-- Prefer selecting ALL invoice types in the left «الفواتير» list
-- (مبيعات، مرتجع مبيعات، مشتريات، مرتجع مشتريات، بضاعة أول المدة،
--  تسويات جردية إدخال/إخراج، حجز، طلبيات، …).
-- FIFO Remaining/Age needs both IN and OUT bill types; partial selection skews age.
--
-- How to get @Billtypes GUID (same idea as sourceGuid for prcGeneralLedger):
--   1) In أعمار المواد: check ALL sources under الفواتير, then Preview once.
--   2) Capture the GUID Amine passes as @Billtypes (SQL Profiler / Extended Events), OR
--   3) Look up the saved report-source row in RepSrcs after that run.
-- Do NOT leave @Billtypes = 0x0 for a real call.
--
-- UI defaults observed (أعمار المواد):
--   إلى تاريخ = as-of date | عدد الفترات = 3 | طول الفترة = 30 يوم
--   عرض تقرير تفصيلي = on | الوحدة الافتراضية | العملة = ملف الشركة
--
-- Other filters:
--   @StorePtr -> exclude scrap warehouses by selecting only sellable stores (or 0x0 = all)
--   @MatPtr / @MatGroup / @MatCondGuid -> optional material scope
--
-- NOT this report: prcTransMatAge (year AGES000 transfer), repGetAges_ce (AR),
--   repFirstInFirstOut (costing), repMatExpireDate (expiry).
-- Companion: dbo.repMatNoMove (FirstPurchase / LastSale / moveCnt).

SET NOCOUNT ON;

-- Optional: inspect report-source registry (column names vary by Amine build)
-- SELECT TOP 50 * FROM dbo.RepSrcs ORDER BY 1 DESC;
-- SELECT GUID, Name, LatinName FROM dbo.bt000 ORDER BY Number;  -- bill type cards

DECLARE
    @MatPtr         UNIQUEIDENTIFIER = 0x0,
    @MatGroup       UNIQUEIDENTIFIER = 0x0,
    @StorePtr       UNIQUEIDENTIFIER = 0x0,
    @UntilDate      DATETIME         = CAST(GETDATE() AS DATE),
    @Detailed       BIT              = 1,            -- عرض تقرير تفصيلي
    @NumOfPeriods   INT              = 3,            -- عدد الفترات (UI default)
    @PeriodLength   INT              = 30,           -- طول الفترة بالأيام
    @ShowBonus      BIT              = 0,            -- معالجة الهدايا
    @UseUnit        INT              = 4,            -- 4 = الوحدة الافتراضية
    @CurrencyPtr    UNIQUEIDENTIFIER = 0x0,          -- REPLACE: my000 GUID for company currency
    @CurrencyVal    FLOAT            = 1,
    @Billtypes      UNIQUEIDENTIFIER = 0x0,          -- REPLACE: REQUIRED — all-sources RepSrcs GUID
    @ShowEmpty      BIT              = 0,            -- عرض المواد الفارغة
    @MatCondGuid    UNIQUEIDENTIFIER = 0x0,
    @ProcessInBlill BIT              = 0,
    @DetailByClass  BIT              = 0,
    @DetailByStore  BIT              = 1;

IF @Billtypes = 0x0
BEGIN
    RAISERROR(N'@Billtypes is required. In أعمار المواد select ALL sources under الفواتير, capture the report-source GUID, then set @Billtypes.', 16, 1);
    RETURN;
END;

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
