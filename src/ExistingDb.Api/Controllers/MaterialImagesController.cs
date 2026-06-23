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
        return Ok(new ImageSettingsResponse(settings.ImagesDirectory));
    }

    [HttpPut("settings")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> UpdateSettings(ImageSettingsRequest request, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(request.ImagesDirectory))
        {
            return BadRequest(new { message = "Images directory is required." });
        }

        await imageSettingsService.UpdateAsync(
            new ImageStorageSettings(request.ImagesDirectory.Trim()),
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

    [HttpGet("lookup")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<MaterialImageLookupResponse>> LookupByFileName(
        [FromQuery] string fileName,
        CancellationToken cancellationToken)
    {
        fileName = Path.GetFileName(fileName.Trim());
        if (fileName is "")
        {
            return BadRequest(new { message = "fileName is required." });
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var candidates = await mainDbContext.MaterialImages
            .AsNoTracking()
            .Where(image => image.Name != null && image.Name.Contains(fileName))
            .ToListAsync(cancellationToken);

        var image = candidates
            .FirstOrDefault(candidate =>
                string.Equals(ExtractFileName(candidate.Name), fileName, StringComparison.OrdinalIgnoreCase));

        var dbByFileName = image is null
            ? new Dictionary<string, MaterialImageRecord>(StringComparer.OrdinalIgnoreCase)
            : new Dictionary<string, MaterialImageRecord>(StringComparer.OrdinalIgnoreCase)
            {
                [fileName] = image
            };

        var batchItem = await LookupFileOnAmineAsync(
            fileName,
            settings.ImagesDirectory,
            dbByFileName,
            cancellationToken);
        if (!batchItem.Found)
        {
            return NotFound();
        }

        return Ok(new MaterialImageLookupResponse(
            batchItem.Id,
            batchItem.FileName,
            batchItem.SizeBytes,
            batchItem.Sha256,
            batchItem.FileExistsOnDisk));
    }

    [HttpPost("lookup-batch")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<MaterialImageLookupBatchResponse>> LookupBatch(
        [FromBody] MaterialImageLookupBatchRequest request,
        CancellationToken cancellationToken)
    {
        var items = (request.Items ?? [])
            .Where(item => !string.IsNullOrWhiteSpace(item.FileName))
            .Take(50)
            .ToArray();
        if (items.Length == 0)
        {
            return BadRequest(new { message = "At least one fileName is required." });
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var requestByFile = items
            .Select(item => new
            {
                FileName = Path.GetFileName(item.FileName.Trim()),
                Item = item,
            })
            .Where(entry => entry.FileName is not "")
            .GroupBy(entry => entry.FileName, StringComparer.OrdinalIgnoreCase)
            .ToDictionary(group => group.Key, group => group.First().Item, StringComparer.OrdinalIgnoreCase);

        var requestedNames = requestByFile.Keys.ToArray();
        var dbByFile = await LoadDbImagesByFileNamesAsync(requestedNames, cancellationToken);
        var responsesByFile = new Dictionary<string, MaterialImageLookupBatchItemResponse>(StringComparer.OrdinalIgnoreCase);

        foreach (var fileName in requestedNames)
        {
            var dbByFileName = dbByFile.TryGetValue(fileName, out var image)
                ? new Dictionary<string, MaterialImageRecord>(StringComparer.OrdinalIgnoreCase)
                {
                    [fileName] = image,
                }
                : new Dictionary<string, MaterialImageRecord>(StringComparer.OrdinalIgnoreCase);

            responsesByFile[fileName] = await LookupFileOnAmineAsync(
                fileName,
                settings.ImagesDirectory,
                dbByFileName,
                cancellationToken);
        }

        var imageGuids = responsesByFile.Values
            .Where(response => response.Id is Guid id && id != Guid.Empty)
            .Select(response => response.Id!.Value)
            .Distinct()
            .ToArray();
        var materialByImage = await GetLinkedMaterialDetailsByImageAsync(imageGuids, cancellationToken);

        var responses = new List<MaterialImageLookupBatchItemResponse>(items.Length);
        foreach (var item in items)
        {
            var fileName = Path.GetFileName(item.FileName.Trim());
            if (fileName is "")
            {
                continue;
            }

            if (!responsesByFile.TryGetValue(fileName, out var baseResponse))
            {
                responses.Add(new MaterialImageLookupBatchItemResponse(fileName, null, 0, string.Empty, false, false));
                continue;
            }

            if (baseResponse.Id is Guid imageGuid && materialByImage.TryGetValue(imageGuid, out var link))
            {
                responses.Add(baseResponse with
                {
                    MaterialGuid = link.MaterialGuid,
                    MaterialName = link.MaterialName,
                    MaterialCode = link.MaterialCode,
                });
                continue;
            }

            responses.Add(baseResponse);
        }

        return Ok(new MaterialImageLookupBatchResponse(responses));
    }

    [HttpGet("link-files")]
    [RequirePermission("materials.read")]
    public async Task<ActionResult<PagedResponse<MaterialImageLinkFileResponse>>> GetLinkFiles(
        [FromQuery] bool? linked = null,
        [FromQuery] string? materialSearch = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 24,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 6, 60);

        var query = BuildImageQuery(linked, null);
        var searchTokens = SplitSearchTokens(materialSearch);
        foreach (var token in searchTokens)
        {
            var term = token;
            query = query.Where(image =>
                mainDbContext.Materials.Any(material =>
                    material.PictureGuid == image.Guid &&
                    ((material.Name != null && material.Name.Contains(term)) ||
                     (material.Code != null && material.Code.Contains(term)))));
        }

        var totalCount = await query.CountAsync(cancellationToken);
        var images = await query
            .OrderBy(image => image.Name)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var materialByImage = await GetLinkedMaterialDetailsByImageAsync(
            images.Select(image => image.Guid).ToArray(),
            cancellationToken);

        var items = images
            .Select(image =>
            {
                var fileName = ExtractFileName(image.Name) ?? string.Empty;
                materialByImage.TryGetValue(image.Guid, out var link);
                return new MaterialImageLinkFileResponse(
                    image.Guid,
                    fileName,
                    link is not null,
                    link?.MaterialGuid,
                    link?.MaterialName,
                    link?.MaterialCode);
            })
            .ToArray();

        return Ok(new PagedResponse<MaterialImageLinkFileResponse>(items, page, pageSize, totalCount));
    }

    [HttpPost("{sourceImageGuid:guid}/assign-to-materials")]
    [RequirePermission("materials.update")]
    public async Task<ActionResult<MaterialImageAssignResponse>> AssignToMaterials(
        Guid sourceImageGuid,
        [FromBody] MaterialImageAssignRequest request,
        CancellationToken cancellationToken)
    {
        if (sourceImageGuid == Guid.Empty)
        {
            return BadRequest(new { message = "Source image GUID is required." });
        }

        var materialGuids = (request.MaterialGuids ?? [])
            .Where(guid => guid != Guid.Empty)
            .Distinct()
            .Take(50)
            .ToArray();
        if (materialGuids.Length == 0)
        {
            return BadRequest(new { message = "At least one material GUID is required." });
        }

        var sourceImage = await mainDbContext.MaterialImages
            .AsNoTracking()
            .SingleOrDefaultAsync(image => image.Guid == sourceImageGuid, cancellationToken);
        if (sourceImage is null)
        {
            return NotFound(new { message = "Source image was not found." });
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var sourcePath = ResolveExistingImagePath(sourceImage.Name, settings.ImagesDirectory);
        if (string.IsNullOrWhiteSpace(sourcePath) || !System.IO.File.Exists(sourcePath))
        {
            return BadRequest(new { message = "Source image file was not found on disk." });
        }

        var materials = await mainDbContext.Materials
            .Where(material => materialGuids.Contains(material.Guid))
            .ToListAsync(cancellationToken);
        if (materials.Count == 0)
        {
            return BadRequest(new { message = "No valid materials were found for assignment." });
        }

        var extension = Path.GetExtension(sourcePath);
        var orderedMaterials = materials.OrderBy(material => material.Name).ToList();
        var createdImages = new List<MaterialImageRecord>(orderedMaterials.Count);
        var savedFiles = new List<string>(orderedMaterials.Count);
        var responses = new List<MaterialImageAssignItemResponse>(orderedMaterials.Count);

        try
        {
            foreach (var material in orderedMaterials)
            {
                cancellationToken.ThrowIfCancellationRequested();

                var preferredFileName = BuildMaterialImageFileName(material, extension);
                await PrepareMaterialImageSlotAsync(material, preferredFileName, cancellationToken);
                var storedFile = await imageStorageService.CopyFromPathAsync(
                    sourcePath,
                    preferredFileName,
                    replaceExisting: true,
                    cancellationToken);

                savedFiles.Add(storedFile.ImagePath);
                var image = new MaterialImageRecord
                {
                    Guid = Guid.NewGuid(),
                    Name = storedFile.ImagePath,
                };
                createdImages.Add(image);
            }

            mainDbContext.MaterialImages.AddRange(createdImages);
            await mainDbContext.SaveChangesAsync(cancellationToken);

            for (var index = 0; index < orderedMaterials.Count; index++)
            {
                var material = orderedMaterials[index];
                var image = createdImages[index];
                var linked = await LinkImageToMaterialInternalAsync(image.Guid, material.Guid, cancellationToken);
                if (!linked)
                {
                    return NotFound(new { message = "Material was not found during linking.", materialGuid = material.Guid });
                }

                responses.Add(new MaterialImageAssignItemResponse(
                    material.Guid,
                    material.Name ?? string.Empty,
                    material.Code,
                    image.Guid,
                    Path.GetFileName(image.Name ?? string.Empty)));
            }
        }
        catch
        {
            foreach (var savedFile in savedFiles)
            {
                imageStorageService.DeleteFile(savedFile);
            }

            throw;
        }

        if (responses.Count == orderedMaterials.Count)
        {
            await TryDeleteStagingSourceAfterAssignAsync(sourceImageGuid, cancellationToken);
        }

        return Ok(new MaterialImageAssignResponse(sourceImageGuid, responses));
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
    public Task<IActionResult> GetThumbnail(Guid id, CancellationToken cancellationToken) =>
        GetImageFile(id, cancellationToken);

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

        var createdImages = new List<MaterialImageRecord>(files.Count);
        var savedFiles = new List<string>(files.Count);
        MaterialRecord? materialToLink = null;
        if (effectiveMaterialGuid is Guid linkedMaterialGuid)
        {
            materialToLink = await mainDbContext.Materials
                .SingleOrDefaultAsync(material => material.Guid == linkedMaterialGuid, cancellationToken);
            if (materialToLink is null)
            {
                return BadRequest(new { message = "Material GUID is invalid.", materialGuid = linkedMaterialGuid });
            }
        }

        foreach (var file in files)
        {
            StoredImageFile storedFile;
            try
            {
                if (materialToLink is not null)
                {
                    var preferredFileName = BuildMaterialImageFileName(
                        materialToLink,
                        Path.GetExtension(Path.GetFileName(file.FileName)));
                    await PrepareMaterialImageSlotAsync(materialToLink, preferredFileName, cancellationToken);
                }

                storedFile = await imageStorageService.SaveAsync(
                    file,
                    replaceExisting: materialToLink is not null,
                    cancellationToken);
            }
            catch (InvalidOperationException exception)
            {
                foreach (var savedFile in savedFiles)
                {
                    imageStorageService.DeleteFile(savedFile);
                }

                return BadRequest(new { message = exception.Message, fileName = file.FileName });
            }

            savedFiles.Add(storedFile.ImagePath);
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
                imageStorageService.DeleteFile(savedFile);
            }

            throw;
        }

        if (effectiveMaterialGuid is Guid materialGuidToLink && materialToLink is not null)
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
            .Where(material => material.PictureGuid != null && material.PictureGuid != MaterialPictureGuid.Cleared)
            .ToListAsync(cancellationToken);

        if (linkedMaterials.Count == 0)
        {
            return NoContent();
        }

        foreach (var material in linkedMaterials)
        {
            material.PictureGuid = MaterialPictureGuid.Cleared;
        }

        await mainDbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpDelete("by-file")]
    [RequirePermission("materials.update")]
    public async Task<IActionResult> DeleteImageByFileName(
        [FromQuery] string fileName,
        CancellationToken cancellationToken)
    {
        fileName = Path.GetFileName(fileName.Trim());
        if (fileName is "")
        {
            return BadRequest(new { message = "fileName is required." });
        }

        var dbByFile = await LoadDbImagesByFileNamesAsync([fileName], cancellationToken);
        if (!dbByFile.TryGetValue(fileName, out var stub))
        {
            return NotFound(new { message = "Image file was not found in bm000.", fileName });
        }

        var image = await mainDbContext.MaterialImages
            .SingleOrDefaultAsync(item => item.Guid == stub.Guid, cancellationToken);
        if (image is null)
        {
            return NotFound(new { message = "Image file was not found in bm000.", fileName });
        }

        return await DeleteImageRecordAsync(image, cancellationToken);
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

        return await DeleteImageRecordAsync(image, cancellationToken);
    }

    private async Task<IActionResult> DeleteImageRecordAsync(
        MaterialImageRecord image,
        CancellationToken cancellationToken)
    {
        var deleted = await DeleteImageRecordInternalAsync(image, cancellationToken);
        if (!deleted)
        {
            return StatusCode(500, new { message = "Failed to delete image record from bm000." });
        }

        return NoContent();
    }

    private async Task<bool> DeleteImageRecordInternalAsync(
        MaterialImageRecord image,
        CancellationToken cancellationToken)
    {
        var id = image.Guid;
        var imageName = image.Name;

        var linkedMaterials = await mainDbContext.Materials
            .Where(material => material.PictureGuid == id)
            .ToListAsync(cancellationToken);
        foreach (var material in linkedMaterials)
        {
            material.PictureGuid = MaterialPictureGuid.Cleared;
        }

        mainDbContext.MaterialImages.Remove(image);
        await mainDbContext.SaveChangesAsync(cancellationToken);

        var stillExists = await mainDbContext.MaterialImages
            .AsNoTracking()
            .AnyAsync(item => item.Guid == id, cancellationToken);
        if (stillExists)
        {
            await mainDbContext.Database.ExecuteSqlRawAsync(
                "DELETE FROM bm000 WHERE [GUID] = {0}",
                id);
        }

        stillExists = await mainDbContext.MaterialImages
            .AsNoTracking()
            .AnyAsync(item => item.Guid == id, cancellationToken);
        if (stillExists)
        {
            return false;
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var imagePath = ResolveImagePath(imageName, settings.ImagesDirectory);
        imageStorageService.DeleteFile(imagePath);

        return true;
    }

    private async Task PrepareMaterialImageSlotAsync(
        MaterialRecord material,
        string preferredFileName,
        CancellationToken cancellationToken)
    {
        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var preferredBase = Path.GetFileName(preferredFileName);
        var codeBase = Path.GetFileNameWithoutExtension(preferredBase);
        var extension = Path.GetExtension(preferredBase);

        if (MaterialPictureGuid.HasImage(material.PictureGuid))
        {
            var linkedImage = await mainDbContext.MaterialImages
                .SingleOrDefaultAsync(item => item.Guid == material.PictureGuid!.Value, cancellationToken);
            if (linkedImage is not null)
            {
                await DeleteImageRecordInternalAsync(linkedImage, cancellationToken);
            }
        }

        var linkedImageGuids = await mainDbContext.Materials
            .AsNoTracking()
            .Where(item => item.PictureGuid != null && item.PictureGuid != MaterialPictureGuid.Cleared)
            .Select(item => item.PictureGuid!.Value)
            .ToListAsync(cancellationToken);

        var staleImages = await mainDbContext.MaterialImages
            .Where(image => image.Name != null)
            .ToListAsync(cancellationToken);
        foreach (var staleImage in staleImages)
        {
            if (linkedImageGuids.Contains(staleImage.Guid))
            {
                continue;
            }

            var fileName = ExtractFileName(staleImage.Name);
            if (fileName is null || !IsSameMaterialCodeFamily(fileName, codeBase, extension))
            {
                continue;
            }

            await DeleteImageRecordInternalAsync(staleImage, cancellationToken);
        }

        var targetPath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, preferredBase));
        if (System.IO.File.Exists(targetPath))
        {
            imageStorageService.DeleteFile(targetPath);
        }
    }

    private static bool IsSameMaterialCodeFamily(string fileName, string codeBase, string extension)
    {
        var name = Path.GetFileNameWithoutExtension(fileName);
        var fileExtension = Path.GetExtension(fileName);
        if (!fileExtension.Equals(extension, StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        if (string.Equals(name, codeBase, StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        if (!name.StartsWith(codeBase + "_", StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        var suffix = name[(codeBase.Length + 1)..];
        return suffix.Length > 0 && suffix.All(char.IsDigit);
    }

    private async Task TryDeleteStagingSourceAfterAssignAsync(
        Guid sourceImageGuid,
        CancellationToken cancellationToken)
    {
        var stillLinked = await mainDbContext.Materials
            .AsNoTracking()
            .AnyAsync(material => material.PictureGuid == sourceImageGuid, cancellationToken);
        if (stillLinked)
        {
            return;
        }

        var staging = await mainDbContext.MaterialImages
            .SingleOrDefaultAsync(image => image.Guid == sourceImageGuid, cancellationToken);
        if (staging is null)
        {
            return;
        }

        var imageName = staging.Name;
        mainDbContext.MaterialImages.Remove(staging);
        await mainDbContext.SaveChangesAsync(cancellationToken);

        var stillExists = await mainDbContext.MaterialImages
            .AsNoTracking()
            .AnyAsync(item => item.Guid == sourceImageGuid, cancellationToken);
        if (stillExists)
        {
            await mainDbContext.Database.ExecuteSqlRawAsync(
                "DELETE FROM bm000 WHERE [GUID] = {0}",
                sourceImageGuid);
        }

        var settings = await imageSettingsService.GetAsync(cancellationToken);
        var imagePath = ResolveExistingImagePath(imageName, settings.ImagesDirectory)
            ?? ResolveImagePath(imageName, settings.ImagesDirectory);
        imageStorageService.DeleteFile(imagePath);
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
            .Where(material => material.PictureGuid != null && material.PictureGuid != MaterialPictureGuid.Cleared);

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
            .Where(material => materialGuids.Contains(material.Guid) && material.PictureGuid != null && material.PictureGuid != MaterialPictureGuid.Cleared)
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
            .Where(material => material.PictureGuid != null && material.PictureGuid != MaterialPictureGuid.Cleared)
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
            .Where(material => material.PictureGuid != null && material.PictureGuid != MaterialPictureGuid.Cleared && imageGuids.Contains(material.PictureGuid.Value))
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
            linkedMaterial.PictureGuid = MaterialPictureGuid.Cleared;
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
            null,
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
        bool? isAvailable) =>
        MaterialStoreInventoryQuery.ApplyStoreAndQuantityFilters(
            mainDbContext,
            query,
            selectedStoreGuids,
            minWarehouseQuantity,
            maxWarehouseQuantity,
            isAvailable);

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

    private static string BuildMaterialImageFileName(MaterialRecord material, string extension)
    {
        var code = SanitizeFileName(material.Code ?? string.Empty);
        if (string.IsNullOrWhiteSpace(code))
        {
            code = "mat_" + material.Guid.ToString("N")[..8];
        }

        if (string.IsNullOrWhiteSpace(extension) || extension is ".")
        {
            extension = ".jpg";
        }

        return $"{code}{extension}";
    }

    private async Task<Dictionary<string, MaterialImageRecord>> LoadDbImagesByFileNamesAsync(
        string[] fileNames,
        CancellationToken cancellationToken)
    {
        var result = new Dictionary<string, MaterialImageRecord>(StringComparer.OrdinalIgnoreCase);
        foreach (var fileName in fileNames.Distinct(StringComparer.OrdinalIgnoreCase))
        {
            var candidates = await mainDbContext.MaterialImages
                .AsNoTracking()
                .Where(image => image.Name != null && image.Name.Contains(fileName))
                .ToListAsync(cancellationToken);

            var image = candidates
                .FirstOrDefault(candidate =>
                    string.Equals(ExtractFileName(candidate.Name), fileName, StringComparison.OrdinalIgnoreCase));
            if (image is not null)
            {
                result[fileName] = image;
            }
        }

        return result;
    }

    private sealed record MaterialLinkDetails(
        Guid MaterialGuid,
        string MaterialName,
        string? MaterialCode);

    private async Task<Dictionary<Guid, MaterialLinkDetails>> GetLinkedMaterialDetailsByImageAsync(
        Guid[] imageGuids,
        CancellationToken cancellationToken)
    {
        if (imageGuids.Length == 0)
        {
            return [];
        }

        var rows = await mainDbContext.Materials
            .AsNoTracking()
            .Where(material => material.PictureGuid != null && material.PictureGuid != MaterialPictureGuid.Cleared && imageGuids.Contains(material.PictureGuid.Value))
            .Select(material => new
            {
                ImageGuid = material.PictureGuid!.Value,
                MaterialGuid = material.Guid,
                material.Name,
                material.Code,
            })
            .ToListAsync(cancellationToken);

        return rows
            .GroupBy(row => row.ImageGuid)
            .ToDictionary(
                group => group.Key,
                group =>
                {
                    var first = group.OrderBy(item => item.MaterialGuid).First();
                    return new MaterialLinkDetails(
                        first.MaterialGuid,
                        first.Name ?? string.Empty,
                        first.Code);
                });
    }

    private static string[] SplitSearchTokens(string? value) =>
        string.IsNullOrWhiteSpace(value)
            ? []
            : value.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);

    private async Task<MaterialImageLookupBatchItemResponse> LookupFileOnAmineAsync(
        string fileName,
        string imagesDirectory,
        IReadOnlyDictionary<string, MaterialImageRecord> dbByFileName,
        CancellationToken cancellationToken)
    {
        if (dbByFileName.TryGetValue(fileName, out var image))
        {
            var imagePath = ResolveExistingImagePath(image.Name, imagesDirectory)
                ?? ResolveImagePath(image.Name, imagesDirectory);
            if (!System.IO.File.Exists(imagePath))
            {
                return new MaterialImageLookupBatchItemResponse(
                    fileName,
                    image.Guid,
                    0,
                    string.Empty,
                    false,
                    true);
            }

            var fileInfo = new FileInfo(imagePath);
            return new MaterialImageLookupBatchItemResponse(
                fileName,
                image.Guid,
                fileInfo.Length,
                ComputeSha256Hex(imagePath),
                true,
                true);
        }

        var directPath = Path.GetFullPath(Path.Combine(imagesDirectory, fileName));
        if (!System.IO.File.Exists(directPath))
        {
            return new MaterialImageLookupBatchItemResponse(
                fileName,
                null,
                0,
                string.Empty,
                false,
                false);
        }

        var directInfo = new FileInfo(directPath);
        var storedFileName = Path.GetFileName(directPath);
        var registered = new MaterialImageRecord
        {
            Guid = Guid.NewGuid(),
            Name = storedFileName,
        };
        mainDbContext.MaterialImages.Add(registered);
        await mainDbContext.SaveChangesAsync(cancellationToken);

        return new MaterialImageLookupBatchItemResponse(
            fileName,
            registered.Guid,
            directInfo.Length,
            ComputeSha256Hex(directPath),
            true,
            true);
    }

    private static string ComputeSha256Hex(string path)
    {
        using var stream = System.IO.File.OpenRead(path);
        var hash = System.Security.Cryptography.SHA256.HashData(stream);
        return Convert.ToHexString(hash).ToLowerInvariant();
    }
}
