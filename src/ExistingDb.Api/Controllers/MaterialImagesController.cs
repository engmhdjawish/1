using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Contracts.Images;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using ExistingDb.Api.Images;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/material-images")]
public sealed class MaterialImagesController(
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

        var query = mainDbContext.MaterialImages.AsNoTracking();

        var linkedImageGuids = mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.PictureGuid.HasValue)
            .Select(material => material.PictureGuid!.Value);

        if (materialGuid is not null)
        {
            query = query.Where(image =>
                mainDbContext.Materials.Any(material =>
                    material.Guid == materialGuid.Value &&
                    material.PictureGuid == image.Guid));
        }

        if (linked is true)
        {
            query = query.Where(image => linkedImageGuids.Contains(image.Guid));
        }
        else if (linked is false)
        {
            query = query.Where(image => !linkedImageGuids.Contains(image.Guid));
        }

        var totalCount = await query.CountAsync(cancellationToken);
        var images = await query
            .OrderBy(image => image.Name)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var imageGuids = images.Select(image => image.Guid).ToArray();
        var materialLinks = imageGuids.Length == 0
            ? []
            : await mainDbContext.Materials
                .AsNoTracking()
                .Where(material => material.PictureGuid.HasValue && imageGuids.Contains(material.PictureGuid.Value))
                .Select(material => new { ImageGuid = material.PictureGuid!.Value, MaterialGuid = material.Guid })
                .ToListAsync(cancellationToken);

        var materialGuidsByImage = materialLinks
            .GroupBy(link => link.ImageGuid)
            .ToDictionary(
                group => group.Key,
                group => (IReadOnlyCollection<Guid>)group
                    .Select(item => item.MaterialGuid)
                    .OrderBy(guid => guid)
                    .ToArray());

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        return Ok(new PagedResponse<MaterialImageResponse>(
            images
                .Select(image => ToResponse(
                    image,
                    materialGuidsByImage.GetValueOrDefault(image.Guid) ?? [],
                    settings))
                .ToArray(),
            page,
            pageSize,
            totalCount));
    }

    [HttpGet("{id:guid}")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<MaterialImageResponse>> GetImage(Guid id, CancellationToken cancellationToken)
    {
        var image = await mainDbContext.MaterialImages
            .AsNoTracking()
            .SingleOrDefaultAsync(item => item.Guid == id, cancellationToken);

        if (image is null)
        {
            return NotFound();
        }

        var materialGuids = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.PictureGuid == id)
            .Select(material => material.Guid)
            .OrderBy(guid => guid)
            .ToArrayAsync(cancellationToken);

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        return Ok(ToResponse(image, materialGuids, settings));
    }

    [HttpGet("{id:guid}/file")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> GetImageFile(Guid id, CancellationToken cancellationToken)
    {
        var image = await mainDbContext.MaterialImages
            .AsNoTracking()
            .SingleOrDefaultAsync(item => item.Guid == id, cancellationToken);

        if (image is null)
        {
            return NotFound();
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var imagePath = ResolveImagePath(image.Name, settings.ImagesDirectory);
        if (!System.IO.File.Exists(imagePath))
        {
            return NotFound();
        }

        return PhysicalFile(imagePath, GetContentType(imagePath), Path.GetFileName(imagePath));
    }

    [HttpGet("{id:guid}/thumbnail")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> GetThumbnail(Guid id, CancellationToken cancellationToken)
    {
        var image = await mainDbContext.MaterialImages
            .AsNoTracking()
            .SingleOrDefaultAsync(item => item.Guid == id, cancellationToken);

        if (image is null)
        {
            return NotFound();
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var thumbnailPath = ResolveThumbnailPath(image.Name, settings.ThumbnailsDirectory);
        if (!System.IO.File.Exists(thumbnailPath))
        {
            return NotFound();
        }

        return PhysicalFile(thumbnailPath, GetContentType(thumbnailPath), Path.GetFileName(thumbnailPath));
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

        var image = new MaterialImageRecord
        {
            Guid = Guid.NewGuid(),
            Name = storedFile.StoredFileName
        };

        mainDbContext.MaterialImages.Add(image);

        if (materialGuids.Count > 0)
        {
            var materials = await mainDbContext.Materials
                .Where(material => materialGuids.Contains(material.Guid))
                .ToListAsync(cancellationToken);

            foreach (var material in materials)
            {
                material.PictureGuid = image.Guid;
            }
        }

        await mainDbContext.SaveChangesAsync(cancellationToken);

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var response = ToResponse(image, materialGuids.OrderBy(guid => guid).ToArray(), settings);

        return CreatedAtAction(nameof(GetImage), new { id = image.Guid }, response);
    }

    [HttpPost("{id:guid}/materials")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> LinkMaterials(Guid id, MaterialImageLinkRequest request, CancellationToken cancellationToken)
    {
        var imageExists = await mainDbContext.MaterialImages
            .AnyAsync(image => image.Guid == id, cancellationToken);
        if (!imageExists)
        {
            return NotFound();
        }

        var materialGuids = request.MaterialGuids.Distinct().ToArray();
        var invalidMaterialGuids = await GetInvalidMaterialGuidsAsync(materialGuids, cancellationToken);
        if (invalidMaterialGuids.Count > 0)
        {
            return BadRequest(new { message = "One or more material GUIDs are invalid.", invalidMaterialGuids });
        }

        var materials = await mainDbContext.Materials
            .Where(material => materialGuids.Contains(material.Guid))
            .ToListAsync(cancellationToken);

        foreach (var material in materials)
        {
            material.PictureGuid = id;
        }

        await mainDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpPut("{id:guid}/materials")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> ReplaceMaterialLinks(Guid id, MaterialImageLinkRequest request, CancellationToken cancellationToken)
    {
        var imageExists = await mainDbContext.MaterialImages
            .AnyAsync(image => image.Guid == id, cancellationToken);
        if (!imageExists)
        {
            return NotFound();
        }

        var materialGuids = request.MaterialGuids.Distinct().ToArray();
        var invalidMaterialGuids = await GetInvalidMaterialGuidsAsync(materialGuids, cancellationToken);
        if (invalidMaterialGuids.Count > 0)
        {
            return BadRequest(new { message = "One or more material GUIDs are invalid.", invalidMaterialGuids });
        }

        var requested = materialGuids.ToHashSet();
        var materials = await mainDbContext.Materials
            .Where(material => material.PictureGuid == id || requested.Contains(material.Guid))
            .ToListAsync(cancellationToken);

        foreach (var material in materials)
        {
            material.PictureGuid = requested.Contains(material.Guid)
                ? id
                : null;
        }

        await mainDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpDelete("{id:guid}/materials")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> UnlinkMaterials(Guid id, MaterialImageLinkRequest request, CancellationToken cancellationToken)
    {
        var materialGuids = request.MaterialGuids.Distinct().ToArray();
        if (materialGuids.Length == 0)
        {
            return NoContent();
        }

        var materials = await mainDbContext.Materials
            .Where(material => materialGuids.Contains(material.Guid) && material.PictureGuid == id)
            .ToListAsync(cancellationToken);

        if (materials.Count == 0)
        {
            return NoContent();
        }

        foreach (var material in materials)
        {
            material.PictureGuid = null;
        }

        await mainDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpDelete("{id:guid}")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> DeleteImage(Guid id, CancellationToken cancellationToken)
    {
        var image = await mainDbContext.MaterialImages
            .SingleOrDefaultAsync(item => item.Guid == id, cancellationToken);
        if (image is null)
        {
            return NotFound();
        }

        var linkedMaterials = await mainDbContext.Materials
            .Where(material => material.PictureGuid == id)
            .ToListAsync(cancellationToken);

        foreach (var material in linkedMaterials)
        {
            material.PictureGuid = null;
        }

        mainDbContext.MaterialImages.Remove(image);
        await mainDbContext.SaveChangesAsync(cancellationToken);

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var imagePath = ResolveImagePath(image.Name, settings.ImagesDirectory);
        var thumbnailPath = ResolveThumbnailPath(image.Name, settings.ThumbnailsDirectory);
        imageStorageService.DeleteFiles(imagePath, thumbnailPath);
        return NoContent();
    }

    [HttpGet("/api/materials/{materialGuid:guid}/images")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<IReadOnlyCollection<MaterialImageResponse>>> GetMaterialImages(Guid materialGuid, CancellationToken cancellationToken)
    {
        var pictureGuid = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.Guid == materialGuid)
            .Select(material => material.PictureGuid)
            .SingleOrDefaultAsync(cancellationToken);

        if (pictureGuid is null || pictureGuid == Guid.Empty)
        {
            return Ok(Array.Empty<MaterialImageResponse>());
        }

        var image = await mainDbContext.MaterialImages
            .AsNoTracking()
            .SingleOrDefaultAsync(item => item.Guid == pictureGuid, cancellationToken);

        if (image is null)
        {
            return Ok(Array.Empty<MaterialImageResponse>());
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        return Ok(new[]
        {
            ToResponse(image, [materialGuid], settings)
        });
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

    private static IReadOnlyCollection<Guid> ParseGuids(string? values)
    {
        if (string.IsNullOrWhiteSpace(values))
        {
            return [];
        }

        return values
            .Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries)
            .Select(value => Guid.TryParse(value, out var parsed) ? (Guid?)parsed : null)
            .Where(value => value.HasValue)
            .Select(value => value!.Value)
            .Distinct()
            .ToArray();
    }

    private static MaterialImageResponse ToResponse(
        MaterialImageRecord image,
        IReadOnlyCollection<Guid> materialGuids,
        ImageStorageSettings settings)
    {
        var imagePath = ResolveImagePath(image.Name, settings.ImagesDirectory);
        var thumbnailPath = ResolveThumbnailPath(image.Name, settings.ThumbnailsDirectory);
        var imageExists = System.IO.File.Exists(imagePath);
        var thumbnailExists = System.IO.File.Exists(thumbnailPath);
        var storedFileName = Path.GetFileName(image.Name ?? string.Empty);
        var createdAt = imageExists
            ? new DateTimeOffset(System.IO.File.GetCreationTimeUtc(imagePath), TimeSpan.Zero)
            : DateTimeOffset.UnixEpoch;
        DateTimeOffset? updatedAt = imageExists
            ? new DateTimeOffset(System.IO.File.GetLastWriteTimeUtc(imagePath), TimeSpan.Zero)
            : null;

        return new MaterialImageResponse(
            image.Guid,
            imagePath,
            thumbnailExists ? thumbnailPath : null,
            storedFileName,
            storedFileName,
            GetContentType(imagePath),
            imageExists ? new FileInfo(imagePath).Length : 0,
            null,
            null,
            null,
            null,
            materialGuids.OrderBy(guid => guid).ToArray(),
            createdAt,
            updatedAt);
    }

    private static string ResolveImagePath(string? name, string imagesDirectory)
    {
        if (string.IsNullOrWhiteSpace(name))
        {
            return string.Empty;
        }

        if (Path.IsPathRooted(name))
        {
            return Path.GetFullPath(name);
        }

        var fileName = Path.GetFileName(name);
        return Path.GetFullPath(Path.Combine(imagesDirectory, fileName));
    }

    private static string ResolveThumbnailPath(string? name, string thumbnailsDirectory)
    {
        var fileName = Path.GetFileName(name ?? string.Empty);
        if (string.IsNullOrWhiteSpace(fileName))
        {
            return string.Empty;
        }

        return Path.GetFullPath(Path.Combine(thumbnailsDirectory, fileName));
    }

    private static string GetContentType(string path)
    {
        var extension = Path.GetExtension(path).ToLowerInvariant();
        return extension switch
        {
            ".jpg" or ".jpeg" => "image/jpeg",
            ".png" => "image/png",
            ".gif" => "image/gif",
            ".bmp" => "image/bmp",
            ".webp" => "image/webp",
            _ => "application/octet-stream"
        };
    }
}

