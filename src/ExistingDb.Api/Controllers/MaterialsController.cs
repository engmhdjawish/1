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
    MaterialResultFiltersService materialResultFiltersService,
    MaterialCatalogService materialCatalogService,
    MaterialFilterOptionsService materialFilterOptionsService) : ControllerBase
{
    private const string ResourceCode = "materials";

    [HttpGet]
    public async Task<ActionResult<MaterialPagedResponse>> GetMaterials(
        [FromQuery] MaterialListQuery query,
        CancellationToken cancellationToken = default)
    {
        var filters = query.ToFilters();
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var priceFilterAccessResult = ValidatePriceFilterAccess(fieldAccess, filters);
        if (priceFilterAccessResult is not null)
        {
            return priceFilterAccessResult;
        }

        if (!TryParseGroupBy(query.GroupBy, out var grouping))
        {
            return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
            {
                ["groupBy"] = ["Invalid value. Supported values: ageCategory, sizeRange, materialType, manufacturer, countryOfOrigin, group."]
            }));
        }

        if (!TryParseSort(query.Sort ?? string.Empty, out var sortClauses))
        {
            return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
            {
                ["sort"] = ["Invalid value. Use comma-separated fields, e.g. ageCategory:asc,materialType:asc,-manufacturer."]
            }));
        }

        var pageResult = await materialCatalogService.QueryAsync(
            filters,
            source => ApplyOrdering(source, grouping, sortClauses),
            query.IncludeTotalCount,
            query.Page,
            query.PageSize,
            cancellationToken);

        var items = MapItems(pageResult, filters.StoreGuids, fieldAccess);

        MaterialAppliedFiltersResponse? appliedFilters = null;
        MaterialResultFiltersResponse? resultFilters = null;
        if (query.IncludeResultFilters)
        {
            appliedFilters = ToAppliedFilters(filters);
            resultFilters = await materialResultFiltersService.BuildAsync(filters, filters.Search, cancellationToken);
        }

        MaterialGroupingResponse? groupingResponse = null;
        if (grouping != MaterialGroupBy.None)
        {
            var filteredQuery = materialQueryBuilder.Build(filters);
            filteredQuery = await materialQueryBuilder.ApplySearchFilterAsync(filteredQuery, filters.Search, cancellationToken);
            groupingResponse = await BuildGroupingResponseAsync(
                grouping,
                filteredQuery,
                pageResult.Materials,
                items,
                cancellationToken);
        }

        return Ok(new MaterialPagedResponse(
            items,
            pageResult.Page,
            pageResult.PageSize,
            pageResult.TotalCount,
            appliedFilters,
            resultFilters,
            groupingResponse));
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
        CancellationToken cancellationToken) =>
        grouping switch
        {
            MaterialGroupBy.AgeCategory => await GetStringFieldGroupingCountsAsync(
                query, material => material.Provenance, cancellationToken),
            MaterialGroupBy.SizeRange => await GetStringFieldGroupingCountsAsync(
                query, material => material.Dim, cancellationToken),
            MaterialGroupBy.MaterialType => await GetStringFieldGroupingCountsAsync(
                query, material => material.Color, cancellationToken),
            MaterialGroupBy.Manufacturer => await GetStringFieldGroupingCountsAsync(
                query, material => material.Company, cancellationToken),
            MaterialGroupBy.CountryOfOrigin => await GetStringFieldGroupingCountsAsync(
                query, material => material.Origin, cancellationToken),
            MaterialGroupBy.Group => await GetGroupGuidCountsAsync(query, cancellationToken),
            _ => []
        };

    private static async Task<IReadOnlyCollection<MaterialGroupingCount>> GetStringFieldGroupingCountsAsync(
        IQueryable<MaterialRecord> query,
        Expression<Func<MaterialRecord, string?>> fieldSelector,
        CancellationToken cancellationToken)
    {
        var parameter = fieldSelector.Parameters[0];
        var property = fieldSelector.Body;
        var notNull = Expression.NotEqual(property, Expression.Constant(null, typeof(string)));
        var notEmpty = Expression.NotEqual(property, Expression.Constant(string.Empty, typeof(string)));
        var hasValue = Expression.Lambda<Func<MaterialRecord, bool>>(
            Expression.AndAlso(notNull, notEmpty),
            parameter);

        var rows = await query
            .Where(hasValue)
            .GroupBy(fieldSelector)
            .Select(group => new { Key = group.Key!, Count = group.Count() })
            .OrderBy(group => group.Key)
            .ToListAsync(cancellationToken);

        return rows
            .Select(group => new MaterialGroupingCount(group.Key, group.Key, group.Count))
            .ToList();
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

    [HttpGet("result-filters")]
    public async Task<ActionResult<MaterialResultFiltersResponse>> GetResultFilters(
        [FromQuery] MaterialListQuery query,
        CancellationToken cancellationToken = default)
    {
        var filters = query.ToFilters();
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var priceFilterAccessResult = ValidatePriceFilterAccess(fieldAccess, filters);
        if (priceFilterAccessResult is not null)
        {
            return priceFilterAccessResult;
        }

        var resultFilters = await materialResultFiltersService.BuildAsync(filters, filters.Search, cancellationToken);

        return Ok(resultFilters);
    }

    [HttpGet("filter-options")]
    public async Task<ActionResult<MaterialFilterOptionsResponse>> GetFilterOptions(CancellationToken cancellationToken)
    {
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var response = await materialFilterOptionsService.GetAsync(cancellationToken);
        var prices = response.PriceRanges;

        return Ok(response with
        {
            PriceRanges = new MaterialPriceRangesResponse(
                IsFieldDenied(fieldAccess, "Whole") ? null : prices.UnitSalePriceSyp,
                IsFieldDenied(fieldAccess, "Half") ? null : prices.UnitSalePriceUsd,
                IsFieldDenied(fieldAccess, "EndUser") ? null : prices.UnitPurchasePriceUsd)
        });
    }

    /// <summary>
    /// Several home-page or offer slices in one round trip. Each slice skips the
    /// total-count scan unless the caller asks for it.
    /// </summary>
    [HttpPost("batch")]
    public async Task<ActionResult<MaterialBatchResponse>> GetMaterialsBatch(
        [FromBody] MaterialBatchRequest request,
        CancellationToken cancellationToken = default)
    {
        if (request.Queries.Count == 0)
        {
            return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
            {
                ["queries"] = ["At least one query is required."]
            }));
        }

        if (request.Queries.Count > MaterialCatalogService.MaxBatchQueries)
        {
            return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
            {
                ["queries"] = [$"A batch accepts at most {MaterialCatalogService.MaxBatchQueries} queries."]
            }));
        }

        var keys = new HashSet<string>(StringComparer.Ordinal);
        foreach (var query in request.Queries)
        {
            var key = query.Key.Trim();
            if (key.Length == 0 || !keys.Add(key))
            {
                return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
                {
                    ["queries"] = ["Each query needs a unique non-empty key."]
                }));
            }
        }

        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var catalogRequests = new List<MaterialCatalogRequest>(request.Queries.Count);
        foreach (var query in request.Queries)
        {
            var filters = query.ToFilters();
            var priceFilterAccessResult = ValidatePriceFilterAccess(fieldAccess, filters);
            if (priceFilterAccessResult is not null)
            {
                return priceFilterAccessResult;
            }

            if (!TryParseSort(query.Sort ?? string.Empty, out var sortClauses))
            {
                return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
                {
                    ["sort"] = [$"Invalid sort for query '{query.Key}'."]
                }));
            }

            catalogRequests.Add(new MaterialCatalogRequest
            {
                Key = query.Key.Trim(),
                Filters = filters,
                Order = source => ApplyOrdering(source, MaterialGroupBy.None, sortClauses),
                IncludeTotalCount = query.IncludeTotalCount,
                Page = query.Page,
                PageSize = query.PageSize
            });
        }

        var pages = await materialCatalogService.QueryManyAsync(catalogRequests, cancellationToken);
        var results = new Dictionary<string, MaterialPagedResponse>(StringComparer.Ordinal);
        foreach (var catalogRequest in catalogRequests)
        {
            var pageResult = pages[catalogRequest.Key];
            results[catalogRequest.Key] = new MaterialPagedResponse(
                MapItems(pageResult, catalogRequest.Filters.StoreGuids, fieldAccess),
                pageResult.Page,
                pageResult.PageSize,
                pageResult.TotalCount);
        }

        return Ok(new MaterialBatchResponse(results));
    }

    /// <summary>
    /// Exact code or barcode match. Used by the price checker instead of a catalog search.
    /// </summary>
    [HttpGet("lookup")]
    public async Task<ActionResult<MaterialLookupResponse>> LookupMaterial(
        [FromQuery] string? barcode = null,
        [FromQuery] string? code = null,
        [FromQuery] MaterialListQuery? scope = null,
        CancellationToken cancellationToken = default)
    {
        var term = !string.IsNullOrWhiteSpace(barcode) ? barcode : code;
        if (string.IsNullOrWhiteSpace(term))
        {
            return BadRequest(new ValidationProblemDetails(new Dictionary<string, string[]>
            {
                ["barcode"] = ["barcode or code is required."]
            }));
        }

        var filters = (scope ?? new MaterialListQuery()).ToFilters();
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var pageResult = await materialCatalogService.LookupExactAsync(term, filters, cancellationToken);
        var item = MapItems(pageResult, filters.StoreGuids, fieldAccess).FirstOrDefault();

        return Ok(new MaterialLookupResponse(item));
    }

    /// <summary>
    /// Short search list for the store box. No total-count scan and no facet queries.
    /// </summary>
    [HttpGet("suggest")]
    public async Task<ActionResult<MaterialSuggestResponse>> SuggestMaterials(
        [FromQuery] string? q = null,
        [FromQuery] int limit = 8,
        [FromQuery] MaterialListQuery? scope = null,
        CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(q))
        {
            return Ok(new MaterialSuggestResponse([]));
        }

        var filters = (scope ?? new MaterialListQuery()).ToFilters();
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var priceFilterAccessResult = ValidatePriceFilterAccess(fieldAccess, filters);
        if (priceFilterAccessResult is not null)
        {
            return priceFilterAccessResult;
        }

        var pageResult = await materialCatalogService.SuggestAsync(q, limit, filters, cancellationToken);

        return Ok(new MaterialSuggestResponse(MapItems(pageResult, filters.StoreGuids, fieldAccess)));
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
        var warehouseQuantity = selectedStoreGuids.Count > 0
            ? quantityByMaterial.GetValueOrDefault(material.Guid, 0)
            : quantityByMaterial.GetValueOrDefault(material.Guid, material.Qty);

        if (selectedStoreGuids.Count > 0 && (warehouseQuantity ?? 0) <= 0)
        {
            return NotFound();
        }

        return Ok(ToResponse(material, fieldAccess, warehouseQuantity));
    }

    [HttpGet("{guid:guid}/store-quantities")]
    public async Task<ActionResult<IReadOnlyList<MaterialStoreQuantityResponse>>> GetMaterialStoreQuantities(
        Guid guid,
        CancellationToken cancellationToken = default)
    {
        var exists = await mainDbContext.Materials
            .AsNoTracking()
            .AnyAsync(record => record.Guid == guid, cancellationToken);
        if (!exists)
        {
            return NotFound();
        }

        var quantities = await mainDbContext.MaterialInventory
            .AsNoTracking()
            .Where(inventory => inventory.MaterialGuid == guid && inventory.StoreGuid.HasValue)
            .GroupBy(inventory => inventory.StoreGuid!.Value)
            .Select(group => new
            {
                StoreGuid = group.Key,
                Quantity = group.Sum(inventory => inventory.Qty ?? 0)
            })
            .ToListAsync(cancellationToken);

        if (quantities.Count == 0)
        {
            return Ok(Array.Empty<MaterialStoreQuantityResponse>());
        }

        var storeGuids = quantities.Select(row => row.StoreGuid).ToArray();
        var stores = await mainDbContext.Stores
            .AsNoTracking()
            .Where(store => storeGuids.Contains(store.Guid))
            .Select(store => new { store.Guid, store.Name })
            .ToDictionaryAsync(store => store.Guid, store => store.Name, cancellationToken);

        var response = quantities
            .OrderByDescending(row => row.Quantity)
            .ThenBy(row => stores.GetValueOrDefault(row.StoreGuid) ?? row.StoreGuid.ToString())
            .Select(row => new MaterialStoreQuantityResponse(
                row.StoreGuid,
                stores.GetValueOrDefault(row.StoreGuid),
                row.Quantity))
            .ToArray();

        return Ok(response);
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
            MaterialPictureGuid.Normalize(material.PictureGuid),
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

    private MaterialResponse[] MapItems(
        MaterialPageResult pageResult,
        IReadOnlyCollection<Guid> selectedStoreGuids,
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess) =>
        pageResult.Materials
            .Select(material => ToResponse(
                material,
                fieldAccess,
                selectedStoreGuids.Count > 0
                    ? pageResult.QuantityByMaterial.GetValueOrDefault(material.Guid, 0)
                    : pageResult.QuantityByMaterial.GetValueOrDefault(material.Guid, material.Qty)))
            .ToArray();

    private ActionResult? ValidatePriceFilterAccess(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        MaterialListFilters filters)
    {
        if ((filters.MinUnitSalePriceSyp is not null || filters.MaxUnitSalePriceSyp is not null) &&
            IsFieldDenied(fieldAccess, "Whole"))
        {
            return Forbid();
        }

        if ((filters.MinUnitSalePriceUsd is not null || filters.MaxUnitSalePriceUsd is not null) &&
            IsFieldDenied(fieldAccess, "Half"))
        {
            return Forbid();
        }

        if ((filters.MinUnitPurchasePriceUsd is not null || filters.MaxUnitPurchasePriceUsd is not null) &&
            IsFieldDenied(fieldAccess, "EndUser"))
        {
            return Forbid();
        }

        return null;
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
            var normalized = commaSeparatedGuids
                .Replace("%2C", ",", StringComparison.OrdinalIgnoreCase)
                .Replace("%2c", ",", StringComparison.OrdinalIgnoreCase);

            foreach (var value in normalized.Split([',', ';'], StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
            {
                if (Guid.TryParse(value, out var parsedGuid))
                {
                    parsed.Add(parsedGuid);
                }
            }
        }

        return parsed.ToArray();
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

