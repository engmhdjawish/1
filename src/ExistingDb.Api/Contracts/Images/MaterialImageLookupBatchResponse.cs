namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageLookupBatchItemResponse(
    string FileName,
    Guid? Id,
    long SizeBytes,
    string Sha256,
    bool FileExistsOnDisk,
    bool Found,
    Guid? MaterialGuid = null,
    string? MaterialName = null,
    string? MaterialCode = null);

public sealed record MaterialImageLookupBatchResponse(
    IReadOnlyList<MaterialImageLookupBatchItemResponse> Items);
