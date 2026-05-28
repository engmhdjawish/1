using System.ComponentModel.DataAnnotations;

namespace ExistingDb.Api.Contracts.Auth;

public sealed class ChangePasswordRequest
{
    [Required]
    public string CurrentPassword { get; init; } = string.Empty;

    [Required]
    [MinLength(8)]
    public string NewPassword { get; init; } = string.Empty;
}

