namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageLookupBatchItemResponse(
    string FileName,
    Guid? Id,
    long SizeBytes,
    string Sha256,
    bool FileExistsOnDisk,
    bool Found);

public sealed record MaterialImageLookupBatchResponse(
    IReadOnlyList<MaterialImageLookupBatchItemResponse> Items);
