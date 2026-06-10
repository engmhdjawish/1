using System.IO.Compression;
using System.Linq.Expressions;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Contracts.Images;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using ExistingDb.Api.Images;
using ExistingDb.Api.Services.Materials;
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

        var query = BuildImageQuery(linked, materialGuid);

        var totalCount = await query.CountAsync(cancellationToken);
        var images = await query
            .OrderBy(image => image.Name)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var linkedMaterialByImage = await GetLinkedMaterialByImageAsync(images, cancellationToken);
        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var responseItems = images
            .Select(image => ToResponse(
                image,
                linkedMaterialByImage.GetValueOrDefault(image.Guid),
                settings))
            .ToArray();

        return Ok(new PagedResponse<MaterialImageResponse>(responseItems, page, pageSize, totalCount));
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

        var linkedMaterialGuid = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.PictureGuid == id)
            .OrderBy(material => material.Guid)
            .Select(material => (Guid?)material.Guid)
            .FirstOrDefaultAsync(cancellationToken);

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        return Ok(ToResponse(image, linkedMaterialGuid, settings));
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
        var imagePath = ResolveExistingImagePath(Path.GetFileName(image.Name), settings.ImagesDirectory);
        if (string.IsNullOrWhiteSpace(imagePath))
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
        var thumbnailPath = ResolveExistingImagePath(Path.GetFileName(image.Name), settings.ThumbnailsDirectory);
        if (string.IsNullOrWhiteSpace(thumbnailPath) || !System.IO.File.Exists(thumbnailPath))
        {
            return NotFound();
        }

        return PhysicalFile(thumbnailPath, GetContentType(thumbnailPath), Path.GetFileName(thumbnailPath));
    }

    [HttpPost]
    [RequirePermission("materials.update")]
    [Consumes("multipart/form-data")]
    public async Task<ActionResult> UploadImage([FromForm] UploadMaterialImageRequest request, CancellationToken cancellationToken)
    {
        var files = (request.Files ?? [])
            .Where(file => file.Length > 0)
            .ToList();

        if (files.Count == 0)
        {
            return BadRequest(new { message = "At least one image file is required." });
        }

        // MaterialGuid is ignored when uploading multiple files.
        var effectiveMaterialGuid = files.Count == 1 ? request.MaterialGuid : null;

        if (effectiveMaterialGuid is Guid materialGuid && materialGuid == Guid.Empty)
        {
            return BadRequest(new { message = "MaterialGuid cannot be empty." });
        }

        if (effectiveMaterialGuid is Guid linkedMaterialGuid)
        {
            var materialExists = await mainDbContext.Materials
                .AsNoTracking()
                .AnyAsync(material => material.Guid == linkedMaterialGuid, cancellationToken);
            if (!materialExists)
            {
                return BadRequest(new { message = "Material GUID is invalid.", materialGuid = linkedMaterialGuid });
            }
        }

        var createdImages = new List<MaterialImageRecord>(files.Count);
        var savedFiles = new List<(string ImagePath, string? ThumbnailPath)>(files.Count);
        foreach (var file in files)
        {
            StoredImageFile storedFile;
            try
            {
                storedFile = await imageStorageService.SaveAsync(file, cancellationToken);
            }
            catch (InvalidOperationException exception)
            {
                foreach (var savedFile in savedFiles)
                {
                    imageStorageService.DeleteFiles(savedFile.ImagePath, savedFile.ThumbnailPath);
                }

                return BadRequest(new { message = exception.Message, fileName = file.FileName });
            }

            savedFiles.Add((storedFile.ImagePath, storedFile.ThumbnailPath));
            var image = new MaterialImageRecord
            {
                Guid = Guid.NewGuid(),
                Name = storedFile.ImagePath
            };

            createdImages.Add(image);
        }

        try
        {
            mainDbContext.MaterialImages.AddRange(createdImages);
            await mainDbContext.SaveChangesAsync(cancellationToken);
        }
        catch
        {
            foreach (var savedFile in savedFiles)
            {
                imageStorageService.DeleteFiles(savedFile.ImagePath, savedFile.ThumbnailPath);
            }

            throw;
        }

        if (effectiveMaterialGuid is Guid materialGuidToLink)
        {
            var linked = await LinkImageToMaterialInternalAsync(createdImages[0].Guid, materialGuidToLink, cancellationToken);
            if (!linked)
            {
                return NotFound(new { message = "Material was not found during linking.", materialGuid = materialGuidToLink });
            }
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        if (createdImages.Count == 1)
        {
            Guid? linkedMaterial = effectiveMaterialGuid;
            var response = ToResponse(createdImages[0], linkedMaterial, settings);
            return CreatedAtAction(nameof(GetImage), new { id = createdImages[0].Guid }, response);
        }

        var responseItems = createdImages
            .Select(image => ToResponse(image, null, settings))
            .ToArray();
        return Ok(responseItems);
    }

    [HttpPut("links/materials/{materialGuid:guid}/images/{imageGuid:guid}")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> LinkImageToMaterial(
        Guid materialGuid,
        Guid imageGuid,
        CancellationToken cancellationToken)
    {
        if (materialGuid == Guid.Empty)
        {
            return BadRequest(new { message = "MaterialGuid cannot be empty." });
        }

        if (imageGuid == Guid.Empty)
        {
            return BadRequest(new { message = "ImageGuid cannot be empty." });
        }

        var imageExists = await mainDbContext.MaterialImages
            .AsNoTracking()
            .AnyAsync(image => image.Guid == imageGuid, cancellationToken);
        if (!imageExists)
        {
            return NotFound(new { message = "Image was not found." });
        }

        var linked = await LinkImageToMaterialInternalAsync(imageGuid, materialGuid, cancellationToken);
        return linked
            ? NoContent()
            : NotFound(new { message = "Material was not found." });
    }

    [HttpPost("unlink")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> UnlinkImageFromMaterial(
        [FromBody] MaterialImageUnlinkRequest request,
        CancellationToken cancellationToken)
    {
        if (request.MaterialGuid is Guid materialGuidValue && materialGuidValue == Guid.Empty)
        {
            return BadRequest(new { message = "MaterialGuid cannot be empty." });
        }

        if (request.ImageGuid is Guid imageGuidValue && imageGuidValue == Guid.Empty)
        {
            return BadRequest(new { message = "ImageGuid cannot be empty." });
        }

        var materialGuid = request.MaterialGuid;
        var imageGuid = request.ImageGuid;

        if (!materialGuid.HasValue && !imageGuid.HasValue)
        {
            return BadRequest(new { message = "Either MaterialGuid or ImageGuid must be provided." });
        }

        var linkedMaterialsQuery = mainDbContext.Materials.AsQueryable();
        if (materialGuid.HasValue)
        {
            linkedMaterialsQuery = linkedMaterialsQuery.Where(material => material.Guid == materialGuid.Value);
        }

        if (imageGuid.HasValue)
        {
            linkedMaterialsQuery = linkedMaterialsQuery.Where(material => material.PictureGuid == imageGuid.Value);
        }

        var linkedMaterials = await linkedMaterialsQuery
            .Where(material => material.PictureGuid.HasValue)
            .ToListAsync(cancellationToken);

        if (linkedMaterials.Count == 0)
        {
            return NoContent();
        }

        foreach (var material in linkedMaterials)
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
        var material = await mainDbContext.Materials
            .AsNoTracking()
            .Where(item => item.Guid == materialGuid)
            .Select(item => new { item.Guid, item.PictureGuid })
            .SingleOrDefaultAsync(cancellationToken);

        if (material is null)
        {
            return NotFound();
        }

        if (!MaterialPictureGuid.HasImage(material.PictureGuid))
        {
            return Ok(Array.Empty<MaterialImageResponse>());
        }

        var image = await mainDbContext.MaterialImages
            .AsNoTracking()
            .SingleOrDefaultAsync(item => item.Guid == material.PictureGuid!.Value, cancellationToken);

        if (image is null)
        {
            return Ok(Array.Empty<MaterialImageResponse>());
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        return Ok(new[]
        {
            ToResponse(image, material.Guid, settings)
        });
    }

    [HttpGet("download")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> DownloadImages(
        [FromQuery] bool? linked = null,
        [FromQuery] Guid? materialGuid = null,
        CancellationToken cancellationToken = default)
    {
        var images = await BuildImageQuery(linked, materialGuid)
            .OrderBy(image => image.Name)
            .ToListAsync(cancellationToken);

        return await DownloadImagesAsZipAsync(images, "material-images", cancellationToken);
    }

    [HttpGet("download/materials")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> DownloadMaterialImagesByFilter(
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
        CancellationToken cancellationToken = default)
    {
        var selectedStoreGuids = ParseGuids(storeGuid, storeGuids);
        var selectedGroupGuids = ParseGuids(groupGuid, groupGuids);
        var materialQuery = mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.PictureGuid.HasValue);

        materialQuery = ApplyStoreAndQuantityFilters(
            materialQuery,
            selectedStoreGuids,
            minWarehouseQuantity,
            maxWarehouseQuantity,
            isAvailable);

        materialQuery = ApplyTextFilters(
            materialQuery,
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

        if (selectedGroupGuids.Count > 0)
        {
            materialQuery = materialQuery.Where(material =>
                material.GroupGuid.HasValue && selectedGroupGuids.Contains(material.GroupGuid.Value));
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var term = search.Trim();
            var exactCodeExists = await mainDbContext.Materials
                .AsNoTracking()
                .AnyAsync(material => material.Code == term, cancellationToken);

            materialQuery = exactCodeExists
                ? materialQuery.Where(material => material.Code == term)
                : materialQuery.Where(material =>
                    (material.Name != null && material.Name.Contains(term)) ||
                    (material.LatinName != null && material.LatinName.Contains(term)) ||
                    (material.Code != null && material.Code.Contains(term)) ||
                    (material.BarCode != null && material.BarCode.Contains(term)) ||
                    (material.BarCode2 != null && material.BarCode2.Contains(term)) ||
                    (material.BarCode3 != null && material.BarCode3.Contains(term)));
        }

        var imageGuids = await materialQuery
            .Select(material => material.PictureGuid!.Value)
            .Distinct()
            .ToListAsync(cancellationToken);

        var images = imageGuids.Count == 0
            ? []
            : await mainDbContext.MaterialImages
                .AsNoTracking()
                .Where(image => imageGuids.Contains(image.Guid))
                .OrderBy(image => image.Name)
                .ToListAsync(cancellationToken);

        return await DownloadImagesAsZipAsync(images, "filtered-material-images", cancellationToken);
    }

    [HttpGet("download/bills/{billGuid:guid}")]
    [RequirePermission("materials.read")]
    public async Task<IActionResult> DownloadBillMaterialImages(Guid billGuid, CancellationToken cancellationToken)
    {
        var materialGuids = await mainDbContext.BillItems
            .AsNoTracking()
            .Where(item => item.ParentGuid == billGuid && item.MaterialGuid.HasValue)
            .Select(item => item.MaterialGuid!.Value)
            .Distinct()
            .ToListAsync(cancellationToken);

        if (materialGuids.Count == 0)
        {
            return NotFound(new { message = "No material rows were found for this bill.", billGuid });
        }

        var imageGuids = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => materialGuids.Contains(material.Guid) && material.PictureGuid.HasValue)
            .Select(material => material.PictureGuid!.Value)
            .Distinct()
            .ToListAsync(cancellationToken);

        var images = imageGuids.Count == 0
            ? []
            : await mainDbContext.MaterialImages
                .AsNoTracking()
                .Where(image => imageGuids.Contains(image.Guid))
                .OrderBy(image => image.Name)
                .ToListAsync(cancellationToken);

        return await DownloadImagesAsZipAsync(images, $"bill-{billGuid:N}-images", cancellationToken);
    }

    private IQueryable<MaterialImageRecord> BuildImageQuery(bool? linked, Guid? materialGuid)
    {
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

        return query;
    }

    private async Task<Dictionary<Guid, Guid?>> GetLinkedMaterialByImageAsync(
        IReadOnlyCollection<MaterialImageRecord> images,
        CancellationToken cancellationToken)
    {
        if (images.Count == 0)
        {
            return [];
        }

        var imageGuids = images.Select(image => image.Guid).ToArray();
        var links = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.PictureGuid.HasValue && imageGuids.Contains(material.PictureGuid.Value))
            .Select(material => new { ImageGuid = material.PictureGuid!.Value, MaterialGuid = (Guid?)material.Guid })
            .ToListAsync(cancellationToken);

        return links
            .GroupBy(link => link.ImageGuid)
            .ToDictionary(
                group => group.Key,
                group => group
                    .OrderBy(item => item.MaterialGuid)
                    .Select(item => item.MaterialGuid)
                    .FirstOrDefault());
    }

    private async Task<bool> LinkImageToMaterialInternalAsync(Guid imageGuid, Guid materialGuid, CancellationToken cancellationToken)
    {
        var material = await mainDbContext.Materials
            .SingleOrDefaultAsync(item => item.Guid == materialGuid, cancellationToken);
        if (material is null)
        {
            return false;
        }

        var linkedMaterials = await mainDbContext.Materials
            .Where(item => item.PictureGuid == imageGuid && item.Guid != materialGuid)
            .ToListAsync(cancellationToken);
        foreach (var linkedMaterial in linkedMaterials)
        {
            linkedMaterial.PictureGuid = null;
        }

        material.PictureGuid = imageGuid;
        await mainDbContext.SaveChangesAsync(cancellationToken);
        return true;
    }

    private async Task<IActionResult> DownloadImagesAsZipAsync(
        IReadOnlyCollection<MaterialImageRecord> images,
        string archiveName,
        CancellationToken cancellationToken)
    {
        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var files = images
            .Select(image => ResolveExistingImagePath(image.Name, settings.ImagesDirectory))
            .Where(path => !string.IsNullOrWhiteSpace(path))
            .Select(path => path!)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();

        if (files.Length == 0)
        {
            return NotFound(new { message = "No image files found for this request." });
        }

        Response.ContentType = "application/zip";
        Response.Headers.ContentDisposition = $"attachment; filename=\"{SanitizeFileName(archiveName)}.zip\"";

        using var archive = new ZipArchive(Response.Body, ZipArchiveMode.Create, leaveOpen: true);
        var usedEntryNames = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var path in files)
        {
            cancellationToken.ThrowIfCancellationRequested();

            var entryName = CreateUniqueEntryName(Path.GetFileName(path), usedEntryNames);
            var entry = archive.CreateEntry(entryName, CompressionLevel.Fastest);
            await using var archiveStream = entry.Open();
            await using var fileStream = System.IO.File.OpenRead(path);
            await fileStream.CopyToAsync(archiveStream, cancellationToken);
        }

        return new EmptyResult();
    }

    private static string CreateUniqueEntryName(string fileName, ISet<string> usedEntryNames)
    {
        var baseName = Path.GetFileNameWithoutExtension(fileName);
        var extension = Path.GetExtension(fileName);
        var candidate = fileName;
        var counter = 1;

        while (!usedEntryNames.Add(candidate))
        {
            candidate = $"{baseName}_{counter}{extension}";
            counter++;
        }

        return candidate;
    }

    private static string SanitizeFileName(string fileName)
    {
        var invalid = Path.GetInvalidFileNameChars();
        var sanitized = new string(fileName.Select(ch => invalid.Contains(ch) ? '_' : ch).ToArray()).Trim();
        return string.IsNullOrWhiteSpace(sanitized) ? "material-images" : sanitized;
    }

    private static MaterialImageResponse ToResponse(
        MaterialImageRecord image,
        Guid? materialGuid,
        ImageStorageSettings settings)
    {
        var imagePath = ResolveExistingImagePath(image.Name, settings.ImagesDirectory)
            ?? ResolveImagePath(image.Name, settings.ImagesDirectory);
        var thumbnailPath = ResolveExistingThumbnailPath(image.Name, settings.ThumbnailsDirectory)
            ?? ResolveThumbnailPath(image.Name, settings.ThumbnailsDirectory);
        var imageExists = System.IO.File.Exists(imagePath);
        var storedFileName = ExtractFileName(image.Name) ?? Path.GetFileName(imagePath);
        var createdAt = imageExists
            ? new DateTimeOffset(System.IO.File.GetCreationTimeUtc(imagePath), TimeSpan.Zero)
            : DateTimeOffset.UnixEpoch;
        DateTimeOffset? updatedAt = imageExists
            ? new DateTimeOffset(System.IO.File.GetLastWriteTimeUtc(imagePath), TimeSpan.Zero)
            : null;

        return new MaterialImageResponse(
            image.Guid,
            imagePath,
            System.IO.File.Exists(thumbnailPath) ? thumbnailPath : null,
            storedFileName,
            storedFileName,
            GetContentType(string.IsNullOrWhiteSpace(imagePath) ? storedFileName : imagePath),
            imageExists ? new FileInfo(imagePath).Length : 0,
            materialGuid,
            createdAt,
            updatedAt);
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

    private static string ResolveImagePath(string? name, string imagesDirectory)
    {
        var fileName = ExtractFileName(name);
        if (string.IsNullOrWhiteSpace(fileName))
        {
            return string.Empty;
        }

        return Path.GetFullPath(Path.Combine(imagesDirectory, fileName));
    }

    private static string? ResolveExistingImagePath(string? name, string imagesDirectory)
    {
        var candidates = BuildImagePathCandidates(name, imagesDirectory);
        return candidates.FirstOrDefault(System.IO.File.Exists);
    }

    private static string? ResolveExistingThumbnailPath(string? name, string thumbnailsDirectory)
    {
        var fileName = ExtractFileName(name);
        if (!string.IsNullOrWhiteSpace(fileName))
        {
            var candidate = Path.GetFullPath(Path.Combine(thumbnailsDirectory, fileName));
            return System.IO.File.Exists(candidate) ? candidate : null;
        }

        return null;
    }

    private static IReadOnlyCollection<string> BuildImagePathCandidates(string? name, string imagesDirectory)
    {
        var fileName = ExtractFileName(name);
        var candidates = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        if (!string.IsNullOrWhiteSpace(fileName))
        {
            candidates.Add(Path.GetFullPath(Path.Combine(imagesDirectory, fileName)));
        }

        return candidates.ToArray();
    }

    private static string? ExtractFileName(string? pathLikeValue)
    {
        if (string.IsNullOrWhiteSpace(pathLikeValue))
        {
            return null;
        }

        var normalized = pathLikeValue.Replace('\\', '/');
        var lastSeparator = normalized.LastIndexOf('/');
        return lastSeparator >= 0
            ? normalized[(lastSeparator + 1)..]
            : normalized;
    }

    private static string ResolveThumbnailPath(string? name, string thumbnailsDirectory)
    {
        var fileName = ExtractFileName(name);
        if (string.IsNullOrWhiteSpace(fileName))
        {
            return string.Empty;
        }

        return Path.GetFullPath(Path.Combine(thumbnailsDirectory, fileName));
    }

    private static string GetContentType(string path)
    {
        return Path.GetExtension(path).ToLowerInvariant() switch
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
