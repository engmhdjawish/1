using ExistingDb.Api.Data.Entities;

namespace ExistingDb.Api.Auth;

public interface ITokenService
{
    Task<TokenPair> CreateTokenPairAsync(ApiUser user, string? ipAddress, CancellationToken cancellationToken = default);
    string HashRefreshToken(string refreshToken);
}

public sealed record TokenPair(
    string AccessToken,
    DateTimeOffset AccessTokenExpiresAt,
    string RefreshToken,
    DateTimeOffset RefreshTokenExpiresAt);

