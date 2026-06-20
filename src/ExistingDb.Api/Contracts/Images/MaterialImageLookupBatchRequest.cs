namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageLookupBatchItemRequest(
    string FileName,
    string? Sha256,
    long? SizeBytes);

public sealed record MaterialImageLookupBatchRequest(
    IReadOnlyList<MaterialImageLookupBatchItemRequest> Items);
