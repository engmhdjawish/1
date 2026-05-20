namespace ExistingDb.Api.Data.Entities;

public sealed class ApiPermission
{
    public int Id { get; set; }
    public string Code { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
    public string? Description { get; set; }
    public string Category { get; set; } = string.Empty;

    public ICollection<ApiRolePermission> RolePermissions { get; set; } = [];
}

