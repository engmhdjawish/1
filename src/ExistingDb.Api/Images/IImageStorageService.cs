using ExistingDb.Api.Data.Entities;

namespace ExistingDb.Api.Images;

public interface IImageStorageService
{
    Task<StoredImageFile> SaveAsync(IFormFile file, CancellationToken cancellationToken = default);
    void DeleteFiles(ApiMaterialImage image);
}

public sealed record StoredImageFile(
    string ImagePath,
    string? ThumbnailPath,
    string StoredFileName,
    string ContentType,
    long SizeBytes,
    int Width,
    int Height,
    int ThumbnailWidth,
    int ThumbnailHeight);

