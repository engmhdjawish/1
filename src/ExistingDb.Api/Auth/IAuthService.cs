namespace ExistingDb.Api.Auth;

public interface IAuthService
{
    Task<AuthResult?> LoginAsync(string userName, string password, string? ipAddress, CancellationToken cancellationToken = default);
    Task<AuthResult?> RefreshAsync(string refreshToken, string? ipAddress, CancellationToken cancellationToken = default);
    Task<bool> LogoutAsync(string refreshToken, CancellationToken cancellationToken = default);
    Task<bool> ChangePasswordAsync(Guid userId, string currentPassword, string newPassword, CancellationToken cancellationToken = default);
}

public sealed record AuthResult(
    Guid UserId,
    string UserName,
    string DisplayName,
    string AccessToken,
    DateTimeOffset AccessTokenExpiresAt,
    string RefreshToken,
    DateTimeOffset RefreshTokenExpiresAt,
    IReadOnlyCollection<string> Roles);

