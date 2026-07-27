-- Discover Amine tables that store per-warehouse material quantities.
-- Run on MainDb (أمين) in SSMS.
-- Material 76123 GUID:
DECLARE @MaterialGuid uniqueidentifier = '0AF14DF6-68C6-4182-9BC0-516E20978717';
DECLARE @ExpectedQty float = 1;

PRINT '=== 1) Tables/views that look like store stock (name heuristics) ===';
SELECT
    s.name AS SchemaName,
    o.name AS ObjectName,
    o.type_desc AS ObjectType
FROM sys.objects o
INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.type IN ('U', 'V') -- tables + views
  AND (
        o.name LIKE '%Store%Qty%'
     OR o.name LIKE '%Mat%Store%'
     OR o.name LIKE '%Store%Mat%'
     OR o.name LIKE '%Stock%'
     OR o.name LIKE '%Inventory%'
     OR o.name LIKE '%Balance%'
     OR o.name LIKE 'ms%'
     OR o.name LIKE 'mt%st%'
     OR o.name LIKE '%Qty%Store%'
     OR o.name LIKE 'di%'
     OR o.name LIKE 'mi%'
  )
ORDER BY o.type_desc, o.name;

PRINT '=== 2) Objects that have BOTH a material-like GUID column AND a store-like GUID column ===';
;WITH cols AS (
    SELECT
        c.object_id,
        OBJECT_SCHEMA_NAME(c.object_id) AS SchemaName,
        OBJECT_NAME(c.object_id) AS ObjectName,
        o.type_desc AS ObjectType,
        c.name AS ColumnName,
        t.name AS TypeName
    FROM sys.columns c
    INNER JOIN sys.objects o ON o.object_id = c.object_id
    INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
    WHERE o.type IN ('U', 'V')
      AND t.name IN ('uniqueidentifier', 'float', 'real', 'decimal', 'numeric', 'money', 'smallmoney', 'int', 'bigint')
)
SELECT
    m.SchemaName,
    m.ObjectName,
    m.ObjectType,
    STRING_AGG(CONVERT(nvarchar(200), m.ColumnName), ', ') WITHIN GROUP (ORDER BY m.ColumnName) AS InterestingColumns
FROM cols m
WHERE EXISTS (
        SELECT 1 FROM cols x
        WHERE x.object_id = m.object_id
          AND (
                x.ColumnName LIKE '%Mat%GUID%'
             OR x.ColumnName LIKE '%Material%GUID%'
             OR x.ColumnName IN ('MatGUID', 'MaterialGuid', 'biMatPtr', 'mtGUID')
          )
      )
  AND EXISTS (
        SELECT 1 FROM cols x
        WHERE x.object_id = m.object_id
          AND (
                x.ColumnName LIKE '%Store%GUID%'
             OR x.ColumnName LIKE '%Store%Ptr%'
             OR x.ColumnName IN ('StoreGuid', 'StoreGUID', 'buStorePtr', 'stGUID')
          )
      )
  AND EXISTS (
        SELECT 1 FROM cols x
        WHERE x.object_id = m.object_id
          AND (
                x.ColumnName LIKE '%Qty%'
             OR x.ColumnName LIKE '%Quantity%'
             OR x.ColumnName LIKE '%Balance%'
             OR x.ColumnName IN ('Qty', 'Quantity', 'Remain', 'RestQty')
          )
      )
GROUP BY m.SchemaName, m.ObjectName, m.ObjectType
ORDER BY m.ObjectType, m.ObjectName;

PRINT '=== 3) Known candidates: probe for material 76123 with Qty near 1 ===';
-- Adjust/extend this list after step 2 results.
-- Example pattern for each candidate object:
-- SELECT TOP 50 * FROM <table> WHERE <MatCol> = @MaterialGuid;

-- mt000 total
SELECT 'mt000' AS Src, GUID AS MaterialGuid, CAST(NULL AS uniqueidentifier) AS StoreGuid, Qty
FROM mt000
WHERE GUID = @MaterialGuid;

-- current API source
SELECT 'vwMaterialInventory' AS Src, MaterialGuid, StoreGuid, Qty
FROM vwMaterialInventory
WHERE MaterialGuid = @MaterialGuid;

PRINT '=== 4) Find rows for this material where Qty = 1 (scan candidate columns via dynamic SQL) ===';
-- Builds probes for user tables/views that contain Mat+Store+Qty-like columns.
DECLARE @sql nvarchar(max) = N'';

;WITH targets AS (
    SELECT DISTINCT
        OBJECT_SCHEMA_NAME(o.object_id) AS SchemaName,
        o.name AS ObjectName,
        o.object_id
    FROM sys.objects o
    WHERE o.type IN ('U', 'V')
      AND EXISTS (
            SELECT 1 FROM sys.columns c
            WHERE c.object_id = o.object_id
              AND (c.name LIKE '%Mat%GUID%' OR c.name LIKE '%Material%GUID%' OR c.name IN ('MatGUID','MaterialGuid','biMatPtr'))
        )
      AND EXISTS (
            SELECT 1 FROM sys.columns c
            WHERE c.object_id = o.object_id
              AND (c.name LIKE '%Store%GUID%' OR c.name LIKE '%Store%Ptr%' OR c.name IN ('StoreGuid','StoreGUID','buStorePtr','stGUID'))
        )
      AND EXISTS (
            SELECT 1 FROM sys.columns c
            INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
            WHERE c.object_id = o.object_id
              AND (c.name LIKE '%Qty%' OR c.name LIKE '%Quantity%' OR c.name LIKE '%Balance%')
              AND t.name IN ('float','real','decimal','numeric','money','smallmoney','int','bigint')
        )
)
SELECT @sql = @sql +
N'SELECT TOP 20 ''' + SchemaName + '.' + ObjectName + ''' AS Src, * FROM ' +
QUOTENAME(SchemaName) + '.' + QUOTENAME(ObjectName) +
N' WHERE ' +
(
    SELECT TOP 1 QUOTENAME(c.name)
    FROM sys.columns c
    WHERE c.object_id = targets.object_id
      AND (c.name LIKE '%Mat%GUID%' OR c.name LIKE '%Material%GUID%' OR c.name IN ('MatGUID','MaterialGuid','biMatPtr'))
    ORDER BY CASE WHEN c.name IN ('MatGUID','MaterialGuid','biMatPtr') THEN 0 ELSE 1 END, c.column_id
) +
N' = @MaterialGuid;' + CHAR(10)
FROM targets;

PRINT @sql; -- review before exec if desired
EXEC sp_executesql @sql, N'@MaterialGuid uniqueidentifier', @MaterialGuid=@MaterialGuid;

PRINT '=== 5) Optional: which store is ستوك كجون? ===';
SELECT GUID, Number, Code, Name, IsActive
FROM st000
WHERE Name LIKE N'%ستوك%' OR Name LIKE N'%كجون%' OR Name LIKE N'%مشكل%'
ORDER BY Number;
