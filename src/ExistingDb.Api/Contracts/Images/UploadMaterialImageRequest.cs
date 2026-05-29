namespace ExistingDb.Api.Contracts.Images;

public sealed class UploadMaterialImageRequest
{
    public IFormFile? File { get; init; }
    public IReadOnlyCollection<IFormFile>? Files { get; init; }
    public Guid? MaterialGuid { get; init; }
}

