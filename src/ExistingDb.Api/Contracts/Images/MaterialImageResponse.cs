namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageResponse(
    Guid Id,
    string ImagePath,
    string? ThumbnailName,
    string FileName,
    string StoredFileName,
    string ContentType,
    long SizeBytes,
    Guid? MaterialGuid,
    DateTimeOffset CreatedAt,
    DateTimeOffset? UpdatedAt);

