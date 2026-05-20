namespace ExistingDb.Api.Contracts.Admin;

public sealed record UserResponse(
    Guid Id,
    Guid? LegacyUserGuid,
    string UserName,
    string Email,
    string DisplayName,
    bool IsActive,
    DateTimeOffset CreatedAt,
    DateTimeOffset? LastLoginAt,
    IReadOnlyCollection<string> Roles);

