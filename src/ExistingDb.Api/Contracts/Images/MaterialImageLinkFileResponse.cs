namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageLinkFileResponse(
    Guid ImageGuid,
    string FileName,
    bool IsLinkedToMaterial,
    Guid? MaterialGuid,
    string? MaterialName,
    string? MaterialCode);
