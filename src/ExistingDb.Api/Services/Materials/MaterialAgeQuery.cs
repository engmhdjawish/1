using System.Data;
using System.Globalization;
using System.Text;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using ExistingDb.Api.Services.Search;
using Microsoft.Data.SqlClient;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Materials;

public sealed class MaterialAgeQuery(MainDbContext mainDbContext)
{
    private const int CommandTimeoutSeconds = 180;
    private const int MaterialBatchSize = 200;

    public sealed class Request
    {
        /// <summary>Null means use CAST(GETDATE() AS date) on the MainDb connection.</summary>
        public DateOnly? AsOf { get; init; }
        public required string AgeMode { get; init; }
        public IReadOnlyList<Guid> StoreGuids { get; init; } = [];
        public IReadOnlyList<Guid> BillTypeGuids { get; init; } = [];
        public bool SkipEmpty { get; init; } = true;
        public bool SkipZeroBalance { get; init; }
        public IReadOnlyList<string> MaterialTypes { get; init; } = [];
        public IReadOnlyList<Guid> GroupGuids { get; init; } = [];
        public IReadOnlyList<string> Companies { get; init; } = [];
        public string? Keyword { get; init; }
        public string? Search { get; init; }
        public int? MinAgeDays { get; init; }
        public int? MaxAgeDays { get; init; }
        /// <summary>number:asc (default), age:asc|desc, qty:asc|desc, name:asc|desc, code:asc|desc, price:asc|desc, purchase:asc|desc</summary>
        public string Sort { get; init; } = "number:asc";
        public int Page { get; init; } = 1;
        public int PageSize { get; init; } = 100;
    }

    public sealed class ValidationError(string Message, Guid? StoreGuid = null, Guid? BillTypeGuid = null)
    {
        public string Message { get; } = Message;
        public Guid? StoreGuid { get; } = StoreGuid;
        public Guid? BillTypeGuid { get; } = BillTypeGuid;
    }

    public async Task<(MaterialAgeResponse? Response, ValidationError? Error)> ExecuteAsync(
        Request request,
        CancellationToken cancellationToken)
    {
        var ageMode = request.AgeMode.Trim();
        if (ageMode is not ("oldest" or "weightedAverage" or "layers"))
        {
            return (null, new ValidationError("ageMode must be oldest, weightedAverage, or layers."));
        }

        if (request.MinAgeDays is < 0
            || request.MaxAgeDays is < 0
            || (request.MinAgeDays is int min && request.MaxAgeDays is int max && min > max))
        {
            return (null, new ValidationError("minAgeDays must be between 0 and maxAgeDays."));
        }

        var storeCheck = await ValidateStoresAsync(request.StoreGuids, cancellationToken);
        if (storeCheck is not null)
        {
            return (null, storeCheck);
        }

        var connection = mainDbContext.Database.GetDbConnection();
        var sqlConnection = connection as SqlConnection
            ?? throw new InvalidOperationException("MainDb provider must be SQL Server.");

        var shouldClose = sqlConnection.State != ConnectionState.Open;
        if (shouldClose)
        {
            await sqlConnection.OpenAsync(cancellationToken);
        }

        try
        {
            var schemaError = await ValidateRequiredColumnsAsync(sqlConnection, cancellationToken);
            if (schemaError is not null)
            {
                return (null, new ValidationError(schemaError));
            }

            var billTypes = await LoadBillTypesAsync(sqlConnection, cancellationToken);
            var stockMoving = billTypes.Where(t => t.MovesStock).Select(t => t.BillTypeGuid).ToHashSet();
            IReadOnlyList<Guid> effectiveBillTypes;
            if (request.BillTypeGuids.Count == 0)
            {
                effectiveBillTypes = stockMoving.ToArray();
            }
            else
            {
                foreach (var guid in request.BillTypeGuids)
                {
                    var row = billTypes.FirstOrDefault(t => t.BillTypeGuid == guid);
                    if (row is null)
                    {
                        return (null, new ValidationError("Unknown bill type.", BillTypeGuid: guid));
                    }

                    if (!row.MovesStock)
                    {
                        return (null, new ValidationError("Bill type does not move stock.", BillTypeGuid: guid));
                    }
                }

                effectiveBillTypes = request.BillTypeGuids;
            }

            if (effectiveBillTypes.Count == 0)
            {
                return (BuildEmptyResponse(request, ageMode, DateOnly.FromDateTime(DateTime.Today), effectiveBillTypes), null);
            }

            var asOf = request.AsOf ?? await ReadSqlCalendarDateAsync(sqlConnection, cancellationToken);
            var storeMeta = await LoadStoreMetaAsync(sqlConnection, cancellationToken);

            // Prefer ms000 candidates so we never pull the entire bi000 history into memory.
            Guid[] candidateMaterialGuids;
            Dictionary<(Guid Mat, Guid Store), double> ms000;
            if (request.SkipEmpty)
            {
                ms000 = await LoadMs000Async(
                    sqlConnection,
                    request.StoreGuids,
                    materialGuids: null,
                    skipZeroQty: request.SkipZeroBalance,
                    cancellationToken);
                candidateMaterialGuids = ms000.Keys.Select(k => k.Mat).Distinct().ToArray();
            }
            else
            {
                var catalogAll = await LoadCatalogMaterialsAsync(
                    sqlConnection,
                    request,
                    restrictToMaterialGuids: null,
                    cancellationToken);
                candidateMaterialGuids = catalogAll.Select(m => m.MaterialGuid).ToArray();
                ms000 = await LoadMs000Async(
                    sqlConnection,
                    request.StoreGuids,
                    candidateMaterialGuids,
                    skipZeroQty: false,
                    cancellationToken);
            }

            if (candidateMaterialGuids.Length == 0)
            {
                return (BuildEmptyResponse(request, ageMode, asOf, effectiveBillTypes), null);
            }

            // Apply catalog filters to candidates (keyword/type/group/company).
            var catalogMaterials = await LoadCatalogMaterialsAsync(
                sqlConnection,
                request,
                candidateMaterialGuids,
                cancellationToken);
            var catalogByGuid = catalogMaterials
                .GroupBy(m => m.MaterialGuid)
                .ToDictionary(g => g.Key, g => g.First());
            candidateMaterialGuids = candidateMaterialGuids.Where(catalogByGuid.ContainsKey).ToArray();
            if (candidateMaterialGuids.Length == 0)
            {
                return (BuildEmptyResponse(request, ageMode, asOf, effectiveBillTypes), null);
            }

            var rows = new List<MaterialAgeRowResponse>();
            for (var offset = 0; offset < candidateMaterialGuids.Length; offset += MaterialBatchSize)
            {
                var batch = candidateMaterialGuids.Skip(offset).Take(MaterialBatchSize).ToArray();
                var lines = await LoadMovementLinesAsync(
                    sqlConnection,
                    request,
                    asOf,
                    effectiveBillTypes,
                    batch,
                    cancellationToken);

                var fifo = MaterialAgeCalculator.Compute(lines, asOf);
                var fifoByMaterial = fifo.GroupBy(r => r.MaterialGuid)
                    .ToDictionary(g => g.Key, g => g.ToList());

                foreach (var materialGuid in batch)
                {
                    if (!catalogByGuid.TryGetValue(materialGuid, out var catalog))
                    {
                        continue;
                    }

                    var storeResults = fifoByMaterial.GetValueOrDefault(materialGuid) ?? [];
                    var allLayers = storeResults.SelectMany(s => s.Layers).ToList();
                    var net = storeResults.Sum(s => s.NetQuantity);

                    // Materials with ms000 but no posted history still appear when skipEmpty.
                    if (storeResults.Count == 0 && request.SkipEmpty)
                    {
                        var msStores = ms000.Keys.Where(k => k.Mat == materialGuid).Select(k => k.Store).ToList();
                        if (msStores.Count == 0)
                        {
                            continue;
                        }
                    }

                    if (request.SkipZeroBalance && MaterialAgeCalculator.IsZero(net))
                    {
                        var msNet = ms000.Where(kv => kv.Key.Mat == materialGuid).Sum(kv => kv.Value);
                        if (MaterialAgeCalculator.IsZero(msNet) && MaterialAgeCalculator.IsZero(net))
                        {
                            continue;
                        }
                    }

                    var modeLayers = allLayers;
                    if (ageMode == "layers" && (request.MinAgeDays is not null || request.MaxAgeDays is not null))
                    {
                        modeLayers = allLayers
                            .Where(layer => InAgeBand(layer.AgeDays, request.MinAgeDays, request.MaxAgeDays))
                            .ToList();
                        if (modeLayers.Count == 0)
                        {
                            continue;
                        }
                    }

                    int? oldestAge = MaterialAgeCalculator.OldestAgeDays(modeLayers, asOf);
                    double? weighted = MaterialAgeCalculator.WeightedAgeDays(modeLayers);
                    DateOnly? oldestDate = MaterialAgeCalculator.OldestDate(modeLayers);

                    if (ageMode is "oldest" or "weightedAverage"
                        && (request.MinAgeDays is not null || request.MaxAgeDays is not null))
                    {
                        double? bandValue = ageMode == "oldest" ? oldestAge : weighted;
                        if (bandValue is null || !InAgeBand(bandValue.Value, request.MinAgeDays, request.MaxAgeDays))
                        {
                            continue;
                        }
                    }

                    var storeBalances = BuildStoreBalances(
                        materialGuid,
                        storeResults,
                        modeLayers,
                        ageMode,
                        asOf,
                        request,
                        ms000,
                        storeMeta);

                    double remainingQuantity;
                    if (ageMode == "layers" && (request.MinAgeDays is not null || request.MaxAgeDays is not null))
                    {
                        remainingQuantity = modeLayers.Sum(l => l.RemainingQuantity);
                    }
                    else if (storeResults.Count > 0)
                    {
                        remainingQuantity = net;
                    }
                    else
                    {
                        remainingQuantity = ms000.Where(kv => kv.Key.Mat == materialGuid).Sum(kv => kv.Value);
                    }

                    IReadOnlyList<MaterialAgeLayerResponse>? layerPayload = null;
                    if (ageMode == "layers")
                    {
                        layerPayload = modeLayers
                            .OrderBy(l => storeMeta.GetValueOrDefault(l.StoreGuid)?.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase)
                            .ThenBy(l => l.BillDate)
                            .ThenBy(l => l.BillNumber ?? int.MaxValue)
                            .ThenBy(l => l.LineNumber ?? int.MaxValue)
                            .ThenBy(l => l.LineGuid)
                            .Select(l => new MaterialAgeLayerResponse(
                                l.StoreGuid,
                                l.LineGuid,
                                l.BillGuid,
                                l.BillNumber,
                                l.BillDate,
                                l.LineNumber,
                                l.BillTypeGuid,
                                l.BillTypeName,
                                l.InboundQuantity,
                                l.RemainingQuantity,
                                l.AgeDays))
                            .ToArray();
                    }

                    rows.Add(new MaterialAgeRowResponse(
                        catalog.MaterialGuid,
                        catalog.Number,
                        catalog.Code,
                        catalog.Name,
                        catalog.LatinName,
                        catalog.Company,
                        catalog.GroupGuid,
                        catalog.GroupCode,
                        catalog.GroupName,
                        catalog.MaterialType,
                        catalog.Unit,
                        remainingQuantity,
                        ageMode == "oldest" ? oldestDate : null,
                        ageMode == "oldest" ? oldestAge : null,
                        ageMode == "weightedAverage" ? weighted : null,
                        storeBalances,
                        layerPayload,
                        catalog.PictureGuid,
                        catalog.EndUser,
                        catalog.Half,
                        catalog.Whole,
                        catalog.LastPrice,
                        catalog.AvgPrice,
                        catalog.CurrencyVal));
                }
            }

            var ordered = SortRows(rows, request.Sort, ageMode);

            var page = Math.Max(1, request.Page);
            var pageSize = Math.Clamp(request.PageSize, 1, 500);
            var totalCount = ordered.Count;
            var pageItems = ordered.Skip((page - 1) * pageSize).Take(pageSize).ToList();

            var storeScope = request.StoreGuids.Count switch
            {
                0 => "all",
                1 => "one",
                _ => "several"
            };

            return (new MaterialAgeResponse(
                asOf.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
                ageMode,
                storeScope,
                request.SkipEmpty,
                request.SkipZeroBalance,
                request.StoreGuids,
                effectiveBillTypes,
                page,
                pageSize,
                totalCount,
                pageItems), null);
        }
        finally
        {
            if (shouldClose)
            {
                await sqlConnection.CloseAsync();
            }
        }
    }

    public async Task<MaterialAgeBillTypeListResponse> ListBillTypesAsync(CancellationToken cancellationToken)
    {
        var connection = mainDbContext.Database.GetDbConnection();
        var sqlConnection = connection as SqlConnection
            ?? throw new InvalidOperationException("MainDb provider must be SQL Server.");
        var shouldClose = sqlConnection.State != ConnectionState.Open;
        if (shouldClose)
        {
            await sqlConnection.OpenAsync(cancellationToken);
        }

        try
        {
            var schemaError = await ValidateRequiredColumnsAsync(sqlConnection, cancellationToken);
            if (schemaError is not null)
            {
                throw new InvalidOperationException(schemaError);
            }

            var types = await LoadBillTypesAsync(sqlConnection, cancellationToken);
            return new MaterialAgeBillTypeListResponse(types);
        }
        finally
        {
            if (shouldClose)
            {
                await sqlConnection.CloseAsync();
            }
        }
    }

    private static MaterialAgeResponse BuildEmptyResponse(
        Request request,
        string ageMode,
        DateOnly asOf,
        IReadOnlyList<Guid> effectiveBillTypes)
    {
        var page = Math.Max(1, request.Page);
        var pageSize = Math.Clamp(request.PageSize, 1, 500);
        var storeScope = request.StoreGuids.Count switch
        {
            0 => "all",
            1 => "one",
            _ => "several"
        };
        return new MaterialAgeResponse(
            asOf.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
            ageMode,
            storeScope,
            request.SkipEmpty,
            request.SkipZeroBalance,
            request.StoreGuids,
            effectiveBillTypes,
            page,
            pageSize,
            0,
            []);
    }

    private static bool InAgeBand(double ageDays, int? minAgeDays, int? maxAgeDays)
    {
        if (minAgeDays is int min && ageDays < min)
        {
            return false;
        }

        if (maxAgeDays is int max && ageDays > max)
        {
            return false;
        }

        return true;
    }

    private static List<MaterialAgeRowResponse> SortRows(
        List<MaterialAgeRowResponse> rows,
        string sort,
        string ageMode)
    {
        var key = string.IsNullOrWhiteSpace(sort) ? "number:asc" : sort.Trim().ToLowerInvariant();
        IEnumerable<MaterialAgeRowResponse> ordered = key switch
        {
            "age:desc" => rows
                .OrderByDescending(r => AgeSortValue(r, ageMode) ?? double.MinValue)
                .ThenBy(r => r.Number ?? int.MaxValue)
                .ThenBy(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase),
            "age:asc" => rows
                .OrderBy(r => AgeSortValue(r, ageMode) ?? double.MaxValue)
                .ThenBy(r => r.Number ?? int.MaxValue)
                .ThenBy(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase),
            "qty:desc" => rows
                .OrderByDescending(r => r.RemainingQuantity)
                .ThenBy(r => r.Number ?? int.MaxValue),
            "qty:asc" => rows
                .OrderBy(r => r.RemainingQuantity)
                .ThenBy(r => r.Number ?? int.MaxValue),
            "name:desc" => rows
                .OrderByDescending(r => r.Name ?? string.Empty, StringComparer.OrdinalIgnoreCase)
                .ThenBy(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase),
            "name:asc" => rows
                .OrderBy(r => r.Name ?? string.Empty, StringComparer.OrdinalIgnoreCase)
                .ThenBy(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase),
            "code:desc" => rows
                .OrderByDescending(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase),
            "code:asc" => rows
                .OrderBy(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase),
            // Jawish card: Half = sale, EndUser = purchase.
            "price:desc" => rows
                .OrderByDescending(r => r.Half ?? double.MinValue)
                .ThenBy(r => r.Number ?? int.MaxValue),
            "price:asc" => rows
                .OrderBy(r => r.Half ?? double.MaxValue)
                .ThenBy(r => r.Number ?? int.MaxValue),
            "purchase:desc" => rows
                .OrderByDescending(r => r.EndUser ?? double.MinValue)
                .ThenBy(r => r.Number ?? int.MaxValue),
            "purchase:asc" => rows
                .OrderBy(r => r.EndUser ?? double.MaxValue)
                .ThenBy(r => r.Number ?? int.MaxValue),
            _ => rows
                .OrderBy(r => r.Number ?? int.MaxValue)
                .ThenBy(r => r.Code ?? string.Empty, StringComparer.OrdinalIgnoreCase)
                .ThenBy(r => r.MaterialGuid)
        };

        return ordered.ToList();
    }

    private static double? AgeSortValue(MaterialAgeRowResponse row, string ageMode) =>
        ageMode == "weightedAverage" ? row.WeightedAgeDays : row.OldestAgeDays;

    private static IReadOnlyList<MaterialAgeStoreBalanceResponse> BuildStoreBalances(
        Guid materialGuid,
        IReadOnlyList<MaterialAgeCalculator.StoreFifoResult> storeResults,
        IReadOnlyList<MaterialAgeCalculator.SurvivingLayer> modeLayers,
        string ageMode,
        DateOnly asOf,
        Request request,
        Dictionary<(Guid Mat, Guid Store), double> ms000,
        Dictionary<Guid, StoreMeta> storeMeta)
    {
        var storeIds = new HashSet<Guid>();
        foreach (var result in storeResults)
        {
            storeIds.Add(result.StoreGuid);
        }

        foreach (var key in ms000.Keys.Where(k => k.Mat == materialGuid))
        {
            if (request.StoreGuids.Count == 0 || request.StoreGuids.Contains(key.Store))
            {
                storeIds.Add(key.Store);
            }
        }

        if (ageMode == "layers" && (request.MinAgeDays is not null || request.MaxAgeDays is not null))
        {
            storeIds = modeLayers.Select(l => l.StoreGuid).ToHashSet();
        }

        var balances = new List<MaterialAgeStoreBalanceResponse>();
        foreach (var storeGuid in storeIds.OrderBy(id => storeMeta.GetValueOrDefault(id)?.Code ?? id.ToString()))
        {
            var result = storeResults.FirstOrDefault(r => r.StoreGuid == storeGuid);
            var layersForStore = modeLayers.Where(l => l.StoreGuid == storeGuid).ToList();
            double remaining;
            if (ageMode == "layers" && (request.MinAgeDays is not null || request.MaxAgeDays is not null))
            {
                remaining = layersForStore.Sum(l => l.RemainingQuantity);
            }
            else if (result is not null)
            {
                remaining = result.NetQuantity;
            }
            else
            {
                ms000.TryGetValue((materialGuid, storeGuid), out remaining);
            }

            var layersForAges = ageMode == "layers" ? layersForStore : (result?.Layers.ToList() ?? []);
            var oldestDate = MaterialAgeCalculator.OldestDate(layersForAges);
            var oldestAge = MaterialAgeCalculator.OldestAgeDays(layersForAges, asOf);
            var weighted = MaterialAgeCalculator.WeightedAgeDays(layersForAges);
            ms000.TryGetValue((materialGuid, storeGuid), out var current);
            var hasMs = ms000.ContainsKey((materialGuid, storeGuid));
            var meta = storeMeta.GetValueOrDefault(storeGuid);

            balances.Add(new MaterialAgeStoreBalanceResponse(
                storeGuid,
                meta?.Code,
                meta?.Name,
                remaining,
                oldestDate,
                oldestAge,
                weighted,
                hasMs ? current : null));
        }

        return balances;
    }

    private async Task<ValidationError?> ValidateStoresAsync(
        IReadOnlyList<Guid> storeGuids,
        CancellationToken cancellationToken)
    {
        if (storeGuids.Count == 0)
        {
            return null;
        }

        var existing = await mainDbContext.Stores
            .AsNoTracking()
            .Where(store => storeGuids.Contains(store.Guid))
            .Select(store => store.Guid)
            .ToListAsync(cancellationToken);
        var existingSet = existing.ToHashSet();
        foreach (var guid in storeGuids)
        {
            if (!existingSet.Contains(guid))
            {
                return new ValidationError("Unknown store.", StoreGuid: guid);
            }
        }

        return null;
    }

    private static async Task<string?> ValidateRequiredColumnsAsync(
        SqlConnection sqlConnection,
        CancellationToken cancellationToken)
    {
        var required = new (string Table, string Column)[]
        {
            ("bi000", "GUID"),
            ("bi000", "Number"),
            ("bi000", "ParentGUID"),
            ("bi000", "MatGUID"),
            ("bi000", "StoreGUID"),
            ("bi000", "Qty"),
            ("bi000", "BonusQnt"),
            ("bu000", "GUID"),
            ("bu000", "Number"),
            ("bu000", "Date"),
            ("bu000", "TypeGUID"),
            ("bu000", "IsPosted"),
            ("bt000", "GUID"),
            ("bt000", "Name"),
            ("bt000", "LatinName"),
            ("bt000", "bIsInput"),
            ("bt000", "bNoPost"),
            ("ms000", "MatGUID"),
            ("ms000", "StoreGUID"),
            ("ms000", "Qty"),
            ("st000", "GUID"),
            ("st000", "Code"),
            ("st000", "Name"),
            ("mt000", "GUID"),
            ("mt000", "Code"),
            ("mt000", "Name"),
            ("mt000", "Color"),
            ("mt000", "Unity"),
        };

        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandTimeout = CommandTimeoutSeconds;
        cmd.CommandText = """
SELECT OBJECT_NAME(c.object_id) AS TableName, c.name AS ColumnName
FROM sys.columns AS c
WHERE c.object_id IN (
    OBJECT_ID(N'dbo.bi000'), OBJECT_ID(N'dbo.bu000'), OBJECT_ID(N'dbo.bt000'),
    OBJECT_ID(N'dbo.ms000'), OBJECT_ID(N'dbo.st000'), OBJECT_ID(N'dbo.mt000'))
""";
        var existing = new HashSet<(string Table, string Column)>(StringTupleComparer.Instance);
        await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            existing.Add((reader.GetString(0), reader.GetString(1)));
        }

        var missing = required
            .Where(r => !existing.Contains(r))
            .Select(r => $"{r.Table}.{r.Column}")
            .ToArray();
        if (missing.Length == 0)
        {
            return null;
        }

        return "MainDb is missing columns required for material ages: " + string.Join(", ", missing);
    }

    private sealed class StringTupleComparer : IEqualityComparer<(string Table, string Column)>
    {
        public static readonly StringTupleComparer Instance = new();
        public bool Equals((string Table, string Column) x, (string Table, string Column) y) =>
            StringComparer.OrdinalIgnoreCase.Equals(x.Table, y.Table)
            && StringComparer.OrdinalIgnoreCase.Equals(x.Column, y.Column);
        public int GetHashCode((string Table, string Column) obj) =>
            HashCode.Combine(
                StringComparer.OrdinalIgnoreCase.GetHashCode(obj.Table),
                StringComparer.OrdinalIgnoreCase.GetHashCode(obj.Column));
    }

    private static async Task<List<MaterialAgeBillTypeResponse>> LoadBillTypesAsync(
        SqlConnection sqlConnection,
        CancellationToken cancellationToken)
    {
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandTimeout = CommandTimeoutSeconds;
        cmd.CommandText = @"
SELECT
    bt.[GUID],
    bt.[Name],
    bt.[LatinName],
    CASE WHEN ISNULL(bt.[bIsInput], 0) = 1 THEN 1 ELSE 0 END AS IsInput,
    CASE WHEN ISNULL(bt.[bNoPost], 0) = 0 THEN 1 ELSE 0 END AS MovesStock,
    (
        SELECT COUNT(1)
        FROM dbo.[bu000] AS bu
        WHERE bu.[TypeGUID] = bt.[GUID]
          AND ISNULL(bu.[IsPosted], 0) <> 0
    ) AS PostedBillCount
FROM dbo.[bt000] AS bt
ORDER BY bt.[Name], bt.[GUID];";
        var rows = new List<MaterialAgeBillTypeResponse>();
        await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            rows.Add(new MaterialAgeBillTypeResponse(
                reader.GetGuid(0),
                reader.IsDBNull(1) ? null : reader.GetString(1),
                reader.IsDBNull(2) ? null : reader.GetString(2),
                Convert.ToInt32(reader.GetValue(3), CultureInfo.InvariantCulture) == 1,
                Convert.ToInt32(reader.GetValue(4), CultureInfo.InvariantCulture) == 1,
                Convert.ToInt32(reader.GetValue(5), CultureInfo.InvariantCulture)));
        }

        return rows;
    }

    private static async Task<DateOnly> ReadSqlCalendarDateAsync(
        SqlConnection sqlConnection,
        CancellationToken cancellationToken)
    {
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandTimeout = CommandTimeoutSeconds;
        cmd.CommandText = "SELECT CAST(GETDATE() AS date);";
        var value = await cmd.ExecuteScalarAsync(cancellationToken);
        return DateOnly.FromDateTime(Convert.ToDateTime(value, CultureInfo.InvariantCulture));
    }

    private static async Task<List<MaterialAgeCalculator.MovementLine>> LoadMovementLinesAsync(
        SqlConnection sqlConnection,
        Request request,
        DateOnly asOf,
        IReadOnlyList<Guid> billTypeGuids,
        IReadOnlyList<Guid> materialGuids,
        CancellationToken cancellationToken)
    {
        if (materialGuids.Count == 0 || billTypeGuids.Count == 0)
        {
            return [];
        }

        var sql = new StringBuilder();
        sql.Append("""
SELECT
    bi.[GUID]                         AS LineGuid,
    bi.[Number]                       AS LineNumber,
    bi.[MatGUID]                      AS MaterialGuid,
    bi.[StoreGUID]                    AS StoreGuid,
    ISNULL(bi.[Qty], 0)               AS Qty,
    ISNULL(bi.[BonusQnt], 0)          AS BonusQty,
    bu.[GUID]                         AS BillGuid,
    bu.[Number]                       AS BillNumber,
    CAST(bu.[Date] AS date)           AS BillDate,
    bt.[GUID]                         AS BillTypeGuid,
    bt.[Name]                         AS BillTypeName,
    bt.[LatinName]                    AS BillTypeLatinName,
    CASE WHEN bt.[bIsInput] = 1 THEN 1 ELSE -1 END AS Direction
FROM dbo.[bi000] AS bi
INNER JOIN dbo.[bu000] AS bu ON bu.[GUID] = bi.[ParentGUID]
INNER JOIN dbo.[bt000] AS bt ON bt.[GUID] = bu.[TypeGUID]
WHERE ISNULL(bu.[IsPosted], 0) <> 0
  AND bu.[Date] < DATEADD(day, 1, @asOf)
  AND bi.[MatGUID] IS NOT NULL
  AND bi.[StoreGUID] IS NOT NULL
  AND ISNULL(bt.[bNoPost], 0) = 0
""");

        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandTimeout = CommandTimeoutSeconds;
        cmd.Parameters.Add(new SqlParameter("@asOf", SqlDbType.Date) { Value = asOf.ToDateTime(TimeOnly.MinValue) });

        AppendGuidIn(sql, cmd, "bi.[StoreGUID]", request.StoreGuids, "s");
        AppendGuidIn(sql, cmd, "bt.[GUID]", billTypeGuids, "t");
        AppendGuidIn(sql, cmd, "bi.[MatGUID]", materialGuids, "m");

        cmd.CommandText = sql.ToString();
        var lines = new List<MaterialAgeCalculator.MovementLine>();
        await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            lines.Add(new MaterialAgeCalculator.MovementLine(
                reader.GetGuid(0),
                reader.IsDBNull(1) ? null : Convert.ToInt32(reader.GetValue(1), CultureInfo.InvariantCulture),
                reader.GetGuid(2),
                reader.GetGuid(3),
                Convert.ToDouble(reader.GetValue(4), CultureInfo.InvariantCulture),
                Convert.ToDouble(reader.GetValue(5), CultureInfo.InvariantCulture),
                reader.GetGuid(6),
                reader.IsDBNull(7) ? null : Convert.ToInt32(reader.GetValue(7), CultureInfo.InvariantCulture),
                DateOnly.FromDateTime(reader.GetDateTime(8)),
                reader.GetGuid(9),
                reader.IsDBNull(10) ? null : reader.GetString(10),
                reader.IsDBNull(11) ? null : reader.GetString(11),
                Convert.ToInt32(reader.GetValue(12), CultureInfo.InvariantCulture)));
        }

        return lines;
    }

    private sealed record CatalogMaterial(
        Guid MaterialGuid,
        int? Number,
        string? Code,
        string? Name,
        string? LatinName,
        string? Company,
        Guid? GroupGuid,
        string? GroupCode,
        string? GroupName,
        string? MaterialType,
        string? Unit,
        Guid? PictureGuid,
        double? EndUser,
        double? Half,
        double? Whole,
        double? LastPrice,
        double? AvgPrice,
        double? CurrencyVal);

    private static async Task<List<CatalogMaterial>> LoadCatalogMaterialsAsync(
        SqlConnection sqlConnection,
        Request request,
        IReadOnlyList<Guid>? restrictToMaterialGuids,
        CancellationToken cancellationToken)
    {
        if (restrictToMaterialGuids is { Count: 0 })
        {
            return [];
        }

        var sql = new StringBuilder();
        sql.Append("""
SELECT
    mt.[GUID],
    mt.[Number],
    mt.[Code],
    mt.[Name],
    mt.[LatinName],
    mt.[Company],
    mt.[GroupGUID],
    gr.[Code],
    gr.[Name],
    mt.[Color],
    mt.[Unity],
    mt.[PictureGUID],
    mt.[EndUser],
    mt.[Half],
    mt.[Whole],
    mt.[LastPrice],
    mt.[AvgPrice],
    mt.[CurrencyVal]
FROM dbo.[mt000] AS mt
LEFT JOIN dbo.[gr000] AS gr ON gr.[GUID] = mt.[GroupGUID]
WHERE 1 = 1
""");
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandTimeout = CommandTimeoutSeconds;
        AppendCatalogPredicates(sql, cmd, request);
        if (restrictToMaterialGuids is not null)
        {
            // Batch GUID filters when the list is large.
            if (restrictToMaterialGuids.Count <= MaterialBatchSize)
            {
                AppendGuidIn(sql, cmd, "mt.[GUID]", restrictToMaterialGuids, "cm");
                cmd.CommandText = sql.ToString();
                return await ReadCatalogAsync(cmd, cancellationToken);
            }

            var all = new List<CatalogMaterial>();
            for (var offset = 0; offset < restrictToMaterialGuids.Count; offset += MaterialBatchSize)
            {
                var batch = restrictToMaterialGuids.Skip(offset).Take(MaterialBatchSize).ToArray();
                var batchSql = new StringBuilder(sql.ToString());
                await using var batchCmd = sqlConnection.CreateCommand();
                batchCmd.CommandTimeout = CommandTimeoutSeconds;
                foreach (SqlParameter parameter in cmd.Parameters)
                {
                    batchCmd.Parameters.Add(new SqlParameter(parameter.ParameterName, parameter.SqlDbType)
                    {
                        Size = parameter.Size,
                        Value = parameter.Value
                    });
                }

                AppendGuidIn(batchSql, batchCmd, "mt.[GUID]", batch, "cm");
                batchCmd.CommandText = batchSql.ToString();
                all.AddRange(await ReadCatalogAsync(batchCmd, cancellationToken));
            }

            return all;
        }

        cmd.CommandText = sql.ToString();
        return await ReadCatalogAsync(cmd, cancellationToken);
    }

    private static async Task<List<CatalogMaterial>> ReadCatalogAsync(
        SqlCommand cmd,
        CancellationToken cancellationToken)
    {
        var rows = new List<CatalogMaterial>();
        await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            Guid? pictureGuid = null;
            if (!reader.IsDBNull(11))
            {
                var rawPicture = reader.GetGuid(11);
                if (rawPicture != Guid.Empty)
                {
                    pictureGuid = rawPicture;
                }
            }

            rows.Add(new CatalogMaterial(
                reader.GetGuid(0),
                reader.IsDBNull(1) ? null : Convert.ToInt32(reader.GetValue(1), CultureInfo.InvariantCulture),
                reader.IsDBNull(2) ? null : reader.GetString(2),
                reader.IsDBNull(3) ? null : reader.GetString(3),
                reader.IsDBNull(4) ? null : reader.GetString(4),
                reader.IsDBNull(5) ? null : reader.GetString(5),
                reader.IsDBNull(6) ? null : reader.GetGuid(6),
                reader.IsDBNull(7) ? null : reader.GetString(7),
                reader.IsDBNull(8) ? null : reader.GetString(8),
                reader.IsDBNull(9) ? null : reader.GetString(9),
                reader.IsDBNull(10) ? null : reader.GetString(10),
                pictureGuid,
                reader.IsDBNull(12) ? null : Convert.ToDouble(reader.GetValue(12), CultureInfo.InvariantCulture),
                reader.IsDBNull(13) ? null : Convert.ToDouble(reader.GetValue(13), CultureInfo.InvariantCulture),
                reader.IsDBNull(14) ? null : Convert.ToDouble(reader.GetValue(14), CultureInfo.InvariantCulture),
                reader.IsDBNull(15) ? null : Convert.ToDouble(reader.GetValue(15), CultureInfo.InvariantCulture),
                reader.IsDBNull(16) ? null : Convert.ToDouble(reader.GetValue(16), CultureInfo.InvariantCulture),
                reader.IsDBNull(17) ? null : Convert.ToDouble(reader.GetValue(17), CultureInfo.InvariantCulture)));
        }

        return rows;
    }

    private static async Task<Dictionary<(Guid Mat, Guid Store), double>> LoadMs000Async(
        SqlConnection sqlConnection,
        IReadOnlyList<Guid> storeGuids,
        IReadOnlyList<Guid>? materialGuids,
        bool skipZeroQty,
        CancellationToken cancellationToken)
    {
        var result = new Dictionary<(Guid, Guid), double>();
        if (materialGuids is { Count: 0 })
        {
            return result;
        }

        async Task ReadBatchAsync(IReadOnlyList<Guid>? batch)
        {
            var sql = new StringBuilder();
            sql.Append("""
SELECT ms.[MatGUID], ms.[StoreGUID], ISNULL(ms.[Qty], 0)
FROM dbo.[ms000] AS ms
WHERE ms.[MatGUID] IS NOT NULL
  AND ms.[StoreGUID] IS NOT NULL
""");
            if (skipZeroQty)
            {
                sql.Append(" AND ABS(ISNULL(ms.[Qty], 0)) > 0.000001");
            }

            await using var cmd = sqlConnection.CreateCommand();
            cmd.CommandTimeout = CommandTimeoutSeconds;
            AppendGuidIn(sql, cmd, "ms.[StoreGUID]", storeGuids, "ms");
            if (batch is not null)
            {
                AppendGuidIn(sql, cmd, "ms.[MatGUID]", batch, "mm");
            }

            cmd.CommandText = sql.ToString();
            await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
            while (await reader.ReadAsync(cancellationToken))
            {
                result[(reader.GetGuid(0), reader.GetGuid(1))] =
                    Convert.ToDouble(reader.GetValue(2), CultureInfo.InvariantCulture);
            }
        }

        if (materialGuids is null)
        {
            await ReadBatchAsync(null);
            return result;
        }

        for (var offset = 0; offset < materialGuids.Count; offset += MaterialBatchSize)
        {
            var batch = materialGuids.Skip(offset).Take(MaterialBatchSize).ToArray();
            await ReadBatchAsync(batch);
        }

        return result;
    }

    private sealed record StoreMeta(string? Code, string? Name);

    private static async Task<Dictionary<Guid, StoreMeta>> LoadStoreMetaAsync(
        SqlConnection sqlConnection,
        CancellationToken cancellationToken)
    {
        await using var cmd = sqlConnection.CreateCommand();
        cmd.CommandTimeout = CommandTimeoutSeconds;
        cmd.CommandText = "SELECT [GUID], [Code], [Name] FROM dbo.[st000];";
        var map = new Dictionary<Guid, StoreMeta>();
        await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            map[reader.GetGuid(0)] = new StoreMeta(
                reader.IsDBNull(1) ? null : reader.GetString(1),
                reader.IsDBNull(2) ? null : reader.GetString(2));
        }

        return map;
    }

    private static void AppendCatalogPredicates(StringBuilder sql, SqlCommand cmd, Request request)
    {
        if (request.MaterialTypes.Count > 0)
        {
            sql.Append(" AND (");
            for (var i = 0; i < request.MaterialTypes.Count; i++)
            {
                if (i > 0)
                {
                    sql.Append(" OR ");
                }

                var p = "@mt" + i;
                sql.Append("mt.[Color] LIKE ").Append(p).Append(" ESCAPE '\\'");
                cmd.Parameters.Add(new SqlParameter(p, SqlDbType.NVarChar, 200)
                {
                    Value = "%" + EscapeLike(request.MaterialTypes[i]) + "%"
                });
            }

            sql.Append(')');
        }

        if (request.GroupGuids.Count > 0)
        {
            AppendGuidIn(sql, cmd, "mt.[GroupGUID]", request.GroupGuids, "g");
        }

        if (request.Companies.Count > 0)
        {
            sql.Append(" AND (");
            for (var i = 0; i < request.Companies.Count; i++)
            {
                if (i > 0)
                {
                    sql.Append(" OR ");
                }

                var p = "@co" + i;
                sql.Append("mt.[Company] LIKE ").Append(p).Append(" ESCAPE '\\'");
                cmd.Parameters.Add(new SqlParameter(p, SqlDbType.NVarChar, 200)
                {
                    Value = "%" + EscapeLike(request.Companies[i]) + "%"
                });
            }

            sql.Append(')');
        }

        var terms = KeywordSearchTerms.Parse(request.Keyword, request.Search);
        if (terms.Count == 1)
        {
            var term = terms[0];
            var pExact = "@kwExact";
            sql.Append(" AND (mt.[Code] = ").Append(pExact);
            sql.Append(" OR (NOT EXISTS (SELECT 1 FROM dbo.[mt000] AS mtex WHERE mtex.[Code] = ").Append(pExact).Append(')');
            sql.Append(" AND (");
            AppendKeywordContains(sql, cmd, term, "kw0");
            sql.Append(")))");
            cmd.Parameters.Add(new SqlParameter(pExact, SqlDbType.NVarChar, 100) { Value = term });
        }
        else if (terms.Count > 1)
        {
            for (var i = 0; i < terms.Count; i++)
            {
                sql.Append(" AND (");
                AppendKeywordContains(sql, cmd, terms[i], "kw" + i);
                sql.Append(')');
            }
        }
    }

    private static void AppendKeywordContains(StringBuilder sql, SqlCommand cmd, string term, string prefix)
    {
        var p = "@" + prefix;
        sql.Append("mt.[Name] LIKE ").Append(p).Append(" ESCAPE '\\'");
        sql.Append(" OR mt.[LatinName] LIKE ").Append(p).Append(" ESCAPE '\\'");
        sql.Append(" OR mt.[Code] LIKE ").Append(p).Append(" ESCAPE '\\'");
        sql.Append(" OR mt.[BarCode] LIKE ").Append(p).Append(" ESCAPE '\\'");
        sql.Append(" OR mt.[BarCode2] LIKE ").Append(p).Append(" ESCAPE '\\'");
        sql.Append(" OR mt.[BarCode3] LIKE ").Append(p).Append(" ESCAPE '\\'");
        if (!cmd.Parameters.Contains(p))
        {
            cmd.Parameters.Add(new SqlParameter(p, SqlDbType.NVarChar, 200)
            {
                Value = "%" + EscapeLike(term) + "%"
            });
        }
    }

    private static void AppendGuidIn(
        StringBuilder sql,
        SqlCommand cmd,
        string columnSql,
        IReadOnlyList<Guid> guids,
        string prefix)
    {
        if (guids.Count == 0)
        {
            return;
        }

        sql.Append(" AND ").Append(columnSql).Append(" IN (");
        for (var i = 0; i < guids.Count; i++)
        {
            if (i > 0)
            {
                sql.Append(", ");
            }

            var name = "@" + prefix + i;
            sql.Append(name);
            cmd.Parameters.Add(new SqlParameter(name, SqlDbType.UniqueIdentifier) { Value = guids[i] });
        }

        sql.Append(')');
    }

    private static string EscapeLike(string value) =>
        value
            .Replace("\\", "\\\\", StringComparison.Ordinal)
            .Replace("%", "\\%", StringComparison.Ordinal)
            .Replace("_", "\\_", StringComparison.Ordinal)
            .Replace("[", "\\[", StringComparison.Ordinal);

    /// <summary>Latest posted outbound (sale) bill date per material in Amine.</summary>
    public async Task<MaterialLastSaleListResponse> ListLastSaleDatesAsync(
        IReadOnlyList<Guid> materialGuids,
        CancellationToken cancellationToken)
    {
        var distinct = materialGuids
            .Where(guid => guid != Guid.Empty)
            .Distinct()
            .ToArray();
        if (distinct.Length == 0)
        {
            return new MaterialLastSaleListResponse([]);
        }

        var connection = mainDbContext.Database.GetDbConnection();
        var sqlConnection = connection as SqlConnection
            ?? throw new InvalidOperationException("MainDb provider must be SQL Server.");

        var shouldClose = sqlConnection.State != ConnectionState.Open;
        if (shouldClose)
        {
            await sqlConnection.OpenAsync(cancellationToken);
        }

        try
        {
            var billTypes = await LoadBillTypesAsync(sqlConnection, cancellationToken);
            var outboundTypes = billTypes
                .Where(type => type.MovesStock && !type.IsInput)
                .Select(type => type.BillTypeGuid)
                .ToArray();
            if (outboundTypes.Length == 0)
            {
                return new MaterialLastSaleListResponse([]);
            }

            var rows = new List<MaterialLastSaleRowResponse>();
            foreach (var batch in distinct.Chunk(MaterialBatchSize))
            {
                var sql = new StringBuilder();
                sql.Append("""
SELECT
    bi.[MatGUID]                      AS MaterialGuid,
    MAX(CAST(bu.[Date] AS date))      AS LastSaleDate
FROM dbo.[bi000] AS bi
INNER JOIN dbo.[bu000] AS bu ON bu.[GUID] = bi.[ParentGUID]
INNER JOIN dbo.[bt000] AS bt ON bt.[GUID] = bu.[TypeGUID]
WHERE ISNULL(bu.[IsPosted], 0) <> 0
  AND ISNULL(bt.[bNoPost], 0) = 0
  AND ISNULL(bt.[bIsInput], 0) = 0
  AND bi.[MatGUID] IS NOT NULL
""");

                await using var cmd = sqlConnection.CreateCommand();
                cmd.CommandTimeout = CommandTimeoutSeconds;
                AppendGuidIn(sql, cmd, "bi.[MatGUID]", batch, "m");
                AppendGuidIn(sql, cmd, "bt.[GUID]", outboundTypes, "t");
                sql.Append("\nGROUP BY bi.[MatGUID]");
                cmd.CommandText = sql.ToString();

                await using var reader = await cmd.ExecuteReaderAsync(cancellationToken);
                while (await reader.ReadAsync(cancellationToken))
                {
                    rows.Add(new MaterialLastSaleRowResponse(
                        reader.GetGuid(0),
                        DateOnly.FromDateTime(reader.GetDateTime(1))));
                }
            }

            return new MaterialLastSaleListResponse(rows);
        }
        finally
        {
            if (shouldClose)
            {
                await sqlConnection.CloseAsync();
            }
        }
    }
}
