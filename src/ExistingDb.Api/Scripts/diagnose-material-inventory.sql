-- Diagnose material inventory from ms000 (authoritative stock balances).
-- Replace @MatCode with the material code from the store URL / catalog.
-- Example: 76123

DECLARE @MatCode NVARCHAR(100) = N'76123';

DECLARE @MatGuid UNIQUEIDENTIFIER;
SELECT @MatGuid = GUID FROM mt000 WHERE Code = @MatCode;

SELECT
    m.Code,
    m.Name,
    m.Qty AS Mt000_TotalQty,
    ms.GUID AS MsGuid,
    ms.StoreGUID,
    st.Code AS StoreCode,
    st.Name AS StoreName,
    ms.Qty AS MsQty,
    ms.Book AS MsBook
FROM mt000 m
LEFT JOIN ms000 ms ON ms.MatGUID = m.GUID
LEFT JOIN st000 st ON st.GUID = ms.StoreGUID
WHERE m.GUID = @MatGuid
ORDER BY ISNULL(ms.Qty, 0) DESC, st.Name;

-- Scoped sum for allowed stores only (paste StoreGUIDs from access policy):
/*
DECLARE @Allowed TABLE (StoreGUID UNIQUEIDENTIFIER PRIMARY KEY);
INSERT INTO @Allowed (StoreGUID) VALUES
    ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
    ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');

SELECT
    SUM(CASE WHEN a.StoreGUID IS NOT NULL THEN ISNULL(ms.Qty, 0) ELSE 0 END) AS AllowedQty,
    SUM(CASE WHEN a.StoreGUID IS NULL THEN ISNULL(ms.Qty, 0) ELSE 0 END) AS ExcludedQty,
    SUM(ISNULL(ms.Qty, 0)) AS AllMsQty
FROM ms000 ms
LEFT JOIN @Allowed a ON a.StoreGUID = ms.StoreGUID
WHERE ms.MatGUID = @MatGuid;
*/
