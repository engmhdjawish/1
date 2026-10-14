using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Materials;

public sealed class MaterialCatalogRequest
{
    public required string Key { get; init; }

    public required MaterialListFilters Filters { get; init; }

    public required Func<IQueryable<MaterialRecord>, IOrderedQueryable<MaterialRecord>> Order { get; init; }

    public bool IncludeTotalCount { get; init; }

    public int Page { get; init; } = 1;

    public int PageSize { get; init; } = 24;
}

public sealed class MaterialPageResult
{
    public required IReadOnlyList<MaterialRecord> Materials { get; init; }

    public required IReadOnlyDictionary<Guid, double?> QuantityByMaterial { get; init; }

    public required int TotalCount { get; init; }

    public required int Page { get; init; }

    public required int PageSize { get; init; }
}

/// <summary>
/// Runs material list, exact code lookup, and short suggestions on their own DbContext
/// so several slices can execute together without sharing one context.
/// </summary>
public sealed class MaterialCatalogService(IDbContextFactory<MainDbContext> contextFactory)
{
    public const int MaxBatchQueries = 8;

    private static readonly SemaphoreSlim BatchGate = new(3, 3);

    public Task<MaterialPageResult> QueryAsync(
        MaterialListFilters filters,
        Func<IQueryable<MaterialRecord>, IOrderedQueryable<MaterialRecord>> order,
        bool includeTotalCount,
        int page,
        int pageSize,
        CancellationToken cancellationToken) =>
        QueryOnNewContextAsync(
            new MaterialCatalogRequest
            {
                Key = "page",
                Filters = filters,
                Order = order,
                IncludeTotalCount = includeTotalCount,
                Page = page,
                PageSize = pageSize
            },
            cancellationToken);

    public async Task<IReadOnlyDictionary<string, MaterialPageResult>> QueryManyAsync(
        IReadOnlyList<MaterialCatalogRequest> requests,
        CancellationToken cancellationToken)
    {
        var results = new System.Collections.Concurrent.ConcurrentDictionary<string, MaterialPageResult>(StringComparer.Ordinal);
        await Task.WhenAll(requests.Select(async request =>
        {
            await BatchGate.WaitAsync(cancellationToken);
            try
            {
                results[request.Key] = await QueryOnNewContextAsync(request, cancellationToken);
            }
            finally
            {
                BatchGate.Release();
            }
        }));

        return results;
    }

    public async Task<MaterialPageResult> LookupExactAsync(
        string codeOrBarcode,
        MaterialListFilters scope,
        CancellationToken cancellationToken)
    {
        var term = codeOrBarcode.Trim();
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var queryBuilder = new MaterialQueryBuilder(context);
        var query = queryBuilder.Build(scope);
        query = await queryBuilder.ApplySearchFilterAsync(query, scope.Search, cancellationToken);
        var matches = await query
            .Where(material =>
                material.Code == term
                || material.BarCode == term
                || material.BarCode2 == term
                || material.BarCode3 == term)
            .OrderBy(material => material.Number)
            .ThenBy(material => material.Name)
            .Take(12)
            .ToListAsync(cancellationToken);

        var chosen = matches.FirstOrDefault(material =>
                string.Equals(material.Code, term, StringComparison.OrdinalIgnoreCase))
            ?? matches.FirstOrDefault();
        var materials = chosen is null ? Array.Empty<MaterialRecord>() : new[] { chosen };
        var quantities = await GetQuantityByMaterialAsync(context, materials, scope.StoreGuids, cancellationToken);

        return new MaterialPageResult
        {
            Materials = materials,
            QuantityByMaterial = quantities,
            TotalCount = materials.Length,
            Page = 1,
            PageSize = 1
        };
    }

    public async Task<MaterialPageResult> SuggestAsync(
        string term,
        int limit,
        MaterialListFilters scope,
        CancellationToken cancellationToken)
    {
        term = term.Trim();
        limit = Math.Clamp(limit, 1, 20);
        if (term.Length < 2)
        {
            return EmptyPage(limit);
        }

        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var queryBuilder = new MaterialQueryBuilder(context);
        var query = queryBuilder.Build(scope);
        query = await queryBuilder.ApplySearchFilterAsync(query, scope.Search, cancellationToken);
        var exact = await query
            .Where(material =>
                material.Code == term
                || material.BarCode == term
                || material.BarCode2 == term
                || material.BarCode3 == term)
            .OrderBy(material => material.Number)
            .ThenBy(material => material.Name)
            .Take(limit)
            .ToListAsync(cancellationToken);

        if (exact.Count >= limit || (exact.Count > 0 && LooksLikeCode(term)))
        {
            var exactQuantities = await GetQuantityByMaterialAsync(context, exact, scope.StoreGuids, cancellationToken);
            return new MaterialPageResult
            {
                Materials = exact,
                QuantityByMaterial = exactQuantities,
                TotalCount = exact.Count,
                Page = 1,
                PageSize = limit
            };
        }

        var exclude = exact.Select(material => material.Guid).ToArray();
        var remainder = query;
        if (exclude.Length > 0)
        {
            remainder = remainder.Where(material => !exclude.Contains(material.Guid));
        }

        var fuzzy = await remainder
            .Where(material =>
                (material.Name != null && material.Name.Contains(term))
                || (material.LatinName != null && material.LatinName.Contains(term))
                || (material.Code != null && material.Code.Contains(term))
                || (material.BarCode != null && material.BarCode.Contains(term)))
            .OrderBy(material => material.Number)
            .ThenBy(material => material.Name)
            .Take(limit - exact.Count)
            .ToListAsync(cancellationToken);

        var materials = exact.Concat(fuzzy).ToArray();
        var quantities = await GetQuantityByMaterialAsync(context, materials, scope.StoreGuids, cancellationToken);

        return new MaterialPageResult
        {
            Materials = materials,
            QuantityByMaterial = quantities,
            TotalCount = materials.Length,
            Page = 1,
            PageSize = limit
        };
    }

    private async Task<MaterialPageResult> QueryOnNewContextAsync(
        MaterialCatalogRequest request,
        CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var queryBuilder = new MaterialQueryBuilder(context);
        var query = queryBuilder.Build(request.Filters);
        query = await queryBuilder.ApplySearchFilterAsync(query, request.Filters.Search, cancellationToken);

        var page = Math.Max(1, request.Page);
        var pageSize = Math.Clamp(request.PageSize, 1, 200);
        var skipTotalCount = !request.IncludeTotalCount || request.Filters.MaterialGuids.Count > 0;
        var totalCount = skipTotalCount
            ? 0
            : await query.CountAsync(cancellationToken);
        var materials = await request.Order(query)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);
        if (skipTotalCount)
        {
            totalCount = materials.Count;
        }

        var quantities = await GetQuantityByMaterialAsync(context, materials, request.Filters.StoreGuids, cancellationToken);

        return new MaterialPageResult
        {
            Materials = materials,
            QuantityByMaterial = quantities,
            TotalCount = totalCount,
            Page = page,
            PageSize = pageSize
        };
    }

    private static async Task<Dictionary<Guid, double?>> GetQuantityByMaterialAsync(
        MainDbContext context,
        IReadOnlyCollection<MaterialRecord> materials,
        IReadOnlyCollection<Guid> selectedStoreGuids,
        CancellationToken cancellationToken)
    {
        if (selectedStoreGuids.Count == 0 || materials.Count == 0)
        {
            return [];
        }

        var materialGuids = materials.Select(material => material.Guid).ToArray();

        return await context.MaterialInventory
            .AsNoTracking()
            .Where(inventory => inventory.MaterialGuid.HasValue)
            .Where(inventory => materialGuids.Contains(inventory.MaterialGuid!.Value))
            .Where(inventory => inventory.StoreGuid.HasValue && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
            .GroupBy(inventory => inventory.MaterialGuid!.Value)
            .Select(group => new
            {
                MaterialGuid = group.Key,
                Quantity = group.Sum(inventory => inventory.Qty ?? 0)
            })
            .ToDictionaryAsync(row => row.MaterialGuid, row => (double?)row.Quantity, cancellationToken);
    }

    private static bool LooksLikeCode(string term)
    {
        if (term.Any(char.IsWhiteSpace))
        {
            return false;
        }

        var hasDigit = false;
        foreach (var character in term)
        {
            if (character is >= '\u0600' and <= '\u06FF')
            {
                return false;
            }

            if (char.IsDigit(character))
            {
                hasDigit = true;
            }
        }

        return hasDigit || term.Length <= 16;
    }

    private static MaterialPageResult EmptyPage(int pageSize) =>
        new()
        {
            Materials = [],
            QuantityByMaterial = new Dictionary<Guid, double?>(),
            TotalCount = 0,
            Page = 1,
            PageSize = pageSize
        };
}
