namespace ExistingDb.Api.Images;

public interface IImageStorageService
{
    Task<StoredImageFile> SaveAsync(IFormFile file, CancellationToken cancellationToken = default);
    void DeleteFile(string imagePath);
}

public sealed record StoredImageFile(
    string ImagePath,
    string StoredFileName,
    string ContentType,
    long SizeBytes);
