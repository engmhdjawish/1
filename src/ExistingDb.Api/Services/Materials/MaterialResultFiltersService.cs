using System.Linq.Expressions;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Caching.Memory;

namespace ExistingDb.Api.Services.Materials;

public sealed class MaterialResultFiltersService(
    IDbContextFactory<MainDbContext> contextFactory,
    IMemoryCache memoryCache)
{
    private const int MaxFacetValues = 100;
    private static readonly TimeSpan CacheTtl = TimeSpan.FromMinutes(15);
    private static readonly JsonSerializerOptions CacheKeyJsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase
    };

    public async Task<MaterialResultFiltersResponse> BuildAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var cacheKey = BuildCacheKey(filters, search);
        if (memoryCache.TryGetValue(cacheKey, out MaterialResultFiltersResponse? cached) && cached is not null)
        {
            return cached;
        }

        var result = await BuildUncachedAsync(filters, search, cancellationToken);
        memoryCache.Set(cacheKey, result, CacheTtl);

        return result;
    }

    private async Task<MaterialResultFiltersResponse> BuildUncachedAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var searchResolution = await ResolveSearchAsync(search, cancellationToken);

        var ageCategoriesTask = GetStringFacetAsync(
            filters,
            searchResolution,
            MaterialFilterExclusions.AgeCategories,
            material => material.Provenance,
            cancellationToken);
        var sizeRangesTask = GetStringFacetAsync(
            filters,
            searchResolution,
            MaterialFilterExclusions.SizeRanges,
            material => material.Dim,
            cancellationToken);
        var materialTypesTask = GetStringFacetAsync(
            filters,
            searchResolution,
            MaterialFilterExclusions.MaterialTypes,
            material => material.Color,
            cancellationToken);
        var manufacturersTask = GetStringFacetAsync(
            filters,
            searchResolution,
            MaterialFilterExclusions.Manufacturers,
            material => material.Company,
            cancellationToken);
        var countryOfOriginsTask = GetStringFacetAsync(
            filters,
            searchResolution,
            MaterialFilterExclusions.CountryOfOrigins,
            material => material.Origin,
            cancellationToken);
        var groupsTask = GetGroupFacetsAsync(filters, searchResolution, cancellationToken);

        await Task.WhenAll(
            ageCategoriesTask,
            sizeRangesTask,
            materialTypesTask,
            manufacturersTask,
            countryOfOriginsTask,
            groupsTask);

        return new MaterialResultFiltersResponse(
            await ageCategoriesTask,
            await sizeRangesTask,
            await materialTypesTask,
            await manufacturersTask,
            await countryOfOriginsTask,
            await groupsTask);
    }

    private static string BuildCacheKey(MaterialListFilters filters, string? search)
    {
        var payload = JsonSerializer.Serialize(new
        {
            search,
            storeGuids = filters.StoreGuids.OrderBy(guid => guid).ToArray(),
            countryOfOrigins = filters.CountryOfOrigins.Order(StringComparer.OrdinalIgnoreCase).ToArray(),
            manufacturers = filters.Manufacturers.Order(StringComparer.OrdinalIgnoreCase).ToArray(),
            sizeRanges = filters.SizeRanges.Order(StringComparer.OrdinalIgnoreCase).ToArray(),
            materialTypes = filters.MaterialTypes.Order(StringComparer.OrdinalIgnoreCase).ToArray(),
            ageCategories = filters.AgeCategories.Order(StringComparer.OrdinalIgnoreCase).ToArray(),
            groupGuids = filters.GroupGuids.OrderBy(guid => guid).ToArray(),
            materialGuids = filters.MaterialGuids.OrderBy(guid => guid).ToArray(),
            filters.MinWarehouseQuantity,
            filters.MaxWarehouseQuantity,
            filters.IsAvailable,
            filters.HasImage,
            filters.MinUnitSalePriceSyp,
            filters.MaxUnitSalePriceSyp,
            filters.MinUnitSalePriceUsd,
            filters.MaxUnitSalePriceUsd,
            filters.MinUnitPurchasePriceUsd,
            filters.MaxUnitPurchasePriceUsd
        }, CacheKeyJsonOptions);

        var hash = SHA256.HashData(Encoding.UTF8.GetBytes(payload));

        return "material_result_filters_v1:" + Convert.ToHexString(hash);
    }

    private async Task<MaterialSearchResolution> ResolveSearchAsync(
        string? search,
        CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var queryBuilder = new MaterialQueryBuilder(context);

        return await queryBuilder.ResolveSearchAsync(search, cancellationToken);
    }

    private async Task<IReadOnlyCollection<FacetValueResponse>> GetStringFacetAsync(
        MaterialListFilters filters,
        MaterialSearchResolution searchResolution,
        MaterialFilterExclusions excludeDimension,
        Expression<Func<MaterialRecord, string?>> selector,
        CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var queryBuilder = new MaterialQueryBuilder(context);
        var query = queryBuilder.BuildFacetQuery(filters, searchResolution, excludeDimension);

        var rows = await query
            .Where(BuildNotEmptyFilter(selector))
            .GroupBy(selector)
            .Select(group => new { Value = group.Key!, Count = group.Count() })
            .OrderBy(facet => facet.Value)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);

        return rows.Select(row => new FacetValueResponse(row.Value, row.Count)).ToList();
    }

    private async Task<IReadOnlyCollection<GroupFacetValueResponse>> GetGroupFacetsAsync(
        MaterialListFilters filters,
        MaterialSearchResolution searchResolution,
        CancellationToken cancellationToken)
    {
        await using var context = await contextFactory.CreateDbContextAsync(cancellationToken);
        var queryBuilder = new MaterialQueryBuilder(context);
        var query = queryBuilder.BuildFacetQuery(filters, searchResolution, MaterialFilterExclusions.Groups);

        var groupCounts = await query
            .Where(material => material.GroupGuid.HasValue)
            .GroupBy(material => material.GroupGuid!.Value)
            .Select(group => new
            {
                GroupGuid = group.Key,
                Count = group.Count()
            })
            .OrderBy(group => group.GroupGuid)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);

        if (groupCounts.Count == 0)
        {
            return [];
        }

        var groupGuids = groupCounts.Select(group => group.GroupGuid).ToArray();
        var groups = await context.MaterialGroups
            .AsNoTracking()
            .Where(group => groupGuids.Contains(group.Guid))
            .Select(group => new
            {
                group.Guid,
                group.Code,
                group.Name
            })
            .ToDictionaryAsync(group => group.Guid, cancellationToken);

        return groupCounts
            .Select(groupCount =>
            {
                groups.TryGetValue(groupCount.GroupGuid, out var group);
                return new GroupFacetValueResponse(
                    groupCount.GroupGuid,
                    group?.Code,
                    group?.Name,
                    groupCount.Count);
            })
            .OrderBy(group => group.Name)
            .ThenBy(group => group.Code)
            .ToList();
    }

    private static Expression<Func<MaterialRecord, bool>> BuildNotEmptyFilter(
        Expression<Func<MaterialRecord, string?>> selector)
    {
        var parameter = selector.Parameters[0];
        var property = selector.Body;
        var notNull = Expression.NotEqual(property, Expression.Constant(null, typeof(string)));
        var notEmpty = Expression.NotEqual(property, Expression.Constant(string.Empty, typeof(string)));
        var body = Expression.AndAlso(notNull, notEmpty);

        return Expression.Lambda<Func<MaterialRecord, bool>>(body, parameter);
    }
}
