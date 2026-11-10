-- Probe Amine routines that look like MATERIAL STOCK AGE (not product age-category, not expiry alone).
-- Run on MainDb after discover-inventory-age-routines.sql.
-- Paste results (parameters + first lines of definition) back for API wiring.

SET NOCOUNT ON;

PRINT '=== A) Parameters for age / FIFO / no-move candidates ===';
SELECT
    OBJECT_SCHEMA_NAME(p.object_id) AS SchemaName,
    OBJECT_NAME(p.object_id) AS ObjectName,
    p.name AS ParameterName,
    TYPE_NAME(p.user_type_id) AS TypeName,
    p.max_length,
    p.is_output
FROM sys.parameters p
WHERE OBJECT_NAME(p.object_id) IN (
        N'repGetAges_bi',
        N'repGetAges_ce',
        N'prcTransMatAge',
        N'prcTransMatAccAges',
        N'prcTransAccAge',
        N'prcDeleteItemsEditedAge',
        N'repAgesOfCustomerReceivables',
        N'repVoucherAges',
        N'repFirstInFirstOut',
        N'prcGetFirstInFirstOutPrise',
        N'prcGetFirstInFirstOutPriseQTY2',
        N'rep_FiFoGenEntry',
        N'repMatNoMove',
        N'repStockAnalyzer',
        N'repMatOverflow',
        N'repStoreTurnover',
        N'prcCallCalcInOutMtMove',
        N'prcCalcInOutMtMove',
        N'prcMatMoveReport',
        N'repMatMove',
        N'GetAllMatMoveInBills',
        N'FnStoresMaterialsExpireDates',
        N'repMatExpireDate',
        N'PrcMaterialMoveByExpireDateReport'
    )
ORDER BY ObjectName, p.parameter_id;

PRINT '=== B) Definition snippets (search Age / FIFO / عمر inside modules) ===';
SELECT
    OBJECT_SCHEMA_NAME(o.object_id) AS SchemaName,
    o.name AS ObjectName,
    o.type_desc,
    LEFT(m.definition, 4000) AS DefinitionHead
FROM sys.sql_modules m
INNER JOIN sys.objects o ON o.object_id = m.object_id
WHERE o.name IN (
        N'repGetAges_bi',
        N'repGetAges_ce',
        N'prcTransMatAge',
        N'prcTransMatAccAges',
        N'repFirstInFirstOut',
        N'repMatNoMove',
        N'repStockAnalyzer'
    )
ORDER BY o.name;

PRINT '=== C) Confirmed meanings (from MainDb probe) ===';
PRINT 'repGetAges_bi          -> STOCK AGE report on bi000; Remaining + Age; StorePtr/Billtypes filters';
PRINT 'prcTransMatAge         -> year transfer into AGES000 (NOT a report)';
PRINT 'prcTransMatAccAges     -> wrapper: AccAge and/or MatAge transfer';
PRINT 'repGetAges_ce          -> accounting entry ages (NOT inventory)';
PRINT 'repFirstInFirstOut     -> FIFO COSTING/pricing';
PRINT 'repMatNoMove           -> stagnant stock: FirstPurchase / LastSale / moveCnt';
PRINT 'FnStoresMaterialsExpireDates / repMatExpireDate -> EXPIRY date, not stock age';

PRINT '=== D) Next: smoke-call template ===';
PRINT 'See portal/scripts/call-repGetAges_bi-template.sql';
