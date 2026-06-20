namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageLookupResponse(
    Guid? Id,
    string StoredFileName,
    long SizeBytes,
    string Sha256,
    bool FileExistsOnDisk);
