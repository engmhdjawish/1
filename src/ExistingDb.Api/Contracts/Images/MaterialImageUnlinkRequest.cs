namespace ExistingDb.Api.Contracts.Images;

public sealed class MaterialImageUnlinkRequest
{
    public Guid? MaterialGuid { get; init; }
    public Guid? ImageGuid { get; init; }
}
