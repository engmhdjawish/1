using System.ComponentModel.DataAnnotations;

namespace ExistingDb.Api.Contracts.Admin;

public sealed class CreateUserRequest
{
    [Required]
    [MaxLength(100)]
    public string UserName { get; init; } = string.Empty;

    [Required]
    [EmailAddress]
    [MaxLength(255)]
    public string Email { get; init; } = string.Empty;

    [Required]
    [MinLength(8)]
    public string Password { get; init; } = string.Empty;

    [Required]
    [MaxLength(200)]
    public string DisplayName { get; init; } = string.Empty;

    public Guid? LegacyUserGuid { get; init; }
    public IReadOnlyCollection<int> RoleIds { get; init; } = [];
}

