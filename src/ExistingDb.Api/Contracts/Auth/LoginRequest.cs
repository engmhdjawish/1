using System.ComponentModel.DataAnnotations;

namespace ExistingDb.Api.Contracts.Auth;

public sealed class LoginRequest
{
    [Required]
    public string UserName { get; init; } = string.Empty;

    [Required]
    public string Password { get; init; } = string.Empty;
}

