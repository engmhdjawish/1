using System.Security.Claims;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Contracts.Images;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using ExistingDb.Api.Images;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/material-images")]
public sealed class MaterialImagesController(
    ApiManagementDbContext apiDbContext,
    MainDbContext mainDbContext,
    IImageSettingsService imageSettingsService,
    IImageStorageService imageStorageService) : ControllerBase
{
    [HttpGet("settings")]
    [RequirePermission("materials.update")]
    public async Task<ActionResult<ImageSettingsResponse>> GetSettings(CancellationToken cancellationToken)
    {
        var settings = await imageSettingsService.GetAsync(cancellationToken);
        return Ok(new ImageSettingsResponse(settings.ImagesDirectory, settings.ThumbnailsDirectory));
    }

    [HttpPut("settings")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> UpdateSettings(ImageSettingsRequest request, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(request.ImagesDirectory) || string.IsNullOrWhiteSpace(request.ThumbnailsDirectory))
        {
            return BadRequest(new { message = "Image and thumbnail directories are required." });
        }

        await imageSettingsService.UpdateAsync(
            new ImageStorageSettings(request.ImagesDirectory.Trim(), request.ThumbnailsDirectory.Trim()),
            cancellationToken);

        return NoContent();
    }

    [HttpGet]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<PagedResponse<MaterialImageResponse>>> GetImages(
        [FromQuery] bool? linked = null,
        [FromQuery] Guid? materialGuid = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var query = apiDbContext.MaterialImages
            .Include(image => image.MaterialLinks)
            .AsNoTracking();

        if (materialGuid is not null)
        {
            query = query.Where(image => image.MaterialLinks.Any(link => link.MaterialGuid == materialGuid.Value));
        }

        if (linked is true)
        {
            query = query.Where(image => image.MaterialLinks.Any());
        }
        else if (linked is false)
        {
            query = query.Where(image => !image.MaterialLinks.Any());
        }

        var totalCount = await query.CountAsync(cancellationToken);
        var images = await query
            .OrderByDescending(image => image.CreatedAt)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        return Ok(new PagedResponse<MaterialImageResponse>(
            images.Select(ToResponse).ToArray(),
            page,
            pageSize,
            totalCount));
    }

    [HttpGet("{id:guid}")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<MaterialImageResponse>> GetImage(Guid id, CancellationToken cancellationToken)
    {
        var image = await apiDbContext.MaterialImages
            .Include(item => item.MaterialLinks)
            .AsNoTracking()
            .SingleOrDefaultAsync(item => item.Id == id, cancellationToken);

        return image is null ? NotFound() : Ok(ToResponse(image));
    }

    [HttpGet("{id:guid}/file")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> GetImageFile(Guid id, CancellationToken cancellationToken)
    {
        var image = await apiDbContext.MaterialImages.AsNoTracking().SingleOrDefaultAsync(item => item.Id == id, cancellationToken);
        if (image is null || !System.IO.File.Exists(image.Name))
        {
            return NotFound();
        }

        return PhysicalFile(image.Name, image.ContentType, image.OriginalFileName);
    }

    [HttpGet("{id:guid}/thumbnail")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> GetThumbnail(Guid id, CancellationToken cancellationToken)
    {
        var image = await apiDbContext.MaterialImages.AsNoTracking().SingleOrDefaultAsync(item => item.Id == id, cancellationToken);
        if (image?.ThumbnailName is null || !System.IO.File.Exists(image.ThumbnailName))
        {
            return NotFound();
        }

        return PhysicalFile(image.ThumbnailName, image.ContentType, image.StoredFileName);
    }

    [HttpPost]
    [RequirePermission("materials.update")]
    [Consumes("multipart/form-data")]
    public async Task<ActionResult<MaterialImageResponse>> UploadImage([FromForm] UploadMaterialImageRequest request, CancellationToken cancellationToken)
    {
        if (request.File is null)
        {
            return BadRequest(new { message = "Image file is required." });
        }

        var materialGuids = ParseGuids(request.MaterialGuids);
        var invalidMaterialGuids = await GetInvalidMaterialGuidsAsync(materialGuids, cancellationToken);
        if (invalidMaterialGuids.Count > 0)
        {
            return BadRequest(new { message = "One or more material GUIDs are invalid.", invalidMaterialGuids });
        }

        StoredImageFile storedFile;
        try
        {
            storedFile = await imageStorageService.SaveAsync(request.File, cancellationToken);
        }
        catch (InvalidOperationException exception)
        {
            return BadRequest(new { message = exception.Message });
        }

        var image = new ApiMaterialImage
        {
            Name = storedFile.ImagePath,
            ThumbnailName = storedFile.ThumbnailPath,
            OriginalFileName = Path.GetFileName(request.File.FileName),
            StoredFileName = storedFile.StoredFileName,
            ContentType = storedFile.ContentType,
            SizeBytes = storedFile.SizeBytes,
            Width = storedFile.Width,
            Height = storedFile.Height,
            ThumbnailWidth = storedFile.ThumbnailWidth,
            ThumbnailHeight = storedFile.ThumbnailHeight,
            CreatedByUserId = GetUserId(),
            CreatedAt = DateTimeOffset.UtcNow
        };

        foreach (var materialGuid in materialGuids)
        {
            image.MaterialLinks.Add(new ApiMaterialImageLink
            {
                ImageId = image.Id,
                MaterialGuid = materialGuid,
                IsPrimary = request.IsPrimary,
                CreatedByUserId = GetUserId(),
                CreatedAt = DateTimeOffset.UtcNow
            });
        }

        apiDbContext.MaterialImages.Add(image);
        await apiDbContext.SaveChangesAsync(cancellationToken);

        return CreatedAtAction(nameof(GetImage), new { id = image.Id }, ToResponse(image));
    }

    [HttpPost("{id:guid}/materials")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> LinkMaterials(Guid id, MaterialImageLinkRequest request, CancellationToken cancellationToken)
    {
        var image = await apiDbContext.MaterialImages
            .Include(item => item.MaterialLinks)
            .SingleOrDefaultAsync(item => item.Id == id, cancellationToken);

        if (image is null)
        {
            return NotFound();
        }

        var materialGuids = request.MaterialGuids.Distinct().ToArray();
        var invalidMaterialGuids = await GetInvalidMaterialGuidsAsync(materialGuids, cancellationToken);
        if (invalidMaterialGuids.Count > 0)
        {
            return BadRequest(new { message = "One or more material GUIDs are invalid.", invalidMaterialGuids });
        }

        var existing = image.MaterialLinks.Select(link => link.MaterialGuid).ToHashSet();
        foreach (var materialGuid in materialGuids.Where(materialGuid => !existing.Contains(materialGuid)))
        {
            image.MaterialLinks.Add(new ApiMaterialImageLink
            {
                ImageId = image.Id,
                MaterialGuid = materialGuid,
                IsPrimary = request.IsPrimary,
                CreatedByUserId = GetUserId(),
                CreatedAt = DateTimeOffset.UtcNow
            });
        }

        await apiDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpPut("{id:guid}/materials")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> ReplaceMaterialLinks(Guid id, MaterialImageLinkRequest request, CancellationToken cancellationToken)
    {
        var image = await apiDbContext.MaterialImages
            .Include(item => item.MaterialLinks)
            .SingleOrDefaultAsync(item => item.Id == id, cancellationToken);

        if (image is null)
        {
            return NotFound();
        }

        var materialGuids = request.MaterialGuids.Distinct().ToArray();
        var invalidMaterialGuids = await GetInvalidMaterialGuidsAsync(materialGuids, cancellationToken);
        if (invalidMaterialGuids.Count > 0)
        {
            return BadRequest(new { message = "One or more material GUIDs are invalid.", invalidMaterialGuids });
        }

        apiDbContext.MaterialImageLinks.RemoveRange(image.MaterialLinks);
        foreach (var materialGuid in materialGuids)
        {
            image.MaterialLinks.Add(new ApiMaterialImageLink
            {
                ImageId = image.Id,
                MaterialGuid = materialGuid,
                IsPrimary = request.IsPrimary,
                CreatedByUserId = GetUserId(),
                CreatedAt = DateTimeOffset.UtcNow
            });
        }

        image.UpdatedAt = DateTimeOffset.UtcNow;
        await apiDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpDelete("{id:guid}/materials")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> UnlinkMaterials(Guid id, MaterialImageLinkRequest request, CancellationToken cancellationToken)
    {
        var materialGuids = request.MaterialGuids.Distinct().ToArray();
        var links = await apiDbContext.MaterialImageLinks
            .Where(link => link.ImageId == id && materialGuids.Contains(link.MaterialGuid))
            .ToListAsync(cancellationToken);

        if (links.Count == 0)
        {
            return NoContent();
        }

        apiDbContext.MaterialImageLinks.RemoveRange(links);
        await apiDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpDelete("{id:guid}")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> DeleteImage(Guid id, CancellationToken cancellationToken)
    {
        var image = await apiDbContext.MaterialImages.SingleOrDefaultAsync(item => item.Id == id, cancellationToken);
        if (image is null)
        {
            return NotFound();
        }

        apiDbContext.MaterialImages.Remove(image);
        await apiDbContext.SaveChangesAsync(cancellationToken);
        imageStorageService.DeleteFiles(image);
        return NoContent();
    }

    [HttpGet("/api/materials/{materialGuid:guid}/images")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<IReadOnlyCollection<MaterialImageResponse>>> GetMaterialImages(Guid materialGuid, CancellationToken cancellationToken)
    {
        var images = await apiDbContext.MaterialImages
            .Include(image => image.MaterialLinks)
            .AsNoTracking()
            .Where(image => image.MaterialLinks.Any(link => link.MaterialGuid == materialGuid))
            .OrderByDescending(image => image.CreatedAt)
            .ToListAsync(cancellationToken);

        return Ok(images.Select(ToResponse).ToArray());
    }

    private async Task<IReadOnlyCollection<Guid>> GetInvalidMaterialGuidsAsync(IReadOnlyCollection<Guid> materialGuids, CancellationToken cancellationToken)
    {
        if (materialGuids.Count == 0)
        {
            return [];
        }

        var existingMaterialGuids = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => materialGuids.Contains(material.Guid))
            .Select(material => material.Guid)
            .ToListAsync(cancellationToken);

        return materialGuids.Except(existingMaterialGuids).ToArray();
    }

    private Guid? GetUserId()
    {
        var value = User.FindFirstValue(ClaimTypes.NameIdentifier);
        return Guid.TryParse(value, out var userId) ? userId : null;
    }

    private static IReadOnlyCollection<Guid> ParseGuids(string? values)
    {
        if (string.IsNullOrWhiteSpace(values))
        {
            return [];
        }

        return values
            .Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries)
            .Select(value => Guid.TryParse(value, out var parsed) ? parsed : (Guid?)null)
            .Where(value => value.HasValue)
            .Select(value => value!.Value)
            .Distinct()
            .ToArray();
    }

    private static MaterialImageResponse ToResponse(ApiMaterialImage image)
    {
        return new MaterialImageResponse(
            image.Id,
            image.Name,
            image.ThumbnailName,
            image.OriginalFileName,
            image.StoredFileName,
            image.ContentType,
            image.SizeBytes,
            image.Width,
            image.Height,
            image.ThumbnailWidth,
            image.ThumbnailHeight,
            image.MaterialLinks.Select(link => link.MaterialGuid).OrderBy(guid => guid).ToArray(),
            image.CreatedAt,
            image.UpdatedAt);
    }
}

