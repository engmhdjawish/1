using System.Security.Claims;

namespace ExistingDb.Api.Authorization;

public interface IPermissionService
{
    Task<bool> HasPermissionAsync(ClaimsPrincipal user, string permissionCode, CancellationToken cancellationToken = default);

    Task<IReadOnlyDictionary<string, FieldAccessDecision>> GetFieldAccessAsync(
        ClaimsPrincipal user,
        string resourceCode,
        CancellationToken cancellationToken = default);
}

public sealed record FieldAccessDecision(
    string FieldName,
    FieldAccessMode ReadMode,
    bool CanCreate,
    bool CanUpdate,
    MaskingStrategy MaskingStrategy);

