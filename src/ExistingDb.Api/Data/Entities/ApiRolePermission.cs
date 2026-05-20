namespace ExistingDb.Api.Data.Entities;

public sealed class ApiRolePermission
{
    public int RoleId { get; set; }
    public int PermissionId { get; set; }

    public ApiRole? Role { get; set; }
    public ApiPermission? Permission { get; set; }
}

