using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Images;

public sealed class ImageSettingsService(ApiManagementDbContext dbContext) : IImageSettingsService
{
    public async Task<ImageStorageSettings> GetAsync(CancellationToken cancellationToken = default)
    {
        var settings = await dbContext.Settings
            .Where(setting => setting.Key == ImageSettingsKeys.ImagesDirectory || setting.Key == ImageSettingsKeys.ThumbnailsDirectory)
            .ToDictionaryAsync(setting => setting.Key, setting => setting.Value, cancellationToken);

        var imagesDirectory = settings.GetValueOrDefault(ImageSettingsKeys.ImagesDirectory);
        if (string.IsNullOrWhiteSpace(imagesDirectory))
        {
            imagesDirectory = Path.Combine(AppContext.BaseDirectory, "images");
        }

        var thumbnailsDirectory = settings.GetValueOrDefault(ImageSettingsKeys.ThumbnailsDirectory);
        if (string.IsNullOrWhiteSpace(thumbnailsDirectory))
        {
            thumbnailsDirectory = Path.Combine(imagesDirectory, "thumbnails");
        }

        return new ImageStorageSettings(imagesDirectory, thumbnailsDirectory);
    }

    public async Task UpdateAsync(ImageStorageSettings settings, CancellationToken cancellationToken = default)
    {
        await UpsertAsync(
            ImageSettingsKeys.ImagesDirectory,
            settings.ImagesDirectory,
            "Directory where original material image files are uploaded.",
            cancellationToken);
        await UpsertAsync(
            ImageSettingsKeys.ThumbnailsDirectory,
            settings.ThumbnailsDirectory,
            "Directory where generated material image thumbnails are saved.",
            cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task UpsertAsync(string key, string? value, string description, CancellationToken cancellationToken)
    {
        var setting = await dbContext.Settings.SingleOrDefaultAsync(item => item.Key == key, cancellationToken);
        if (setting is null)
        {
            dbContext.Settings.Add(new ApiSetting
            {
                Key = key,
                Value = value,
                Description = description,
                CreatedAt = DateTimeOffset.UtcNow
            });
            return;
        }

        setting.Value = value;
        setting.Description = description;
        setting.UpdatedAt = DateTimeOffset.UtcNow;
    }
}

