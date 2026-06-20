namespace ExistingDb.Api.Contracts.Admin;

public sealed class UpdateUserRequest
{
    public bool? IsActive { get; init; }
    public string? DisplayName { get; init; }
    public string? Email { get; init; }
    public int[]? RoleIds { get; init; }
}
