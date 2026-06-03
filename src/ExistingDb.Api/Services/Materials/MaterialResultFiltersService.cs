using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Materials;

public sealed class MaterialResultFiltersService(MainDbContext mainDbContext, MaterialQueryBuilder queryBuilder)
{
    private const int MaxFacetValues = 100;

    public async Task<MaterialResultFiltersResponse> BuildAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var ageCategories = await GetProvenanceFacetAsync(filters, search, cancellationToken);
        var sizeRanges = await GetDimFacetAsync(filters, search, cancellationToken);
        var materialTypes = await GetColorFacetAsync(filters, search, cancellationToken);
        var manufacturers = await GetCompanyFacetAsync(filters, search, cancellationToken);
        var countryOfOrigins = await GetOriginFacetAsync(filters, search, cancellationToken);
        var groups = await GetGroupFacetsAsync(filters, search, cancellationToken);

        return new MaterialResultFiltersResponse(
            ageCategories,
            sizeRanges,
            materialTypes,
            manufacturers,
            countryOfOrigins,
            groups);
    }

    private async Task<IReadOnlyCollection<FacetValueResponse>> GetProvenanceFacetAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var query = await BuildFacetQueryAsync(filters, search, MaterialFilterExclusions.AgeCategories, cancellationToken);

        return await query
            .Where(material => material.Provenance != null && material.Provenance != string.Empty)
            .GroupBy(material => material.Provenance!)
            .Select(group => new FacetValueResponse(group.Key, group.Count()))
            .OrderBy(facet => facet.Value)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<FacetValueResponse>> GetDimFacetAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var query = await BuildFacetQueryAsync(filters, search, MaterialFilterExclusions.SizeRanges, cancellationToken);

        return await query
            .Where(material => material.Dim != null && material.Dim != string.Empty)
            .GroupBy(material => material.Dim!)
            .Select(group => new FacetValueResponse(group.Key, group.Count()))
            .OrderBy(facet => facet.Value)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<FacetValueResponse>> GetColorFacetAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var query = await BuildFacetQueryAsync(filters, search, MaterialFilterExclusions.MaterialTypes, cancellationToken);

        return await query
            .Where(material => material.Color != null && material.Color != string.Empty)
            .GroupBy(material => material.Color!)
            .Select(group => new FacetValueResponse(group.Key, group.Count()))
            .OrderBy(facet => facet.Value)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<FacetValueResponse>> GetCompanyFacetAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var query = await BuildFacetQueryAsync(filters, search, MaterialFilterExclusions.Manufacturers, cancellationToken);

        return await query
            .Where(material => material.Company != null && material.Company != string.Empty)
            .GroupBy(material => material.Company!)
            .Select(group => new FacetValueResponse(group.Key, group.Count()))
            .OrderBy(facet => facet.Value)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<FacetValueResponse>> GetOriginFacetAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var query = await BuildFacetQueryAsync(filters, search, MaterialFilterExclusions.CountryOfOrigins, cancellationToken);

        return await query
            .Where(material => material.Origin != null && material.Origin != string.Empty)
            .GroupBy(material => material.Origin!)
            .Select(group => new FacetValueResponse(group.Key, group.Count()))
            .OrderBy(facet => facet.Value)
            .Take(MaxFacetValues)
            .ToListAsync(cancellationToken);
    }

    private async Task<IQueryable<ExistingDb.Api.Data.MainDb.MaterialRecord>> BuildFacetQueryAsync(
        MaterialListFilters filters,
        string? search,
        MaterialFilterExclusions excludeDimension,
        CancellationToken cancellationToken)
    {
        var query = queryBuilder.Build(filters, excludeDimension);
        return await queryBuilder.ApplySearchFilterAsync(query, search, cancellationToken);
    }

    private async Task<IReadOnlyCollection<GroupFacetValueResponse>> GetGroupFacetsAsync(
        MaterialListFilters filters,
        string? search,
        CancellationToken cancellationToken)
    {
        var query = await BuildFacetQueryAsync(filters, search, MaterialFilterExclusions.Groups, cancellationToken);

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
        var groups = await mainDbContext.MaterialGroups
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
}
