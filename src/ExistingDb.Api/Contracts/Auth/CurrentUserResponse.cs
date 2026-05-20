namespace ExistingDb.Api.Contracts.Auth;

public sealed record CurrentUserResponse(
    Guid UserId,
    string UserName,
    Guid? LegacyUserGuid,
    IReadOnlyCollection<string> Roles);

