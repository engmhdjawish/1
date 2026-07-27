-- Diagnose stale/wrong per-store qty for a material in Amine MainDb.
-- Replace the GUID if needed. Material 76123:
--   0af14df6-68c6-4182-9bc0-516e20978717

DECLARE @MaterialGuid uniqueidentifier = '0af14df6-68c6-4182-9bc0-516e20978717';

PRINT '=== mt000 total Qty ===';
SELECT GUID, Code, Name, Qty
FROM mt000
WHERE GUID = @MaterialGuid;

PRINT '=== vwMaterialInventory rows ===';
SELECT
    i.MaterialGuid,
    i.StoreGuid,
    s.Name AS StoreName,
    s.Code AS StoreCode,
    i.Qty AS ViewQty
FROM vwMaterialInventory i
LEFT JOIN st000 s ON s.GUID = i.StoreGuid
WHERE i.MaterialGuid = @MaterialGuid
ORDER BY i.Qty DESC;

PRINT '=== Compare totals ===';
SELECT
    (SELECT Qty FROM mt000 WHERE GUID = @MaterialGuid) AS Mt000Qty,
    (SELECT SUM(ISNULL(Qty, 0)) FROM vwMaterialInventory WHERE MaterialGuid = @MaterialGuid) AS ViewSumQty;

PRINT '=== View definition (if permitted) ===';
SELECT OBJECT_DEFINITION(OBJECT_ID('vwMaterialInventory')) AS ViewDefinition;
