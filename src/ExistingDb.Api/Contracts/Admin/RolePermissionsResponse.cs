namespace ExistingDb.Api.Contracts.Admin;

public sealed record RolePermissionsResponse(int RoleId, int[] PermissionIds);
