namespace ExistingDb.Api.Contracts.Audit;

public sealed record AuditLogResponse(
    long Id,
    Guid? UserId,
    string? UserName,
    Guid? LegacyUserGuid,
    string Action,
    string? EntityName,
    string? RecordId,
    Guid? RecordGuid,
    string HttpMethod,
    string Path,
    string? IpAddress,
    int StatusCode,
    string? ErrorMessage,
    DateTimeOffset CreatedAt);

