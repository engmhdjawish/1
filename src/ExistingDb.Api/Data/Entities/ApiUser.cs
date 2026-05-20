namespace ExistingDb.Api.Data.Entities;

public sealed class ApiUser
{
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid? LegacyUserGuid { get; set; }
    public string UserName { get; set; } = string.Empty;
    public string NormalizedUserName { get; set; } = string.Empty;
    public string Email { get; set; } = string.Empty;
    public string NormalizedEmail { get; set; } = string.Empty;
    public string PasswordHash { get; set; } = string.Empty;
    public string DisplayName { get; set; } = string.Empty;
    public bool IsActive { get; set; } = true;
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? UpdatedAt { get; set; }
    public DateTimeOffset? LastLoginAt { get; set; }

    public ICollection<ApiUserRole> UserRoles { get; set; } = [];
    public ICollection<ApiRefreshToken> RefreshTokens { get; set; } = [];
}

