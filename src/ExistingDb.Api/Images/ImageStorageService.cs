namespace ExistingDb.Api.Images;

public sealed class ImageStorageService(IImageSettingsService settingsService) : IImageStorageService
{
    private static readonly HashSet<string> AllowedExtensions = new(StringComparer.OrdinalIgnoreCase)
    {
        ".jpg",
        ".jpeg",
        ".png",
        ".gif",
        ".webp",
    };

    public async Task<StoredImageFile> SaveAsync(IFormFile file, CancellationToken cancellationToken = default)
    {
        if (file.Length == 0)
        {
            throw new InvalidOperationException("Image file is empty.");
        }

        var originalFileName = Path.GetFileName(file.FileName);
        var extension = Path.GetExtension(originalFileName);
        if (!AllowedExtensions.Contains(extension))
        {
            throw new InvalidOperationException("Unsupported image extension.");
        }

        var settings = await settingsService.GetAsync(cancellationToken);
        Directory.CreateDirectory(settings.ImagesDirectory);

        var storedFileName = GetAvailableFileName(settings.ImagesDirectory, SanitizeFileName(originalFileName));
        var imagePath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, storedFileName));

        await using (var stream = File.Create(imagePath))
        {
            await file.CopyToAsync(stream, cancellationToken);
        }

        return new StoredImageFile(
            imagePath,
            storedFileName,
            string.IsNullOrWhiteSpace(file.ContentType) ? "application/octet-stream" : file.ContentType,
            file.Length);
    }

    public async Task<StoredImageFile> CopyFromPathAsync(
        string sourcePath,
        string preferredFileName,
        CancellationToken cancellationToken = default)
    {
        if (!File.Exists(sourcePath))
        {
            throw new InvalidOperationException("Source image file was not found.");
        }

        var extension = Path.GetExtension(preferredFileName);
        if (!AllowedExtensions.Contains(extension))
        {
            extension = Path.GetExtension(sourcePath);
            if (!AllowedExtensions.Contains(extension))
            {
                throw new InvalidOperationException("Unsupported image extension.");
            }
        }

        var settings = await settingsService.GetAsync(cancellationToken);
        Directory.CreateDirectory(settings.ImagesDirectory);

        var baseName = SanitizeFileName(Path.GetFileNameWithoutExtension(preferredFileName) + extension);
        var storedFileName = GetAvailableFileName(settings.ImagesDirectory, baseName);
        var imagePath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, storedFileName));

        await using (var sourceStream = File.OpenRead(sourcePath))
        await using (var targetStream = File.Create(imagePath))
        {
            await sourceStream.CopyToAsync(targetStream, cancellationToken);
        }

        var fileInfo = new FileInfo(imagePath);
        return new StoredImageFile(
            imagePath,
            storedFileName,
            GetContentType(imagePath),
            fileInfo.Length);
    }

    public void DeleteFile(string imagePath)
    {
        TryDelete(imagePath);
    }

    private static string GetAvailableFileName(string directory, string fileName)
    {
        var name = Path.GetFileNameWithoutExtension(fileName);
        var extension = Path.GetExtension(fileName);
        var candidate = fileName;
        var counter = 1;

        while (File.Exists(Path.Combine(directory, candidate)))
        {
            candidate = $"{name}_{counter}{extension}";
            counter++;
        }

        return candidate;
    }

    private static string SanitizeFileName(string fileName)
    {
        var invalidChars = Path.GetInvalidFileNameChars();
        var sanitized = new string(fileName.Select(ch => invalidChars.Contains(ch) ? '_' : ch).ToArray());
        return string.IsNullOrWhiteSpace(sanitized)
            ? $"{Guid.NewGuid():N}.jpg"
            : sanitized;
    }

    private static void TryDelete(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch
        {
            // File cleanup is best effort; database consistency is handled separately.
        }
    }

    private static string GetContentType(string path)
    {
        return Path.GetExtension(path).ToLowerInvariant() switch
        {
            ".jpg" or ".jpeg" => "image/jpeg",
            ".png" => "image/png",
            ".gif" => "image/gif",
            ".webp" => "image/webp",
            _ => "application/octet-stream",
        };
    }
}
