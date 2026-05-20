using ExistingDb.Api.Authorization;

namespace ExistingDb.Api.Data.Entities;

public sealed class ApiResourceField
{
    public int Id { get; set; }
    public int ResourceId { get; set; }
    public string FieldName { get; set; } = string.Empty;
    public string DisplayName { get; set; } = string.Empty;
    public bool IsSensitive { get; set; }
    public FieldAccessMode DefaultReadMode { get; set; } = FieldAccessMode.Allow;
    public bool DefaultCanCreate { get; set; } = true;
    public bool DefaultCanUpdate { get; set; } = true;
    public MaskingStrategy MaskingStrategy { get; set; } = MaskingStrategy.None;

    public ApiResource? Resource { get; set; }
    public ICollection<ApiFieldPermission> FieldPermissions { get; set; } = [];
}

