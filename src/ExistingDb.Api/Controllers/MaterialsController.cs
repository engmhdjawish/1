using System.Linq.Expressions;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
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
    IFieldMasker fieldMasker) : ControllerBase
{
    private const string ResourceCode = "materials";
    private const int MaxFilterOptions = 500;

    [HttpGet]
    public async Task<ActionResult<PagedResponse<MaterialResponse>>> GetMaterials(
        [FromQuery] string? search = null,
        [FromQuery] string? code = null,
        [FromQuery] Guid? storeGuid = null,
        [FromQuery] string? storeGuids = null,
        [FromQuery] string? quantityMode = null,
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
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var selectedStoreGuids = ParseStoreGuids(storeGuid, storeGuids);
        if (!TryParseQuantityMode(quantityMode, out var parsedQuantityMode))
        {
            return BadRequest(new
            {
                message = "quantityMode must be either 'total' or 'detailed'.",
                quantityMode
            });
        }

        var canReadInventory = await permissionService.HasPermissionAsync(User, "inventory.read", cancellationToken);
        if ((selectedStoreGuids.Count > 0 || parsedQuantityMode == MaterialQuantityMode.Detailed) && !canReadInventory)
        {
            return Forbid();
        }

        var selectedGroupGuids = ParseGuids(groupGuid, groupGuids);
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var priceFilterAccessResult = ValidatePriceFilterAccess(
            fieldAccess,
            minUnitSalePriceSyp,
            maxUnitSalePriceSyp,
            minUnitSalePriceUsd,
            maxUnitSalePriceUsd,
            minUnitPurchasePriceUsd,
            maxUnitPurchasePriceUsd);

        if (priceFilterAccessResult is not null)
        {
            return priceFilterAccessResult;
        }

        var query = mainDbContext.Materials.AsNoTracking();

        query = ApplyStoreAndQuantityFilters(
            query,
            selectedStoreGuids,
            minWarehouseQuantity,
            maxWarehouseQuantity,
            isAvailable);

        query = ApplyTextFilters(
            query,
            countryOfOrigin,
            countryOfOrigins,
            manufacturer,
            manufacturers,
            sizeRange,
            sizeRanges,
            materialType,
            materialTypes,
            ageCategory,
            ageCategories);
        query = ApplyGroupFilter(query, selectedGroupGuids);
        query = ApplyPriceFilters(
            query,
            minUnitSalePriceSyp,
            maxUnitSalePriceSyp,
            minUnitSalePriceUsd,
            maxUnitSalePriceUsd,
            minUnitPurchasePriceUsd,
            maxUnitPurchasePriceUsd);

        if (!string.IsNullOrWhiteSpace(code))
        {
            var normalizedCode = code.Trim();
            query = query.Where(material => material.Code == normalizedCode);
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var term = search.Trim();
            var exactCodeExists = await mainDbContext.Materials
                .AsNoTracking()
                .AnyAsync(material => material.Code == term, cancellationToken);

            query = exactCodeExists
                ? query.Where(material => material.Code == term)
                : query.Where(material =>
                    (material.Name != null && material.Name.Contains(term)) ||
                    (material.LatinName != null && material.LatinName.Contains(term)) ||
                    (material.Code != null && material.Code.Contains(term)) ||
                    (material.BarCode != null && material.BarCode.Contains(term)) ||
                    (material.BarCode2 != null && material.BarCode2.Contains(term)) ||
                    (material.BarCode3 != null && material.BarCode3.Contains(term)));
        }

        var totalCount = await query.CountAsync(cancellationToken);
        var materials = await query
            .OrderBy(material => material.Number)
            .ThenBy(material => material.Name)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var groupLookup = await GetGroupNameByGuidAsync(materials, cancellationToken);
        var imageLookup = await GetImageTitleByGuidAsync(materials, cancellationToken);
        var quantityByMaterial = await GetQuantityByMaterialAsync(materials, selectedStoreGuids, cancellationToken);
        var storeQuantitiesByMaterial = parsedQuantityMode == MaterialQuantityMode.Detailed
            ? await GetStoreQuantitiesByMaterialAsync(materials, selectedStoreGuids, cancellationToken)
            : new Dictionary<Guid, IReadOnlyCollection<MaterialStoreQuantityResponse>>();
        var response = materials
            .Select(material => ToResponse(
                material,
                fieldAccess,
                quantityByMaterial.GetValueOrDefault(material.Guid, material.Qty),
                groupLookup.GetValueOrDefault(material.GroupGuid ?? Guid.Empty),
                imageLookup.GetValueOrDefault(material.PictureGuid ?? Guid.Empty),
                storeQuantitiesByMaterial.GetValueOrDefault(material.Guid)))
            .ToArray();

        return Ok(new PagedResponse<MaterialResponse>(response, page, pageSize, totalCount));
    }

    [HttpGet("stores")]
    public async Task<ActionResult<IReadOnlyCollection<MaterialStoreOptionResponse>>> GetStores(CancellationToken cancellationToken = default)
    {
        var canReadInventory = await permissionService.HasPermissionAsync(User, "inventory.read", cancellationToken);
        if (!canReadInventory)
        {
            return Forbid();
        }

        var stores = await mainDbContext.Stores
            .AsNoTracking()
            .Where(store => store.IsActive != false)
            .OrderBy(store => store.Number)
            .ThenBy(store => store.Name)
            .Take(MaxFilterOptions)
            .Select(store => new MaterialStoreOptionResponse(
                store.Guid,
                store.Number,
                store.Code,
                store.Name))
            .ToListAsync(cancellationToken);
        return Ok(stores);
    }

    [HttpGet("filter-options")]
    public async Task<ActionResult<MaterialFilterOptionsResponse>> GetFilterOptions(CancellationToken cancellationToken)
    {
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var canReadInventory = await permissionService.HasPermissionAsync(User, "inventory.read", cancellationToken);
        var response = new MaterialFilterOptionsResponse(
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Origin), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Company), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Dim), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Color), cancellationToken),
            await GetDistinctOptionsAsync(mainDbContext.Materials.Select(material => material.Provenance), cancellationToken),
            await GetGroupsAsync(cancellationToken),
            canReadInventory ? await GetStoresAsync(cancellationToken) : [],
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
        [FromQuery] string? quantityMode = null,
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
        if (!TryParseQuantityMode(quantityMode, out var parsedQuantityMode))
        {
            return BadRequest(new
            {
                message = "quantityMode must be either 'total' or 'detailed'.",
                quantityMode
            });
        }

        var canReadInventory = await permissionService.HasPermissionAsync(User, "inventory.read", cancellationToken);
        if ((selectedStoreGuids.Count > 0 || parsedQuantityMode == MaterialQuantityMode.Detailed) && !canReadInventory)
        {
            return Forbid();
        }

        var quantityByMaterial = await GetQuantityByMaterialAsync([material], selectedStoreGuids, cancellationToken);
        var groupLookup = await GetGroupNameByGuidAsync([material], cancellationToken);
        var imageLookup = await GetImageTitleByGuidAsync([material], cancellationToken);
        var storeQuantitiesByMaterial = parsedQuantityMode == MaterialQuantityMode.Detailed
            ? await GetStoreQuantitiesByMaterialAsync([material], selectedStoreGuids, cancellationToken)
            : new Dictionary<Guid, IReadOnlyCollection<MaterialStoreQuantityResponse>>();
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        return Ok(ToResponse(
            material,
            fieldAccess,
            quantityByMaterial.GetValueOrDefault(material.Guid, material.Qty),
            groupLookup.GetValueOrDefault(material.GroupGuid ?? Guid.Empty),
            imageLookup.GetValueOrDefault(material.PictureGuid ?? Guid.Empty),
            storeQuantitiesByMaterial.GetValueOrDefault(material.Guid)));
    }

    private MaterialResponse ToResponse(
        MaterialRecord material,
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        double? warehouseQuantity,
        string? groupName,
        string? imageTitle,
        IReadOnlyCollection<MaterialStoreQuantityResponse>? storeQuantities) =>
        new(
            material.Guid,
            material.Name,
            material.Code,
            material.Unity,
            material.Unit2,
            material.Unit2Fact,
            warehouseQuantity,
            storeQuantities,
            ResolveNumber(fieldAccess, "Whole", material.Whole),
            ResolveNumber(fieldAccess, "Half", material.Half),
            ResolveNumber(fieldAccess, "EndUser", material.EndUser),
            material.CurrencyVal,
            material.Origin,
            material.Company,
            material.Dim,
            material.Color,
            material.Provenance,
            material.GroupGuid,
            groupName,
            material.PictureGuid,
            imageTitle,
            material.CurrencyGuid,
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

    private async Task<Dictionary<Guid, string?>> GetGroupNameByGuidAsync(
        IReadOnlyCollection<MaterialRecord> materials,
        CancellationToken cancellationToken)
    {
        var groupGuids = materials
            .Where(material => material.GroupGuid.HasValue && material.GroupGuid.Value != Guid.Empty)
            .Select(material => material.GroupGuid!.Value)
            .Distinct()
            .ToArray();
        if (groupGuids.Length == 0)
        {
            return [];
        }

        return await mainDbContext.MaterialGroups
            .AsNoTracking()
            .Where(group => groupGuids.Contains(group.Guid))
            .ToDictionaryAsync(group => group.Guid, group => group.Name, cancellationToken);
    }

    private async Task<Dictionary<Guid, string?>> GetImageTitleByGuidAsync(
        IReadOnlyCollection<MaterialRecord> materials,
        CancellationToken cancellationToken)
    {
        var imageGuids = materials
            .Where(material => material.PictureGuid.HasValue && material.PictureGuid.Value != Guid.Empty)
            .Select(material => material.PictureGuid!.Value)
            .Distinct()
            .ToArray();
        if (imageGuids.Length == 0)
        {
            return [];
        }

        return await mainDbContext.MaterialImages
            .AsNoTracking()
            .Where(image => imageGuids.Contains(image.Guid))
            .ToDictionaryAsync(image => image.Guid, image => image.Name, cancellationToken);
    }

    private async Task<Dictionary<Guid, IReadOnlyCollection<MaterialStoreQuantityResponse>>> GetStoreQuantitiesByMaterialAsync(
        IReadOnlyCollection<MaterialRecord> materials,
        IReadOnlyCollection<Guid> selectedStoreGuids,
        CancellationToken cancellationToken)
    {
        if (materials.Count == 0)
        {
            return [];
        }

        var materialGuids = materials.Select(material => material.Guid).ToArray();
        var inventories = await mainDbContext.MaterialInventory
            .AsNoTracking()
            .Where(inventory => inventory.MaterialGuid.HasValue && materialGuids.Contains(inventory.MaterialGuid.Value))
            .Where(inventory => inventory.StoreGuid.HasValue)
            .Where(inventory => selectedStoreGuids.Count == 0 || selectedStoreGuids.Contains(inventory.StoreGuid!.Value))
            .ToListAsync(cancellationToken);
        if (inventories.Count == 0)
        {
            return [];
        }

        var storeGuids = inventories
            .Where(inventory => inventory.StoreGuid.HasValue && inventory.StoreGuid.Value != Guid.Empty)
            .Select(inventory => inventory.StoreGuid!.Value)
            .Distinct()
            .ToArray();
        var storeLookup = await mainDbContext.Stores
            .AsNoTracking()
            .Where(store => storeGuids.Contains(store.Guid))
            .ToDictionaryAsync(store => store.Guid, store => store.Name, cancellationToken);

        var grouped = inventories
            .Where(inventory => inventory.MaterialGuid.HasValue && inventory.StoreGuid.HasValue && inventory.StoreGuid.Value != Guid.Empty)
            .GroupBy(inventory => new
            {
                MaterialGuid = inventory.MaterialGuid!.Value,
                StoreGuid = inventory.StoreGuid!.Value
            })
            .Select(group => new
            {
                group.Key.MaterialGuid,
                group.Key.StoreGuid,
                Quantity = group.Sum(item => item.Qty ?? 0d)
            })
            .GroupBy(row => row.MaterialGuid)
            .ToDictionary(
                group => group.Key,
                group => (IReadOnlyCollection<MaterialStoreQuantityResponse>)group
                    .OrderBy(row => storeLookup.GetValueOrDefault(row.StoreGuid))
                    .Select(row => new MaterialStoreQuantityResponse(
                        row.StoreGuid,
                        storeLookup.GetValueOrDefault(row.StoreGuid),
                        row.Quantity))
                    .ToArray());

        return grouped;
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

    private static bool TryParseQuantityMode(string? rawValue, out MaterialQuantityMode mode)
    {
        if (string.IsNullOrWhiteSpace(rawValue))
        {
            mode = MaterialQuantityMode.Total;
            return true;
        }

        var normalized = rawValue.Trim().ToLowerInvariant();
        if (normalized is "total" or "sum" or "summary")
        {
            mode = MaterialQuantityMode.Total;
            return true;
        }

        if (normalized is "detailed" or "detail" or "stores")
        {
            mode = MaterialQuantityMode.Detailed;
            return true;
        }

        mode = MaterialQuantityMode.Total;
        return false;
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

    private enum MaterialQuantityMode
    {
        Total = 0,
        Detailed = 1
    }
}

