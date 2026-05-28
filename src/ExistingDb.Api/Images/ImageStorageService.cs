using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;

namespace ExistingDb.Api.Images;

#pragma warning disable CA1416
public sealed class ImageStorageService(IImageSettingsService settingsService) : IImageStorageService
{
    private const int ThumbnailMaxSize = 300;
    private static readonly HashSet<string> AllowedExtensions = new(StringComparer.OrdinalIgnoreCase)
    {
        ".jpg",
        ".jpeg",
        ".png",
        ".gif"
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
        Directory.CreateDirectory(settings.ThumbnailsDirectory);

        var storedFileName = GetAvailableFileName(settings.ImagesDirectory, SanitizeFileName(originalFileName));
        var imagePath = Path.GetFullPath(Path.Combine(settings.ImagesDirectory, storedFileName));
        var thumbnailPath = Path.GetFullPath(Path.Combine(settings.ThumbnailsDirectory, storedFileName));

        await using (var stream = File.Create(imagePath))
        {
            await file.CopyToAsync(stream, cancellationToken);
        }

        try
        {
            using var image = Image.FromFile(imagePath);
            var width = image.Width;
            var height = image.Height;

            using var thumbnail = CreateThumbnail(image);
            thumbnail.Save(thumbnailPath, GetImageFormat(extension));

            return new StoredImageFile(
                imagePath,
                thumbnailPath,
                storedFileName,
                string.IsNullOrWhiteSpace(file.ContentType) ? "application/octet-stream" : file.ContentType,
                file.Length,
                width,
                height,
                thumbnail.Width,
                thumbnail.Height);
        }
        catch
        {
            TryDelete(imagePath);
            TryDelete(thumbnailPath);
            throw;
        }
    }

    private static Bitmap CreateThumbnail(Image image)
    {
        var ratio = Math.Min((double)ThumbnailMaxSize / image.Width, (double)ThumbnailMaxSize / image.Height);
        ratio = Math.Min(1, ratio);
        var width = Math.Max(1, (int)Math.Round(image.Width * ratio));
        var height = Math.Max(1, (int)Math.Round(image.Height * ratio));
        var thumbnail = new Bitmap(width, height);

        using var graphics = Graphics.FromImage(thumbnail);
        graphics.CompositingQuality = CompositingQuality.HighQuality;
        graphics.InterpolationMode = InterpolationMode.HighQualityBicubic;
        graphics.SmoothingMode = SmoothingMode.HighQuality;
        graphics.DrawImage(image, 0, 0, width, height);

        return thumbnail;
    }

    private static ImageFormat GetImageFormat(string extension)
    {
        return extension.ToLowerInvariant() switch
        {
            ".jpg" or ".jpeg" => ImageFormat.Jpeg,
            ".png" => ImageFormat.Png,
            ".gif" => ImageFormat.Gif,
            _ => ImageFormat.Jpeg
        };
    }

    public void DeleteFiles(string imagePath, string? thumbnailPath)
    {
        TryDelete(imagePath);
        if (!string.IsNullOrWhiteSpace(thumbnailPath))
        {
            TryDelete(thumbnailPath);
        }
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
}
#pragma warning restore CA1416

