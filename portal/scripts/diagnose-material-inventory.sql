-- Diagnose material inventory from ms000 (authoritative stock balances).
-- Material 76123 example GUID:
--   0af14df6-68c6-4182-9bc0-516e20978717

DECLARE @MaterialGuid uniqueidentifier = '0af14df6-68c6-4182-9bc0-516e20978717';

PRINT '=== mt000 total Qty ===';
SELECT GUID, Code, Name, Qty
FROM mt000
WHERE GUID = @MaterialGuid;

PRINT '=== ms000 live balances (API source) ===';
SELECT
    ms.GUID AS MsGuid,
    ms.MatGUID AS MaterialGuid,
    ms.StoreGUID AS StoreGuid,
    s.Name AS StoreName,
    s.Code AS StoreCode,
    ms.Qty AS MsQty,
    ms.Book AS MsBook
FROM ms000 ms
LEFT JOIN st000 s ON s.GUID = ms.StoreGUID
WHERE ms.MatGUID = @MaterialGuid
ORDER BY ISNULL(ms.Qty, 0) DESC, s.Name;

PRINT '=== Compare totals ===';
SELECT
    (SELECT Qty FROM mt000 WHERE GUID = @MaterialGuid) AS Mt000Qty,
    (SELECT SUM(ISNULL(Qty, 0)) FROM ms000 WHERE MatGUID = @MaterialGuid) AS Ms000SumQty;

PRINT '=== Legacy bill view (do not use for policy) ===';
SELECT
    i.MaterialGuid,
    i.StoreGuid,
    s.Name AS StoreName,
    i.Qty AS ViewQty
FROM vwMaterialInventory i
LEFT JOIN st000 s ON s.GUID = i.StoreGuid
WHERE i.MaterialGuid = @MaterialGuid
ORDER BY i.Qty DESC;
