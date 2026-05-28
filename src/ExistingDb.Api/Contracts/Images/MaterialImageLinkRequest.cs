namespace ExistingDb.Api.Contracts.Images;

public sealed class MaterialImageLinkRequest
{
    public IReadOnlyCollection<Guid> MaterialGuids { get; init; } = [];
    public bool IsPrimary { get; init; }
}

