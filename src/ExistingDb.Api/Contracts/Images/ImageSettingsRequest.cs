namespace ExistingDb.Api.Contracts.Images;

public sealed class ImageSettingsRequest
{
    public string ImagesDirectory { get; init; } = string.Empty;
    public string ThumbnailsDirectory { get; init; } = string.Empty;
}

