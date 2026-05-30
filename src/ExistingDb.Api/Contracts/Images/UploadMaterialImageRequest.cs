namespace ExistingDb.Api.Contracts.Images;

public sealed class UploadMaterialImageRequest
{
    public IReadOnlyCollection<IFormFile>? Files { get; init; }
    public Guid? MaterialGuid { get; init; }
}

