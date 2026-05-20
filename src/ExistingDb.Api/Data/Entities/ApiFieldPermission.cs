using ExistingDb.Api.Authorization;

namespace ExistingDb.Api.Data.Entities;

public sealed class ApiFieldPermission
{
    public int RoleId { get; set; }
    public int ResourceFieldId { get; set; }
    public FieldAccessMode ReadMode { get; set; } = FieldAccessMode.Allow;
    public bool CanCreate { get; set; } = true;
    public bool CanUpdate { get; set; } = true;

    public ApiRole? Role { get; set; }
    public ApiResourceField? ResourceField { get; set; }
}

