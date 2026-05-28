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

    [HttpGet]
    public async Task<ActionResult<PagedResponse<MaterialResponse>>> GetMaterials(
        [FromQuery] string? search = null,
        [FromQuery] Guid? storeGuid = null,
        [FromQuery] string? storeGuids = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var selectedStoreGuids = ParseStoreGuids(storeGuid, storeGuids);
        var query = mainDbContext.Materials.AsNoTracking();

        if (selectedStoreGuids.Count > 0)
        {
            query = query.Where(material => mainDbContext.MaterialInventory.Any(inventory =>
                inventory.MaterialGuid == material.Guid &&
                inventory.StoreGuid.HasValue &&
                selectedStoreGuids.Contains(inventory.StoreGuid.Value)));
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var term = search.Trim();
            var exactCodeQuery = query.Where(material => material.Code == term);

            query = await exactCodeQuery.AnyAsync(cancellationToken)
                ? exactCodeQuery
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

        var quantityByMaterial = await GetQuantityByMaterialAsync(materials, selectedStoreGuids, cancellationToken);
        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var response = materials
            .Select(material => ToResponse(material, fieldAccess, quantityByMaterial.GetValueOrDefault(material.Guid, material.Qty)))
            .ToArray();

        return Ok(new PagedResponse<MaterialResponse>(response, page, pageSize, totalCount));
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

    private static IReadOnlyCollection<Guid> ParseStoreGuids(Guid? storeGuid, string? storeGuids)
    {
        var parsed = new HashSet<Guid>();
        if (storeGuid is not null)
        {
            parsed.Add(storeGuid.Value);
        }

        if (!string.IsNullOrWhiteSpace(storeGuids))
        {
            foreach (var value in storeGuids.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
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
}

