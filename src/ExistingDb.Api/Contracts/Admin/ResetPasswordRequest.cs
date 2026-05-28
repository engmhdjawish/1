using System.ComponentModel.DataAnnotations;

namespace ExistingDb.Api.Contracts.Admin;

public sealed class ResetPasswordRequest
{
    [Required]
    [MinLength(8)]
    public string NewPassword { get; init; } = string.Empty;
}

