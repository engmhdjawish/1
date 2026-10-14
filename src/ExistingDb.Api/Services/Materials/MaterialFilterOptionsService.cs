using System.Linq.Expressions;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Caching.Memory;

namespace ExistingDb.Api.Services.Materials;

/// <summary>
/// Distinct catalog dimensions change rarely. One cached snapshot replaces the
/// repeated full-table scans the store and dashboard were issuing on every page.
/// </summary>
public sealed class MaterialFilterOptionsService(
    IDbContextFactory<MainDbContext> contextFactory,
    IMemoryCache memoryCache)
{
    private const int MaxFilterOptions = 500;
    private const string CacheKey = "material_filter_options_v1";
    private static readonly TimeSpan CacheTtl = TimeSpan.FromMinutes(15);

    public async Task<MaterialFilterOptionsResponse> GetAsync(CancellationToken cancellationToken)
    {
        if (memoryCache.TryGetValue(CacheKey, out MaterialFilterOptionsResponse? cached) && cached is not null)
        {
            return cached;
        }

        var built = await BuildAsync(cancellationToken);
        memoryCache.Set(CacheKey, built, CacheTtl);

        return built;
    }

    private async Task<MaterialFilterOptionsResponse> BuildAsync(CancellationToken cancellationToken)
    {
        var originsTask = DistinctAsync(material => material.Origin, cancellationToken);
        var companiesTask = DistinctAsync(material => material.Company, cancellationToken);
        var dimsTask = DistinctAsync(material => material.Dim, cancellationToken);
        var colorsTask = DistinctAsync(material => material.Color, cancellationToken);
        var provenancesTask = DistinctAsync(material => material.Provenance, cancellationToken);
        var groupsTask = GroupsAsync(cancellationToken);
        var storesTask = StoresAsync(cancellationToken);
        var pricesTask = PriceRangesAsync(cancellationToken);

        await Task.WhenAll(
            originsTask,
            companiesTask,
            dimsTask,
            colorsTask,
            provenancesTask,
            groupsTask,
            storesTask,
            pricesTask);

        return new MaterialFilterOptionsResponse(
            await originsTask,
            await companiesTask,
            await dimsTask,
            await colorsTask,
            await provenancesTask,
            await groupsTask,
            await storesTask,
            await pricesTask);
    }

    private async Task<IReadOnlyCollection<string>> DistinctAsync(
        Expression<Func<MaterialRecord, string?>> selector,
        CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);

        return await context.Materials
            .AsNoTracking()
            .Select(selector)
            .Where(value => value != null && value != string.Empty)
            .Select(value => value!)
            .Distinct()
            .OrderBy(value => value)
            .Take(MaxFilterOptions)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<LookupOptionResponse>> GroupsAsync(CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);

        return await context.MaterialGroups
            .AsNoTracking()
            .OrderBy(group => group.Number)
            .ThenBy(group => group.Name)
            .Take(MaxFilterOptions)
            .Select(group => new LookupOptionResponse(group.Guid, group.Code, group.Name, group.LatinName))
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<LookupOptionResponse>> StoresAsync(CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);

        var stores = await context.Stores
            .AsNoTracking()
            .Where(store => store.IsActive == null || store.IsActive == true)
            .OrderBy(store => store.Number)
            .ThenBy(store => store.Name)
            .Take(MaxFilterOptions)
            .Select(store => new LookupOptionResponse(store.Guid, store.Code, store.Name, store.LatinName))
            .ToListAsync(cancellationToken);

        if (stores.Count > 0)
        {
            return stores;
        }

        stores = await context.Stores
            .AsNoTracking()
            .OrderBy(store => store.Number)
            .ThenBy(store => store.Name)
            .Take(MaxFilterOptions)
            .Select(store => new LookupOptionResponse(store.Guid, store.Code, store.Name, store.LatinName))
            .ToListAsync(cancellationToken);

        if (stores.Count > 0)
        {
            return stores;
        }

        var storeGuids = await context.MaterialInventory
            .AsNoTracking()
            .Where(inventory => inventory.StoreGuid.HasValue)
            .Select(inventory => inventory.StoreGuid!.Value)
            .Distinct()
            .Take(MaxFilterOptions)
            .ToListAsync(cancellationToken);

        if (storeGuids.Count == 0)
        {
            return [];
        }

        var storesByGuid = await context.Stores
            .AsNoTracking()
            .Where(store => storeGuids.Contains(store.Guid))
            .Select(store => new LookupOptionResponse(store.Guid, store.Code, store.Name, store.LatinName))
            .ToDictionaryAsync(store => store.Guid, cancellationToken);

        return storeGuids
            .Select(storeGuid => storesByGuid.GetValueOrDefault(storeGuid)
                ?? new LookupOptionResponse(storeGuid, storeGuid.ToString("N"), null, null))
            .OrderBy(store => store.Name ?? store.Code ?? store.Guid.ToString())
            .ToList();
    }

    private async Task<MaterialPriceRangesResponse> PriceRangesAsync(CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var ranges = await context.Materials
            .AsNoTracking()
            .GroupBy(_ => 1)
            .Select(group => new
            {
                MinWhole = group.Min(material => material.Whole),
                MaxWhole = group.Max(material => material.Whole),
                MinHalf = group.Min(material => material.Half),
                MaxHalf = group.Max(material => material.Half),
                MinEndUser = group.Min(material => material.EndUser),
                MaxEndUser = group.Max(material => material.EndUser)
            })
            .FirstOrDefaultAsync(cancellationToken);

        if (ranges is null)
        {
            return new MaterialPriceRangesResponse(null, null, null);
        }

        return new MaterialPriceRangesResponse(
            ToRange(ranges.MinWhole, ranges.MaxWhole),
            ToRange(ranges.MinHalf, ranges.MaxHalf),
            ToRange(ranges.MinEndUser, ranges.MaxEndUser));
    }

    private static PriceRangeResponse? ToRange(double? min, double? max) =>
        min is null && max is null ? null : new PriceRangeResponse(min, max);
}
