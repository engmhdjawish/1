namespace ExistingDb.Api.Data.Entities;

public sealed class ApiRole
{
    public int Id { get; set; }
    public string Name { get; set; } = string.Empty;
    public string NormalizedName { get; set; } = string.Empty;
    public string? Description { get; set; }
    public bool IsSystemRole { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public ICollection<ApiUserRole> UserRoles { get; set; } = [];
    public ICollection<ApiRolePermission> RolePermissions { get; set; } = [];
    public ICollection<ApiFieldPermission> FieldPermissions { get; set; } = [];
}

