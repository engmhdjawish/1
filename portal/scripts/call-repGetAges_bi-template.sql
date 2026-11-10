-- Confirmed Amine inventory-age report: dbo.repGetAges_bi
-- Probe of MainDb showed #ResultMatAge columns:
--   MatPtr, Price, Total, Qty, Date, Remaining, Age, MatClass, MatStore,
--   MatUnitName, BillGUID, BillType, BillNotes, BiGuid, BiNumber, Direction, ExpireDate
-- Age is stock age from bill-item receipt date (FIFO remaining qty), not expiry and not AR aging.
--
-- NOT this report:
--   prcTransMatAge / prcTransMatAccAges  -> year-file AGE transfer into AGES000 (maintenance)
--   repGetAges_ce / repAgesOfCustomerReceivables -> accounting / receivables ages
--   repFirstInFirstOut -> FIFO costing/pricing
--   FnStoresMaterialsExpireDates / repMatExpireDate -> product expiry
-- Companion for stagnant offers: dbo.repMatNoMove (FirstPurchase / LastSale / moveCnt)
--
-- Filters useful for the offer tool:
--   @StorePtr     -> pass empty GUID for all stores, or a store tree root; exclude scrap by omitting those stores
--   @Billtypes    -> bill-type list GUID (Amine report source); restrict to purchase / opening in types
--   @UntilDate    -> as-of date for Age
--   @NumOfPeriods / @PeriodLength -> aging buckets (e.g. 4 periods × 30 days)
--   @DetailByStore / @DetailByClass -> break Remaining by store / class
--
-- GUIDs below are placeholders (0x0 = all / empty in Amine style). Replace before run.
-- Requires an Amine connection context (same session patterns as other reports).

SET NOCOUNT ON;

DECLARE
    @MatPtr        UNIQUEIDENTIFIER = 0x0,          -- one material, or 0x0 = all (via group/cond)
    @MatGroup      UNIQUEIDENTIFIER = 0x0,          -- material group tree
    @StorePtr      UNIQUEIDENTIFIER = 0x0,          -- store tree; set to sellable stores only later
    @UntilDate     DATETIME         = GETDATE(),
    @Detailed      BIT              = 1,            -- line-level remaining lots
    @NumOfPeriods  INT              = 4,
    @PeriodLength  INT              = 30,           -- days per bucket
    @ShowBonus     BIT              = 0,
    @UseUnit       INT              = 4,            -- 1/2/3 = unit1..3, 4 = default unit
    @CurrencyPtr   UNIQUEIDENTIFIER = 0x0,          -- usually default currency GUID from my000
    @CurrencyVal   FLOAT            = 1,
    @Billtypes     UNIQUEIDENTIFIER = 0x0,          -- report bill-type source GUID (purchase/opening)
    @ShowEmpty     BIT              = 0,
    @MatCondGuid   UNIQUEIDENTIFIER = 0x0,
    @ProcessInBlill BIT             = 0,
    @DetailByClass BIT              = 0,
    @DetailByStore BIT              = 1;

-- Smoke call — expect result sets with Age / Remaining / MatPtr / MatStore
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

-- Optional companion: materials with little/no movement in a window
-- EXEC dbo.repMatNoMove
--     @MinMove = 0,
--     @StartDate = DATEADD(DAY, -90, GETDATE()),
--     @EndDate = GETDATE(),
--     @Src = 0x0,
--     @Store = 0x0,
--     @Gr = 0x0,
--     @Cost = 0x0,
--     @CurPtr = 0x0,
--     @ShowEmpty = 0,
--     @ShwBalanced = 1;
