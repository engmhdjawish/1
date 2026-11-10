-- Discover Amine (MainDb) routines/views that may expose material STOCK AGE (not product "age category").
-- Run in SSMS on the same database ExistingDb.Api uses (ConnectionStrings:MainDb).
-- Jawish codebase today only calls prcGeneralLedger (accounts); inventory age is not wired in the API yet.

SET NOCOUNT ON;

PRINT '=== 1) Stored procedures / functions: name heuristics (stock, mat, age, FIFO, expiry, jard, movement) ===';
SELECT
    s.name AS SchemaName,
    o.name AS ObjectName,
    o.type_desc AS ObjectType,
    o.create_date,
    o.modify_date
FROM sys.objects o
INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.type IN ('P', 'PC', 'FN', 'IF', 'TF', 'V') -- proc, scalar/table func, views
  AND (
        o.name LIKE N'%Mat%'
     OR o.name LIKE N'%Material%'
     OR o.name LIKE N'%Stock%'
     OR o.name LIKE N'%Store%'
     OR o.name LIKE N'%Invent%'
     OR o.name LIKE N'%Qty%'
     OR o.name LIKE N'%Age%'
     OR o.name LIKE N'%FIFO%'
     OR o.name LIKE N'%Fifo%'
     OR o.name LIKE N'%Expir%'
     OR o.name LIKE N'%Shelf%'
     OR o.name LIKE N'%Jard%'
     OR o.name LIKE N'%Move%'
     OR o.name LIKE N'%prc%Rep%'
     OR o.name LIKE N'%prc%Mat%'
     OR o.name LIKE N'%prc%St%'
  )
ORDER BY o.type_desc, o.name;

PRINT '=== 2) Search procedure/view DEFINITION text (may be slow on large DB) ===';
;WITH defs AS (
    SELECT
        s.name AS SchemaName,
        o.name AS ObjectName,
        o.type_desc AS ObjectType,
        m.definition
    FROM sys.sql_modules m
    INNER JOIN sys.objects o ON o.object_id = m.object_id
    INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
    WHERE o.type IN ('P', 'PC', 'FN', 'IF', 'TF', 'V')
)
SELECT SchemaName, ObjectName, ObjectType
FROM defs
WHERE definition LIKE N'%MatGUID%'
  AND (
        definition LIKE N'%FIFO%'
     OR definition LIKE N'%Age%'
     OR definition LIKE N'%عمر%'
     OR definition LIKE N'%صلاح%'
     OR definition LIKE N'%Expire%'
     OR definition LIKE N'%Expiry%'
     OR definition LIKE N'%Old%Stock%'
     OR definition LIKE N'%Remaining%'
     OR definition LIKE N'%FirstIn%'
  )
ORDER BY ObjectType, ObjectName;

PRINT '=== 3) All user procedures starting with prc (sample; filter in SSMS) ===';
SELECT
    s.name AS SchemaName,
    o.name AS ObjectName,
    o.create_date,
    o.modify_date
FROM sys.objects o
INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.type IN ('P', 'PC')
  AND o.name LIKE N'prc%'
ORDER BY o.name;

PRINT '=== 4) Report source registry (same family as prcGeneralLedger / RepSrcs) ===';
IF OBJECT_ID(N'dbo.RepSrcs', N'U') IS NOT NULL
BEGIN
    SELECT *
    FROM dbo.RepSrcs
  WHERE CAST(Name AS nvarchar(max)) LIKE N'%مخز%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%مستود%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%مادة%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%جرد%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%حرك%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%Mat%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%Stock%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%Invent%'
     OR CAST(Name AS nvarchar(max)) LIKE N'%Age%'
     OR CAST(LatinName AS nvarchar(max)) LIKE N'%Mat%'
     OR CAST(LatinName AS nvarchar(max)) LIKE N'%Stock%'
    ORDER BY Name;
END
ELSE
    PRINT 'Table RepSrcs not found — skip or check schema name.';

PRINT '=== 5) Views already used by Jawish + inventory-related views ===';
SELECT s.name AS SchemaName, v.name AS ViewName
FROM sys.views v
INNER JOIN sys.schemas s ON s.schema_id = v.schema_id
WHERE v.name LIKE N'vw%Mat%'
   OR v.name LIKE N'vw%Stock%'
   OR v.name LIKE N'vw%Store%'
   OR v.name LIKE N'vw%Invent%'
ORDER BY v.name;

PRINT '=== 6) ms000 columns (live balance — API uses Qty; check if Book or others imply age) ===';
SELECT c.name AS ColumnName, t.name AS TypeName
FROM sys.columns c
INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.ms000')
ORDER BY c.column_id;

PRINT 'Done. If nothing matches: age is likely computed inside Amine UI reports only — capture SQL with Extended Events when opening the report (see monitor-amine-stock-queries.sql).';
