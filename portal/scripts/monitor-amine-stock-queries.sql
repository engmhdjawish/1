-- Monitor Amine while opening material card / "المعلومات" for 76123.
-- Run this BEFORE opening the window, then open Amine material info, then query again.

-- A) Start a lightweight Extended Events session (SQL Server 2016+)
-- Requires VIEW SERVER STATE / ALTER ANY EVENT SESSION.

IF EXISTS (SELECT 1 FROM sys.server_event_sessions WHERE name = 'AmineStockSpy')
BEGIN
    ALTER EVENT SESSION AmineStockSpy ON SERVER STATE = STOP;
    DROP EVENT SESSION AmineStockSpy ON SERVER;
END
GO

CREATE EVENT SESSION AmineStockSpy ON SERVER
ADD EVENT sqlserver.sql_batch_completed(
    ACTION(sqlserver.sql_text, sqlserver.database_name, sqlserver.client_app_name, sqlserver.username)
    WHERE sqlserver.database_name = N'REPLACE_WITH_MAINDB_NAME'
      AND (
            sqlserver.sql_text LIKE N'%0AF14DF6-68C6-4182-9BC0-516E20978717%'
         OR sqlserver.sql_text LIKE N'%76123%'
         OR sqlserver.sql_text LIKE N'%st000%'
         OR sqlserver.sql_text LIKE N'%Qty%'
      )
),
ADD EVENT sqlserver.rpc_completed(
    ACTION(sqlserver.sql_text, sqlserver.database_name, sqlserver.client_app_name, sqlserver.username)
    WHERE sqlserver.database_name = N'REPLACE_WITH_MAINDB_NAME'
)
ADD TARGET package0.ring_buffer
WITH (MAX_MEMORY = 20 MB, STARTUP_STATE = OFF);
GO

ALTER EVENT SESSION AmineStockSpy ON SERVER STATE = START;
GO

/*
B) Now in Amine:
   1) Open material 76123
   2) Open حركة مادة / معلومات المستودعات
C) Then run the capture query below.
*/

DECLARE @xml xml =
(
    SELECT CAST(target_data AS xml)
    FROM sys.dm_xe_session_targets t
    INNER JOIN sys.dm_xe_sessions s ON s.address = t.event_session_address
    WHERE s.name = 'AmineStockSpy'
      AND t.target_name = 'ring_buffer'
);

SELECT
    DATEADD(hour, DATEDIFF(hour, GETUTCDATE(), GETDATE()),
        x.value('(@timestamp)[1]', 'datetime2')) AS EventTime,
    x.value('(action[@name="client_app_name"]/value)[1]', 'nvarchar(200)') AS AppName,
    x.value('(data[@name="statement"]/value)[1]', 'nvarchar(max)') AS StatementText,
    x.value('(data[@name="batch_text"]/value)[1]', 'nvarchar(max)') AS BatchText,
    x.value('(action[@name="sql_text"]/value)[1]', 'nvarchar(max)') AS SqlText
FROM @xml.nodes('//event') e(x)
ORDER BY EventTime DESC;

-- Stop when done:
-- ALTER EVENT SESSION AmineStockSpy ON SERVER STATE = STOP;
-- DROP EVENT SESSION AmineStockSpy ON SERVER;
