using ExistingDb.Api.Authorization;

namespace ExistingDb.Api.Contracts.Admin;

public sealed class UpdateFieldPermissionRequest
{
    public FieldAccessMode ReadMode { get; init; } = FieldAccessMode.Allow;
    public bool CanCreate { get; init; } = true;
    public bool CanUpdate { get; init; } = true;
}

