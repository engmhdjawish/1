namespace ExistingDb.Api.Contracts.Admin;

public sealed class UpdateRolePermissionsRequest
{
    public IReadOnlyCollection<int> PermissionIds { get; init; } = [];
}

