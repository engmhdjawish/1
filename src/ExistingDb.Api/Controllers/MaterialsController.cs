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
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var query = mainDbContext.Materials.AsNoTracking();
        if (!string.IsNullOrWhiteSpace(search))
        {
            var term = search.Trim();
            query = query.Where(material =>
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

        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var response = materials.Select(material => ToResponse(material, fieldAccess)).ToArray();

        return Ok(new PagedResponse<MaterialResponse>(response, page, pageSize, totalCount));
    }

    [HttpGet("{guid:guid}")]
    public async Task<ActionResult<MaterialResponse>> GetMaterial(Guid guid, CancellationToken cancellationToken)
    {
        var material = await mainDbContext.Materials
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == guid, cancellationToken);

        if (material is null)
        {
            return NotFound();
        }

        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        return Ok(ToResponse(material, fieldAccess));
    }

    private MaterialResponse ToResponse(
        MaterialRecord material,
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess) =>
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
            material.Qty,
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

