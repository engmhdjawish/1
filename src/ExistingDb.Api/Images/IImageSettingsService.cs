namespace ExistingDb.Api.Images;

public interface IImageSettingsService
{
    Task<ImageStorageSettings> GetAsync(CancellationToken cancellationToken = default);
    Task UpdateAsync(ImageStorageSettings settings, CancellationToken cancellationToken = default);
}

public sealed record ImageStorageSettings(string ImagesDirectory);
