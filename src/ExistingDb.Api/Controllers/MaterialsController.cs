using System.Linq.Expressions;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using ExistingDb.Api.Services.Materials;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/materials")]
[RequirePermission("materials.read")]
public sealed class MaterialsController(
    MainDbContext mainDbContext,
    IPermissionService permissionService,
    IFieldMasker fieldMasker,
    MaterialQueryBuilder materialQueryBuilder,
    MaterialResultFiltersService materialResultFiltersService) : ControllerBase
{
    private const string ResourceCode = "materials";
    private const int MaxFilterOptions = 500;

    [HttpGet]
    public async Task<ActionResult<MaterialPagedResponse>> GetMaterials(
        [FromQuery] string? search = null,
        [FromQuery] Guid? storeGuid = null,
        [FromQuery] string? storeGuids = null,
        [FromQuery] string? countryOfOrigin = null,
        [FromQuery] string? countryOfOrigins = null,
        [FromQuery] string? manufacturer = null,
        [FromQuery] string? manufacturers = null,
        [FromQuery] string? sizeRange = null,
        [FromQuery] string? sizeRanges = null,
        [FromQuery] string? materialType = null,
        [FromQuery] string? materialTypes = null,
        [FromQuery] string? ageCategory = null,
        [FromQuery] string? ageCategories = null,
        [FromQuery] Guid? groupGuid = null,
        [FromQuery] string? groupGuids = null,
        [FromQuery] double? minWarehouseQuantity = null,
        [FromQuery] double? maxWarehouseQuantity = null,
        [FromQuery] bool? isAvailable = null,
        [FromQuery] double? minUnitSalePriceSyp = null,
        [FromQuery] double? maxUnitSalePriceSyp = null,
        [FromQuery] double? minUnitSalePriceUsd = null,
        [FromQuery] double? maxUnitSalePriceUsd = null,
        [FromQuery] double? minUnitPurchasePriceUsd = null,
        [FromQuery] double? maxUnitPurchasePriceUsd = null,
        [FromQuery] string? groupBy = null,
        [FromQuery] string? sortBy = null,
        [FromQuery] string? sortDirection = null,
        [FromQuery] string? sort = null,
        [FromQuery] bool includeResultFilters = false,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var filters = MaterialListFilters.FromQuery(
            search,
            storeGuid,
            storeGuids,
            countryOfOrigin,
            countryOfOrigins,
            manufacturer,
            manufacturers,
            sizeRange,
            sizeRanges,
            materialType,
            materialTypes,
            ageCategory,
            ageCategories,
            groupGuid,
            groupGuids,
            minWarehouseQuantity,
            maxWarehouseQuantity,
            isAvailable,
            minUnitSalePriceSyp,
            maxUnitSalePriceSyp,
            minUnitSalePriceUsd,
            maxUnitSalePriceUsd,
            minUnitPurchasePriceUsd,
            maxUnitPurchasePriceUsd);

        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var priceFilterAccessResult = ValidatePriceFilterAccess(
            fieldAccess,
            filters.MinUnitSalePriceSyp,
            filters.MaxUnitSalePriceSyp,
            filters.MinUnitSalePriceUsd,
            filters.MaxUnitSalePriceUsd,
            filters.MinUnitPurchasePriceUsd,
            filters.MaxUnitPurchasePriceUsd);

        if (priceFilterAccessResult is not null)
        {
            return priceFilterAccessResult;
        }

        if (!TryParseGroupBy(groupBy, out var grouping))
        {
            return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
            {
                ["groupBy"] = ["Invalid value. Supported values: ageCategory, sizeRange, materialType, manufacturer, countryOfOrigin, group."]
            }));
        }

        IReadOnlyCollection<MaterialSortClause> sortClauses;
        if (!string.IsNullOrWhiteSpace(sort))
        {
            if (!TryParseSort(sort, out sortClauses))
            {
                return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
                {
                    ["sort"] = ["Invalid value. Use comma-separated fields, e.g. ageCategory:asc,materialType:asc,-manufacturer."]
                }));
            }
        }
        else
        {
            if (!TryParseSortBy(sortBy, out var sorting))
            {
                return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
                {
                    ["sortBy"] = ["Invalid value. Supported values: number, name, ageCategory, sizeRange, materialType, manufacturer, countryOfOrigin, warehouseQuantity, unitSalePriceSyp, unitSalePriceUsd, unitPurchasePriceUsd."]
                }));
            }

            if (!TryParseSortDirection(sortDirection, out var direction))
            {
                return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
                {
                    ["sortDirection"] = ["Invalid value. Supported values: asc, desc."]
                }));
            }

            sortClauses = [new MaterialSortClause(sorting, direction)];
        }

        var query = materialQueryBuilder.Build(filters);
        query = await materialQueryBuilder.ApplySearchFilterAsync(query, filters.Search, cancellationToken);

        var totalCount = await query.CountAsync(cancellationToken);
        var materials = await ApplyOrdering(query, grouping, sortClauses)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var quantityByMaterial = await GetQuantityByMaterialAsync(materials, filters.StoreGuids, cancellationToken);
        var items = materials
            .Select(material => ToResponse(material, fieldAccess, quantityByMaterial.GetValueOrDefault(material.Guid, material.Qty)))
            .ToArray();

        MaterialAppliedFiltersResponse? appliedFilters = null;
        MaterialResultFiltersResponse? resultFilters = null;
        if (includeResultFilters)
        {
            appliedFilters = ToAppliedFilters(filters);
            resultFilters = await materialResultFiltersService.BuildAsync(filters, filters.Search, cancellationToken);
        }

        MaterialGroupingResponse? groupingResponse = null;
        if (grouping != MaterialGroupBy.None)
        {
            groupingResponse = await BuildGroupingResponseAsync(grouping, query, materials, items, cancellationToken);
        }

        return Ok(new MaterialPagedResponse(items, page, pageSize, totalCount, appliedFilters, resultFilters, groupingResponse));
    }

    private static MaterialAppliedFiltersResponse ToAppliedFilters(MaterialListFilters filters) =>
        new(
            filters.Search,
            filters.StoreGuids,
            filters.CountryOfOrigins,
            filters.Manufacturers,
            filters.SizeRanges,
            filters.MaterialTypes,
            filters.AgeCategories,
            filters.GroupGuids,
            filters.MinWarehouseQuantity,
            filters.MaxWarehouseQuantity,
            filters.IsAvailable,
            filters.MinUnitSalePriceSyp,
            filters.MaxUnitSalePriceSyp,
            filters.MinUnitSalePriceUsd,
            filters.MaxUnitSalePriceUsd,
            filters.MinUnitPurchasePriceUsd,
            filters.MaxUnitPurchasePriceUsd);

    private static IOrderedQueryable<MaterialRecord> ApplyOrdering(
        IQueryable<MaterialRecord> query,
        MaterialGroupBy grouping,
        IReadOnlyCollection<MaterialSortClause> sortClauses)
    {
        IOrderedQueryable<MaterialRecord>? ordered = grouping == MaterialGroupBy.None
            ? null
            : grouping switch
            {
                MaterialGroupBy.AgeCategory => query.OrderBy(material => material.Provenance ?? string.Empty),
                MaterialGroupBy.SizeRange => query.OrderBy(material => material.Dim ?? string.Empty),
                MaterialGroupBy.MaterialType => query.OrderBy(material => material.Color ?? string.Empty),
                MaterialGroupBy.Manufacturer => query.OrderBy(material => material.Company ?? string.Empty),
                MaterialGroupBy.CountryOfOrigin => query.OrderBy(material => material.Origin ?? string.Empty),
                MaterialGroupBy.Group => query.OrderBy(material => material.GroupGuid),
                _ => null
            };

        foreach (var sortClause in sortClauses)
        {
            ordered = ordered is null
                ? ApplyPrimarySort(query, sortClause.SortBy, sortClause.Direction)
                : ApplySecondarySort(ordered, sortClause.SortBy, sortClause.Direction);
        }

        return ordered ?? query.OrderBy(material => material.Number).ThenBy(material => material.Name);
    }

    private static IOrderedQueryable<MaterialRecord> ApplyPrimarySort(
        IQueryable<MaterialRecord> query,
        MaterialSortBy sorting,
        SortDirection direction)
    {
        var descending = direction == SortDirection.Desc;
        return sorting switch
        {
            MaterialSortBy.Name => descending
                ? query.OrderByDescending(material => material.Name ?? string.Empty).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Name ?? string.Empty).ThenBy(material => material.Number),
            MaterialSortBy.AgeCategory => descending
                ? query.OrderByDescending(material => material.Provenance ?? string.Empty).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Provenance ?? string.Empty).ThenBy(material => material.Number),
            MaterialSortBy.SizeRange => descending
                ? query.OrderByDescending(material => material.Dim ?? string.Empty).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Dim ?? string.Empty).ThenBy(material => material.Number),
            MaterialSortBy.MaterialType => descending
                ? query.OrderByDescending(material => material.Color ?? string.Empty).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Color ?? string.Empty).ThenBy(material => material.Number),
            MaterialSortBy.Manufacturer => descending
                ? query.OrderByDescending(material => material.Company ?? string.Empty).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Company ?? string.Empty).ThenBy(material => material.Number),
            MaterialSortBy.CountryOfOrigin => descending
                ? query.OrderByDescending(material => material.Origin ?? string.Empty).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Origin ?? string.Empty).ThenBy(material => material.Number),
            MaterialSortBy.WarehouseQuantity => descending
                ? query.OrderByDescending(material => material.Qty ?? 0).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Qty ?? 0).ThenBy(material => material.Number),
            MaterialSortBy.UnitSalePriceSyp => descending
                ? query.OrderByDescending(material => material.Whole ?? 0).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Whole ?? 0).ThenBy(material => material.Number),
            MaterialSortBy.UnitSalePriceUsd => descending
                ? query.OrderByDescending(material => material.Half ?? 0).ThenBy(material => material.Number)
                : query.OrderBy(material => material.Half ?? 0).ThenBy(material => material.Number),
            MaterialSortBy.UnitPurchasePriceUsd => descending
                ? query.OrderByDescending(material => material.EndUser ?? 0).ThenBy(material => material.Number)
                : query.OrderBy(material => material.EndUser ?? 0).ThenBy(material => material.Number),
            _ => descending
                ? query.OrderByDescending(material => material.Number).ThenBy(material => material.Name)
                : query.OrderBy(material => material.Number).ThenBy(material => material.Name)
        };
    }

    private static IOrderedQueryable<MaterialRecord> ApplySecondarySort(
        IOrderedQueryable<MaterialRecord> query,
        MaterialSortBy sorting,
        SortDirection direction)
    {
        var descending = direction == SortDirection.Desc;
        return sorting switch
        {
            MaterialSortBy.Name => descending ? query.ThenByDescending(material => material.Name ?? string.Empty) : query.ThenBy(material => material.Name ?? string.Empty),
            MaterialSortBy.AgeCategory => descending ? query.ThenByDescending(material => material.Provenance ?? string.Empty) : query.ThenBy(material => material.Provenance ?? string.Empty),
            MaterialSortBy.SizeRange => descending ? query.ThenByDescending(material => material.Dim ?? string.Empty) : query.ThenBy(material => material.Dim ?? string.Empty),
            MaterialSortBy.MaterialType => descending ? query.ThenByDescending(material => material.Color ?? string.Empty) : query.ThenBy(material => material.Color ?? string.Empty),
            MaterialSortBy.Manufacturer => descending ? query.ThenByDescending(material => material.Company ?? string.Empty) : query.ThenBy(material => material.Company ?? string.Empty),
            MaterialSortBy.CountryOfOrigin => descending ? query.ThenByDescending(material => material.Origin ?? string.Empty) : query.ThenBy(material => material.Origin ?? string.Empty),
            MaterialSortBy.WarehouseQuantity => descending ? query.ThenByDescending(material => material.Qty ?? 0) : query.ThenBy(material => material.Qty ?? 0),
            MaterialSortBy.UnitSalePriceSyp => descending ? query.ThenByDescending(material => material.Whole ?? 0) : query.ThenBy(material => material.Whole ?? 0),
            MaterialSortBy.UnitSalePriceUsd => descending ? query.ThenByDescending(material => material.Half ?? 0) : query.ThenBy(material => material.Half ?? 0),
            MaterialSortBy.UnitPurchasePriceUsd => descending ? query.ThenByDescending(material => material.EndUser ?? 0) : query.ThenBy(material => material.EndUser ?? 0),
            _ => query.ThenBy(material => material.Number).ThenBy(material => material.Name)
        };
    }

    private async Task<MaterialGroupingResponse> BuildGroupingResponseAsync(
        MaterialGroupBy grouping,
        IQueryable<MaterialRecord> filteredQuery,
        IReadOnlyCollection<MaterialRecord> pageMaterials,
        IReadOnlyCollection<MaterialResponse> pageItems,
        CancellationToken cancellationToken)
    {
        var counts = await GetGroupingCountsAsync(grouping, filteredQuery, cancellationToken);
        var itemsByGuid = pageItems.ToDictionary(item => item.MaterialGuid);
        var groupedPageItems = pageMaterials
            .Select(material => new
            {
                Key = GetGroupingKey(material, grouping),
                Item = itemsByGuid.GetValueOrDefault(material.Guid)
            })
            .Where(row => !string.IsNullOrWhiteSpace(row.Key) && row.Item is not null)
            .GroupBy(row => row.Key!)
            .ToDictionary(
                group => group.Key,
                group => (IReadOnlyCollection<MaterialResponse>)group.Select(row => row.Item!).ToList());

        var groupedResponse = counts
            .Select(group => new MaterialGroupBucketResponse(
                group.Key,
                group.DisplayName,
                group.TotalCount,
                groupedPageItems.GetValueOrDefault(group.Key, Array.Empty<MaterialResponse>())))
            .ToList();

        return new MaterialGroupingResponse(ToGroupByValue(grouping), groupedResponse);
    }

    private async Task<IReadOnlyCollection<MaterialGroupingCount>> GetGroupingCountsAsync(
        MaterialGroupBy grouping,
        IQueryable<MaterialRecord> query,
        CancellationToken cancellationToken)
    {
        return grouping switch
        {
            MaterialGroupBy.AgeCategory => await query
                .Where(material => material.Provenance != null && material.Provenance != string.Empty)
                .GroupBy(material => material.Provenance!)
                .Select(group => new MaterialGroupingCount(group.Key, group.Key, group.Count()))
                .OrderBy(group => group.DisplayName)
                .ToListAsync(cancellationToken),
            MaterialGroupBy.SizeRange => await query
                .Where(material => material.Dim != null && material.Dim != string.Empty)
                .GroupBy(material => material.Dim!)
                .Select(group => new MaterialGroupingCount(group.Key, group.Key, group.Count()))
                .OrderBy(group => group.DisplayName)
                .ToListAsync(cancellationToken),
            MaterialGroupBy.MaterialType => await query
                .Where(material => material.Color != null && material.Color != string.Empty)
                .GroupBy(material => material.Color!)
                .Select(group => new MaterialGroupingCount(group.Key, group.Key, group.Count()))
                .OrderBy(group => group.DisplayName)
                .ToListAsync(cancellationToken),
            MaterialGroupBy.Manufacturer => await query
                .Where(material => material.Company != null && material.Company != string.Empty)
                .GroupBy(material => material.Company!)
                .Select(group => new MaterialGroupingCount(group.Key, group.Key, group.Count()))
                .OrderBy(group => group.DisplayName)
                .ToListAsync(cancellationToken),
            MaterialGroupBy.CountryOfOrigin => await query
                .Where(material => material.Origin != null && material.Origin != string.Empty)
                .GroupBy(material => material.Origin!)
                .Select(group => new MaterialGroupingCount(group.Key, group.Key, group.Count()))
                .OrderBy(group => group.DisplayName)
                .ToListAsync(cancellationToken),
            MaterialGroupBy.Group => await GetGroupGuidCountsAsync(query, cancellationToken),
            _ => []
        };
    }

    private async Task<IReadOnlyCollection<MaterialGroupingCount>> GetGroupGuidCountsAsync(
        IQueryable<MaterialRecord> query,
        CancellationToken cancellationToken)
    {
        var countsByGroup = await query
            .Where(material => material.GroupGuid.HasValue)
            .GroupBy(material => material.GroupGuid!.Value)
            .Select(group => new
            {
                GroupGuid = group.Key,
                Count = group.Count()
            })
            .ToListAsync(cancellationToken);

        if (countsByGroup.Count == 0)
        {
            return [];
        }

        var groupGuids = countsByGroup.Select(group => group.GroupGuid).ToArray();
        var groups = await mainDbContext.MaterialGroups
            .AsNoTracking()
            .Where(group => groupGuids.Contains(group.Guid))
            .Select(group => new
            {
                group.Guid,
                group.Name,
                group.Code
            })
            .ToDictionaryAsync(group => group.Guid, cancellationToken);

        return countsByGroup
            .Select(group =>
            {
                groups.TryGetValue(group.GroupGuid, out var groupData);
                var displayName = groupData?.Name ?? groupData?.Code ?? group.GroupGuid.ToString();
                return new MaterialGroupingCount(group.GroupGuid.ToString(), displayName, group.Count);
            })
            .OrderBy(group => group.DisplayName)
            .ToList();
    }

    private static string? GetGroupingKey(MaterialRecord material, MaterialGroupBy grouping) =>
        grouping switch
        {
            MaterialGroupBy.AgeCategory => material.Provenance,
            MaterialGroupBy.SizeRange => material.Dim,
            MaterialGroupBy.MaterialType => material.Color,
            MaterialGroupBy.Manufacturer => material.Company,
            MaterialGroupBy.CountryOfOrigin => material.Origin,
            MaterialGroupBy.Group => material.GroupGuid?.ToString(),
            _ => null
        };

    private static bool TryParseGroupBy(string? value, out MaterialGroupBy grouping)
    {
        grouping = MaterialGroupBy.None;
        if (string.IsNullOrWhiteSpace(value))
        {
            return true;
        }

        grouping = value.Trim().ToLowerInvariant() switch
        {
            "agecategory" or "age" => MaterialGroupBy.AgeCategory,
            "sizerange" or "size" => MaterialGroupBy.SizeRange,
            "materialtype" or "type" => MaterialGroupBy.MaterialType,
            "manufacturer" => MaterialGroupBy.Manufacturer,
            "countryoforigin" or "origin" => MaterialGroupBy.CountryOfOrigin,
            "group" => MaterialGroupBy.Group,
            _ => MaterialGroupBy.Invalid
        };

        return grouping != MaterialGroupBy.Invalid;
    }

    private static bool TryParseSortBy(string? value, out MaterialSortBy sorting)
    {
        sorting = MaterialSortBy.Number;
        if (string.IsNullOrWhiteSpace(value))
        {
            return true;
        }

        sorting = value.Trim().ToLowerInvariant() switch
        {
            "number" => MaterialSortBy.Number,
            "name" => MaterialSortBy.Name,
            "agecategory" or "age" => MaterialSortBy.AgeCategory,
            "sizerange" or "size" => MaterialSortBy.SizeRange,
            "materialtype" or "type" => MaterialSortBy.MaterialType,
            "manufacturer" => MaterialSortBy.Manufacturer,
            "countryoforigin" or "origin" => MaterialSortBy.CountryOfOrigin,
            "warehousequantity" or "qty" => MaterialSortBy.WarehouseQuantity,
            "unitsalepricesyp" => MaterialSortBy.UnitSalePriceSyp,
            "unitsalepriceusd" => MaterialSortBy.UnitSalePriceUsd,
            "unitpurchasepriceusd" => MaterialSortBy.UnitPurchasePriceUsd,
            _ => MaterialSortBy.Invalid
        };

        return sorting != MaterialSortBy.Invalid;
    }

    private static bool TryParseSort(
        string value,
        out IReadOnlyCollection<MaterialSortClause> sortClauses)
    {
        var clauses = new List<MaterialSortClause>();
        foreach (var token in value.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
        {
            if (!TryParseSortToken(token, out var clause))
            {
                sortClauses = [];
                return false;
            }

            if (clauses.All(existing => existing.SortBy != clause.SortBy))
            {
                clauses.Add(clause);
            }
        }

        sortClauses = clauses.Count == 0
            ? [new MaterialSortClause(MaterialSortBy.Number, SortDirection.Asc)]
            : clauses;
        return true;
    }

    private static bool TryParseSortToken(string token, out MaterialSortClause clause)
    {
        clause = new MaterialSortClause(MaterialSortBy.Number, SortDirection.Asc);
        var trimmed = token.Trim();
        if (trimmed.Length == 0)
        {
            return false;
        }

        var direction = SortDirection.Asc;
        string sortKey;
        if (trimmed.StartsWith("-", StringComparison.Ordinal))
        {
            direction = SortDirection.Desc;
            sortKey = trimmed[1..];
        }
        else if (trimmed.StartsWith("+", StringComparison.Ordinal))
        {
            sortKey = trimmed[1..];
        }
        else
        {
            sortKey = trimmed;
        }

        var parts = sortKey.Split(':', 2, StringSplitOptions.TrimEntries);
        var sortByPart = parts[0];
        if (string.IsNullOrWhiteSpace(sortByPart))
        {
            return false;
        }

        if (parts.Length == 2)
        {
            var directionPart = parts[1];
            if (string.IsNullOrWhiteSpace(directionPart) || !TryParseSortDirection(directionPart, out direction))
            {
                return false;
            }
        }

        if (!TryParseSortBy(sortByPart, out var sortBy))
        {
            return false;
        }

        clause = new MaterialSortClause(sortBy, direction);
        return true;
    }

    private static bool TryParseSortDirection(string? value, out SortDirection direction)
    {
        direction = SortDirection.Asc;
        if (string.IsNullOrWhiteSpace(value))
        {
            return true;
        }

        direction = value.Trim().ToLowerInvariant() switch
        {
            "asc" => SortDirection.Asc,
            "desc" => SortDirection.Desc,
            _ => SortDirection.Invalid
        };

        return direction != SortDirection.Invalid;
    }

    private static string ToGroupByValue(MaterialGroupBy grouping) =>
        grouping switch
        {
            MaterialGroupBy.AgeCategory => "ageCategory",
            MaterialGroupBy.SizeRange => "sizeRange",
            MaterialGroupBy.MaterialType => "materialType",
            MaterialGroupBy.Manufacturer => "manufacturer",
            MaterialGroupBy.CountryOfOrigin => "countryOfOrigin",
            MaterialGroupBy.Group => "group",
            _ => "none"
        };

    [HttpGet("filter-options")]
    public async Task<ActionResult<MaterialFilterOptionsResponse>> GetFilterOptions(CancellationToken cancellationToken)
    {
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var response = new MaterialFilterOptionsResponse(
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Origin), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Company), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Dim), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Color), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Provenance), cancellationToken),
            await GetGroupsAsync(cancellationToken),
            await GetStoresAsync(cancellationToken),
            new MaterialPriceRangesResponse(
                IsFieldDenied(fieldAccess, "Whole")
                    ? null
                    : await GetPriceRangeAsync(mainDbContext.Materials.Select(material => material.Whole), cancellationToken),
                IsFieldDenied(fieldAccess, "Half")
                    ? null
                    : await GetPriceRangeAsync(mainDbContext.Materials.Select(material => material.Half), cancellationToken),
                IsFieldDenied(fieldAccess, "EndUser")
                    ? null
                    : await GetPriceRangeAsync(mainDbContext.Materials.Select(material => material.EndUser), cancellationToken)));

        return Ok(response);
    }

    [HttpGet("{guid:guid}")]
    public async Task<ActionResult<MaterialResponse>> GetMaterial(
        Guid guid,
        [FromQuery] Guid? storeGuid = null,
        [FromQuery] string? storeGuids = null,
        CancellationToken cancellationToken = default)
    {
        var material = await mainDbContext.Materials
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == guid, cancellationToken);

        if (material is null)
        {
            return NotFound();
        }

        var selectedStoreGuids = ParseStoreGuids(storeGuid, storeGuids);
        var quantityByMaterial = await GetQuantityByMaterialAsync([material], selectedStoreGuids, cancellationToken);
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        return Ok(ToResponse(material, fieldAccess, quantityByMaterial.GetValueOrDefault(material.Guid, material.Qty)));
    }

    private MaterialResponse ToResponse(
        MaterialRecord material,
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        double? warehouseQuantity) =>
        new(
            material.Guid,
            material.Number,
            material.Name,
            material.Code,
            material.LatinName,
            material.BarCode,
            material.Unity,
            material.Unit2,
            material.Unit2Fact,
            material.Unit2FactFlag,
            warehouseQuantity,
            ResolveNumber(fieldAccess, "Whole", material.Whole),
            ResolveNumber(fieldAccess, "Half", material.Half),
            ResolveNumber(fieldAccess, "EndUser", material.EndUser),
            ResolveNumber(fieldAccess, nameof(material.AvgPrice), material.AvgPrice),
            ResolveNumber(fieldAccess, nameof(material.LastPrice), material.LastPrice),
            material.CurrencyVal,
            material.Origin,
            material.Company,
            material.Dim,
            material.Color,
            material.Provenance,
            material.GroupGuid,
            material.PictureGuid,
            material.CurrencyGuid,
            material.Type,
            material.Security,
            material.UseFlag,
            material.IsHidden);

    private async Task<Dictionary<Guid, double?>> GetQuantityByMaterialAsync(
        IReadOnlyCollection<MaterialRecord> materials,
        IReadOnlyCollection<Guid> selectedStoreGuids,
        CancellationToken cancellationToken)
    {
        if (selectedStoreGuids.Count == 0 || materials.Count == 0)
        {
            return [];
        }

        var materialGuids = materials.Select(material => material.Guid).ToArray();

        return await mainDbContext.MaterialInventory
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

    private async Task<IReadOnlyCollection<string>> GetDistinctOptionsAsync(
        IQueryable<string?> values,
        CancellationToken cancellationToken)
    {
        return await values
            .Where(value => value != null && value != string.Empty)
            .Select(value => value!)
            .Distinct()
            .OrderBy(value => value)
            .Take(MaxFilterOptions)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<LookupOptionResponse>> GetGroupsAsync(CancellationToken cancellationToken)
    {
        return await mainDbContext.MaterialGroups
            .AsNoTracking()
            .OrderBy(group => group.Number)
            .ThenBy(group => group.Name)
            .Take(MaxFilterOptions)
            .Select(group => new LookupOptionResponse(group.Guid, group.Code, group.Name, group.LatinName))
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<LookupOptionResponse>> GetStoresAsync(CancellationToken cancellationToken)
    {
        return await mainDbContext.Stores
            .AsNoTracking()
            .Where(store => store.IsActive != false)
            .OrderBy(store => store.Number)
            .ThenBy(store => store.Name)
            .Take(MaxFilterOptions)
            .Select(store => new LookupOptionResponse(store.Guid, store.Code, store.Name, store.LatinName))
            .ToListAsync(cancellationToken);
    }

    private static async Task<PriceRangeResponse?> GetPriceRangeAsync(
        IQueryable<double?> values,
        CancellationToken cancellationToken)
    {
        var nonEmptyValues = values.Where(value => value.HasValue);
        if (!await nonEmptyValues.AnyAsync(cancellationToken))
        {
            return null;
        }

        return new PriceRangeResponse(
            await nonEmptyValues.MinAsync(cancellationToken),
            await nonEmptyValues.MaxAsync(cancellationToken));
    }

    private IQueryable<MaterialRecord> ApplyStoreAndQuantityFilters(
        IQueryable<MaterialRecord> query,
        IReadOnlyCollection<Guid> selectedStoreGuids,
        double? minWarehouseQuantity,
        double? maxWarehouseQuantity,
        bool? isAvailable)
    {
        if (selectedStoreGuids.Count == 0)
        {
            if (isAvailable is true)
            {
                query = query.Where(material => (material.Qty ?? 0) > 0);
            }
            else if (isAvailable is false)
            {
                query = query.Where(material => (material.Qty ?? 0) <= 0);
            }

            if (minWarehouseQuantity is not null)
            {
                query = query.Where(material => (material.Qty ?? 0) >= minWarehouseQuantity.Value);
            }

            if (maxWarehouseQuantity is not null)
            {
                query = query.Where(material => (material.Qty ?? 0) <= maxWarehouseQuantity.Value);
            }

            return query;
        }

        var storeQuantities = mainDbContext.MaterialInventory
            .AsNoTracking()
            .Where(inventory => inventory.MaterialGuid.HasValue)
            .Where(inventory => inventory.StoreGuid.HasValue && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
            .GroupBy(inventory => inventory.MaterialGuid!.Value)
            .Select(group => new
            {
                MaterialGuid = group.Key,
                Quantity = group.Sum(inventory => inventory.Qty ?? 0)
            });

        if (isAvailable is true)
        {
            query = query.Where(material => storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity > 0));
        }
        else if (isAvailable is false)
        {
            query = query.Where(material => !storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity > 0));
        }
        else
        {
            query = query.Where(material => storeQuantities.Any(quantity => quantity.MaterialGuid == material.Guid));
        }

        if (minWarehouseQuantity is not null)
        {
            query = query.Where(material => storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity >= minWarehouseQuantity.Value));
        }

        if (maxWarehouseQuantity is not null)
        {
            query = query.Where(material => storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity <= maxWarehouseQuantity.Value));
        }

        return query;
    }

    private static IQueryable<MaterialRecord> ApplyTextFilters(
        IQueryable<MaterialRecord> query,
        string? countryOfOrigin,
        string? countryOfOrigins,
        string? manufacturer,
        string? manufacturers,
        string? sizeRange,
        string? sizeRanges,
        string? materialType,
        string? materialTypes,
        string? ageCategory,
        string? ageCategories)
    {
        query = ApplyContainsAny(query, material => material.Origin, countryOfOrigin, countryOfOrigins);
        query = ApplyContainsAny(query, material => material.Company, manufacturer, manufacturers);
        query = ApplyContainsAny(query, material => material.Dim, sizeRange, sizeRanges);
        query = ApplyContainsAny(query, material => material.Color, materialType, materialTypes);
        query = ApplyContainsAny(query, material => material.Provenance, ageCategory, ageCategories);

        return query;
    }

    private static IQueryable<MaterialRecord> ApplyContainsAny(
        IQueryable<MaterialRecord> query,
        Expression<Func<MaterialRecord, string?>> selector,
        params string?[] inputs)
    {
        var values = ParseTextValues(inputs);
        if (values.Count == 0)
        {
            return query;
        }

        var parameter = selector.Parameters[0];
        var property = selector.Body;
        var containsMethod = typeof(string).GetMethod(nameof(string.Contains), [typeof(string)])
            ?? throw new InvalidOperationException("string.Contains(string) method was not found.");
        var notNull = Expression.NotEqual(property, Expression.Constant(null, typeof(string)));
        Expression? body = null;

        foreach (var value in values)
        {
            var contains = Expression.Call(property, containsMethod, Expression.Constant(value));
            var clause = Expression.AndAlso(notNull, contains);
            body = body is null ? clause : Expression.OrElse(body, clause);
        }

        return query.Where(Expression.Lambda<Func<MaterialRecord, bool>>(body!, parameter));
    }

    private static IQueryable<MaterialRecord> ApplyGroupFilter(
        IQueryable<MaterialRecord> query,
        IReadOnlyCollection<Guid> selectedGroupGuids)
    {
        return selectedGroupGuids.Count == 0
            ? query
            : query.Where(material => material.GroupGuid.HasValue && selectedGroupGuids.Contains(material.GroupGuid.Value));
    }

    private ActionResult? ValidatePriceFilterAccess(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        double? minUnitSalePriceSyp,
        double? maxUnitSalePriceSyp,
        double? minUnitSalePriceUsd,
        double? maxUnitSalePriceUsd,
        double? minUnitPurchasePriceUsd,
        double? maxUnitPurchasePriceUsd)
    {
        if ((minUnitSalePriceSyp is not null || maxUnitSalePriceSyp is not null) &&
            IsFieldDenied(fieldAccess, "Whole"))
        {
            return Forbid();
        }

        if ((minUnitSalePriceUsd is not null || maxUnitSalePriceUsd is not null) &&
            IsFieldDenied(fieldAccess, "Half"))
        {
            return Forbid();
        }

        if ((minUnitPurchasePriceUsd is not null || maxUnitPurchasePriceUsd is not null) &&
            IsFieldDenied(fieldAccess, "EndUser"))
        {
            return Forbid();
        }

        return null;
    }

    private IQueryable<MaterialRecord> ApplyPriceFilters(
        IQueryable<MaterialRecord> query,
        double? minUnitSalePriceSyp,
        double? maxUnitSalePriceSyp,
        double? minUnitSalePriceUsd,
        double? maxUnitSalePriceUsd,
        double? minUnitPurchasePriceUsd,
        double? maxUnitPurchasePriceUsd)
    {
        if (minUnitSalePriceSyp is not null)
        {
            query = query.Where(material => material.Whole >= minUnitSalePriceSyp.Value);
        }

        if (maxUnitSalePriceSyp is not null)
        {
            query = query.Where(material => material.Whole <= maxUnitSalePriceSyp.Value);
        }

        if (minUnitSalePriceUsd is not null)
        {
            query = query.Where(material => material.Half >= minUnitSalePriceUsd.Value);
        }

        if (maxUnitSalePriceUsd is not null)
        {
            query = query.Where(material => material.Half <= maxUnitSalePriceUsd.Value);
        }

        if (minUnitPurchasePriceUsd is not null)
        {
            query = query.Where(material => material.EndUser >= minUnitPurchasePriceUsd.Value);
        }

        if (maxUnitPurchasePriceUsd is not null)
        {
            query = query.Where(material => material.EndUser <= maxUnitPurchasePriceUsd.Value);
        }

        return query;
    }

    private static bool IsFieldDenied(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        string fieldName)
    {
        return fieldAccess.TryGetValue(fieldName, out var decision) && decision.ReadMode == FieldAccessMode.Deny;
    }

    private static IReadOnlyCollection<Guid> ParseStoreGuids(Guid? storeGuid, string? storeGuids)
    {
        return ParseGuids(storeGuid, storeGuids);
    }

    private static IReadOnlyCollection<Guid> ParseGuids(Guid? singleGuid, string? commaSeparatedGuids)
    {
        var parsed = new HashSet<Guid>();
        if (singleGuid is not null)
        {
            parsed.Add(singleGuid.Value);
        }

        if (!string.IsNullOrWhiteSpace(commaSeparatedGuids))
        {
            foreach (var value in commaSeparatedGuids.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
            {
                if (Guid.TryParse(value, out var parsedGuid))
                {
                    parsed.Add(parsedGuid);
                }
            }
        }

        return parsed.ToArray();
    }

    private static IReadOnlyCollection<string> ParseTextValues(params string?[] inputs)
    {
        return inputs
            .Where(input => !string.IsNullOrWhiteSpace(input))
            .SelectMany(input => input!.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
            .Where(value => !string.IsNullOrWhiteSpace(value))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
    }

    private object? ResolveNumber(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        string fieldName,
        double? value)
    {
        if (value is null || !fieldAccess.TryGetValue(fieldName, out var decision))
        {
            return value;
        }

        return decision.ReadMode switch
        {
            FieldAccessMode.Deny => null,
            FieldAccessMode.Mask => fieldMasker.Mask(value.Value, decision.MaskingStrategy),
            _ => value
        };
    }

    private sealed record MaterialGroupingCount(string Key, string? DisplayName, int TotalCount);

    private sealed record MaterialSortClause(MaterialSortBy SortBy, SortDirection Direction);

    private enum MaterialGroupBy
    {
        Invalid = -1,
        None = 0,
        AgeCategory = 1,
        SizeRange = 2,
        MaterialType = 3,
        Manufacturer = 4,
        CountryOfOrigin = 5,
        Group = 6
    }

    private enum MaterialSortBy
    {
        Invalid = -1,
        Number = 0,
        Name = 1,
        AgeCategory = 2,
        SizeRange = 3,
        MaterialType = 4,
        Manufacturer = 5,
        CountryOfOrigin = 6,
        WarehouseQuantity = 7,
        UnitSalePriceSyp = 8,
        UnitSalePriceUsd = 9,
        UnitPurchasePriceUsd = 10
    }

    private enum SortDirection
    {
        Invalid = -1,
        Asc = 0,
        Desc = 1
    }
}

