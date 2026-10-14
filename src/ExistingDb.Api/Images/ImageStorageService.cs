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

    public async Task<StoredImageFile> SaveAsync(
        IFormFile file,
        bool replaceExisting = false,
        CancellationToken cancellationToken = default,
        string? preferredFileName = null)
    {
        if (file.Length == 0)
        {
            throw new InvalidOperationException("Image file is empty.");
        }

        var requestedName = string.IsNullOrWhiteSpace(preferredFileName)
            ? file.FileName
            : preferredFileName;
        var originalFileName = Path.GetFileName(requestedName);
        var extension = Path.GetExtension(originalFileName);
        if (!AllowedExtensions.Contains(extension))
        {
            throw new InvalidOperationException("Unsupported image extension.");
        }

        var settings = await settingsService.GetAsync(cancellationToken);
        Directory.CreateDirectory(settings.ImagesDirectory);

        var sanitizedFileName = SanitizeFileName(originalFileName);
        var storedFileName = replaceExisting
            ? sanitizedFileName
            : GetAvailableFileName(settings.ImagesDirectory, sanitizedFileName);
        var imagePath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, storedFileName));
        var tempPath = imagePath + ".writing";
        try
        {
            await using (var stream = File.Create(tempPath))
            {
                await file.CopyToAsync(stream, cancellationToken);
            }

            var written = new FileInfo(tempPath);
            if (!written.Exists || written.Length <= 0)
            {
                throw new InvalidOperationException("Copied image file is empty.");
            }

            File.Move(tempPath, imagePath, overwrite: true);
        }
        catch
        {
            TryDelete(tempPath);
            throw;
        }

        var stored = new FileInfo(imagePath);
        return new StoredImageFile(
            imagePath,
            storedFileName,
            string.IsNullOrWhiteSpace(file.ContentType) ? "application/octet-stream" : file.ContentType,
            stored.Length);
    }

    public async Task<StoredImageFile> CopyFromPathAsync(
        string sourcePath,
        string preferredFileName,
        bool replaceExisting = false,
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
        var sourceFull = Path.GetFullPath(sourcePath);
        var preferredPath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, baseName));
        if (string.Equals(sourceFull, preferredPath, StringComparison.OrdinalIgnoreCase))
        {
            var sameFile = new FileInfo(preferredPath);
            if (sameFile.Length <= 0)
            {
                throw new InvalidOperationException("Source image file is empty.");
            }

            return new StoredImageFile(
                preferredPath,
                baseName,
                GetContentType(preferredPath),
                sameFile.Length);
        }

        var storedFileName = replaceExisting
            ? baseName
            : GetAvailableFileName(settings.ImagesDirectory, baseName);
        var imagePath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, storedFileName));
        var tempPath = imagePath + ".writing";
        try
        {
            await using (var sourceStream = File.OpenRead(sourcePath))
            await using (var targetStream = File.Create(tempPath))
            {
                await sourceStream.CopyToAsync(targetStream, cancellationToken);
            }

            var written = new FileInfo(tempPath);
            if (!written.Exists || written.Length <= 0)
            {
                throw new InvalidOperationException("Copied image file is empty.");
            }

            File.Move(tempPath, imagePath, overwrite: true);
        }
        catch
        {
            TryDelete(tempPath);
            throw;
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
