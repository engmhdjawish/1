namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageResponse(
    Guid Id,
    string Name,
    string? ThumbnailName,
    string OriginalFileName,
    string StoredFileName,
    string ContentType,
    long SizeBytes,
    int? Width,
    int? Height,
    int? ThumbnailWidth,
    int? ThumbnailHeight,
    IReadOnlyCollection<Guid> MaterialGuids,
    DateTimeOffset CreatedAt,
    DateTimeOffset? UpdatedAt);

