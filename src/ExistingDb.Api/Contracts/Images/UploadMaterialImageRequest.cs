namespace ExistingDb.Api.Contracts.Images;

public sealed class UploadMaterialImageRequest
{
    public IFormFile? File { get; init; }
    public string? MaterialGuids { get; init; }
    public bool IsPrimary { get; init; }
}

