using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Security.Cryptography;
using System.Text;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using ExistingDb.Api.Options;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Tokens;

namespace ExistingDb.Api.Auth;

public sealed class JwtTokenService(
    ApiManagementDbContext dbContext,
    IOptions<JwtOptions> jwtOptions) : ITokenService
{
    public async Task<TokenPair> CreateTokenPairAsync(ApiUser user, string? ipAddress, CancellationToken cancellationToken = default)
    {
        var options = jwtOptions.Value;
        var roles = await dbContext.UserRoles
            .Where(userRole => userRole.UserId == user.Id)
            .Select(userRole => userRole.Role!.Name)
            .ToListAsync(cancellationToken);

        var now = DateTimeOffset.UtcNow;
        var accessExpiresAt = now.AddMinutes(options.AccessTokenMinutes);
        var refreshExpiresAt = now.AddDays(options.RefreshTokenDays);
        var accessToken = CreateAccessToken(user, roles, now, accessExpiresAt, options);
        var refreshToken = CreateOpaqueToken();
        var refreshTokenHash = HashRefreshToken(refreshToken);

        dbContext.RefreshTokens.Add(new ApiRefreshToken
        {
            UserId = user.Id,
            TokenHash = refreshTokenHash,
            ExpiresAt = refreshExpiresAt,
            CreatedAt = now,
            CreatedByIp = ipAddress
        });

        await dbContext.SaveChangesAsync(cancellationToken);

        return new TokenPair(accessToken, accessExpiresAt, refreshToken, refreshExpiresAt);
    }

    public string HashRefreshToken(string refreshToken)
    {
        var hash = SHA256.HashData(Encoding.UTF8.GetBytes(refreshToken));
        return Convert.ToHexString(hash);
    }

    private static string CreateAccessToken(
        ApiUser user,
        IEnumerable<string> roles,
        DateTimeOffset issuedAt,
        DateTimeOffset expiresAt,
        JwtOptions options)
    {
        if (Encoding.UTF8.GetByteCount(options.SigningKey) < 32)
        {
            throw new InvalidOperationException("Jwt:SigningKey must be at least 32 bytes.");
        }

        var claims = new List<Claim>
        {
            new(JwtRegisteredClaimNames.Sub, user.Id.ToString()),
            new(JwtRegisteredClaimNames.UniqueName, user.UserName),
            new(JwtRegisteredClaimNames.Jti, Guid.NewGuid().ToString()),
            new(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new(ClaimTypes.Name, user.UserName)
        };

        if (user.LegacyUserGuid is not null)
        {
            claims.Add(new Claim(ApiClaimTypes.LegacyUserGuid, user.LegacyUserGuid.Value.ToString()));
        }

        claims.AddRange(roles.Select(role => new Claim(ClaimTypes.Role, role)));

        var key = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(options.SigningKey));
        var token = new JwtSecurityToken(
            issuer: options.Issuer,
            audience: options.Audience,
            claims: claims,
            notBefore: issuedAt.UtcDateTime,
            expires: expiresAt.UtcDateTime,
            signingCredentials: new SigningCredentials(key, SecurityAlgorithms.HmacSha256));

        return new JwtSecurityTokenHandler().WriteToken(token);
    }

    private static string CreateOpaqueToken()
    {
        return Convert.ToBase64String(RandomNumberGenerator.GetBytes(64));
    }
}

