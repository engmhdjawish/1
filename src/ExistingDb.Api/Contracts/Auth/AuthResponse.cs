namespace ExistingDb.Api.Contracts.Auth;

public sealed record AuthResponse(
    Guid UserId,
    string UserName,
    string DisplayName,
    string AccessToken,
    DateTimeOffset AccessTokenExpiresAt,
    string RefreshToken,
    DateTimeOffset RefreshTokenExpiresAt,
    IReadOnlyCollection<string> Roles);

