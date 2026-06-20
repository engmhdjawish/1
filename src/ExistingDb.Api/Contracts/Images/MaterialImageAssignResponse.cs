namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageAssignItemResponse(
    Guid MaterialGuid,
    string MaterialName,
    string? MaterialCode,
    Guid ImageGuid,
    string StoredFileName);

public sealed record MaterialImageAssignResponse(
    Guid SourceImageGuid,
    IReadOnlyList<MaterialImageAssignItemResponse> Items);
