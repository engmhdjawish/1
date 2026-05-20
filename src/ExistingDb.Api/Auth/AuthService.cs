using ExistingDb.Api.Data;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Auth;

public sealed class AuthService(
    ApiManagementDbContext dbContext,
    IPasswordHasher passwordHasher,
    ITokenService tokenService) : IAuthService
{
    public async Task<AuthResult?> LoginAsync(string userName, string password, string? ipAddress, CancellationToken cancellationToken = default)
    {
        var normalizedUserName = Normalize(userName);
        var user = await dbContext.Users
            .Include(apiUser => apiUser.UserRoles)
            .ThenInclude(userRole => userRole.Role)
            .SingleOrDefaultAsync(apiUser => apiUser.NormalizedUserName == normalizedUserName, cancellationToken);

        if (user is null || !user.IsActive || !passwordHasher.VerifyPassword(password, user.PasswordHash))
        {
            return null;
        }

        user.LastLoginAt = DateTimeOffset.UtcNow;
        var tokens = await tokenService.CreateTokenPairAsync(user, ipAddress, cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        return ToAuthResult(user, tokens);
    }

    public async Task<AuthResult?> RefreshAsync(string refreshToken, string? ipAddress, CancellationToken cancellationToken = default)
    {
        var refreshTokenHash = tokenService.HashRefreshToken(refreshToken);
        var existingToken = await dbContext.RefreshTokens
            .Include(token => token.User)
            .ThenInclude(user => user!.UserRoles)
            .ThenInclude(userRole => userRole.Role)
            .SingleOrDefaultAsync(token => token.TokenHash == refreshTokenHash, cancellationToken);

        if (existingToken?.User is null || !existingToken.IsActive || !existingToken.User.IsActive)
        {
            return null;
        }

        existingToken.RevokedAt = DateTimeOffset.UtcNow;
        var tokens = await tokenService.CreateTokenPairAsync(existingToken.User, ipAddress, cancellationToken);
        existingToken.ReplacedByTokenHash = tokenService.HashRefreshToken(tokens.RefreshToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        return ToAuthResult(existingToken.User, tokens);
    }

    public async Task<bool> LogoutAsync(string refreshToken, CancellationToken cancellationToken = default)
    {
        var refreshTokenHash = tokenService.HashRefreshToken(refreshToken);
        var existingToken = await dbContext.RefreshTokens
            .SingleOrDefaultAsync(token => token.TokenHash == refreshTokenHash, cancellationToken);

        if (existingToken is null || existingToken.RevokedAt is not null)
        {
            return false;
        }

        existingToken.RevokedAt = DateTimeOffset.UtcNow;
        await dbContext.SaveChangesAsync(cancellationToken);
        return true;
    }

    private static AuthResult ToAuthResult(Data.Entities.ApiUser user, TokenPair tokens)
    {
        var roles = user.UserRoles
            .Select(userRole => userRole.Role?.Name)
            .Where(role => !string.IsNullOrWhiteSpace(role))
            .Cast<string>()
            .ToArray();

        return new AuthResult(
            user.Id,
            user.UserName,
            user.DisplayName,
            tokens.AccessToken,
            tokens.AccessTokenExpiresAt,
            tokens.RefreshToken,
            tokens.RefreshTokenExpiresAt,
            roles);
    }

    private static string Normalize(string value) => value.Trim().ToUpperInvariant();
}

