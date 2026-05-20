using System.Security.Claims;
using ExistingDb.Api.Data;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Authorization;

public sealed class PermissionService(ApiManagementDbContext dbContext) : IPermissionService
{
    public async Task<bool> HasPermissionAsync(ClaimsPrincipal user, string permissionCode, CancellationToken cancellationToken = default)
    {
        var userId = GetUserId(user);
        if (userId is null)
        {
            return false;
        }

        if (user.IsInRole("Admin"))
        {
            return true;
        }

        return await dbContext.UserRoles
            .Where(userRole => userRole.UserId == userId)
            .SelectMany(userRole => userRole.Role!.RolePermissions)
            .AnyAsync(rolePermission => rolePermission.Permission!.Code == permissionCode, cancellationToken);
    }

    public async Task<IReadOnlyDictionary<string, FieldAccessDecision>> GetFieldAccessAsync(
        ClaimsPrincipal user,
        string resourceCode,
        CancellationToken cancellationToken = default)
    {
        var userId = GetUserId(user);
        if (userId is null)
        {
            return new Dictionary<string, FieldAccessDecision>(StringComparer.OrdinalIgnoreCase);
        }

        var fields = await dbContext.ResourceFields
            .Where(field => field.Resource!.Code == resourceCode)
            .ToListAsync(cancellationToken);

        if (user.IsInRole("Admin"))
        {
            return fields.ToDictionary(
                field => field.FieldName,
                field => new FieldAccessDecision(field.FieldName, FieldAccessMode.Allow, true, true, field.MaskingStrategy),
                StringComparer.OrdinalIgnoreCase);
        }

        var roleIds = await dbContext.UserRoles
            .Where(userRole => userRole.UserId == userId)
            .Select(userRole => userRole.RoleId)
            .ToListAsync(cancellationToken);

        var explicitPermissions = await dbContext.FieldPermissions
            .Where(fieldPermission => roleIds.Contains(fieldPermission.RoleId))
            .Where(fieldPermission => fieldPermission.ResourceField!.Resource!.Code == resourceCode)
            .Select(fieldPermission => new
            {
                fieldPermission.ResourceFieldId,
                fieldPermission.ReadMode,
                fieldPermission.CanCreate,
                fieldPermission.CanUpdate
            })
            .ToListAsync(cancellationToken);

        return fields.ToDictionary(
            field => field.FieldName,
            field =>
            {
                var overrides = explicitPermissions
                    .Where(permission => permission.ResourceFieldId == field.Id)
                    .ToList();

                if (overrides.Count == 0)
                {
                    return new FieldAccessDecision(
                        field.FieldName,
                        field.DefaultReadMode,
                        field.DefaultCanCreate,
                        field.DefaultCanUpdate,
                        field.MaskingStrategy);
                }

                return new FieldAccessDecision(
                    field.FieldName,
                    overrides.Max(permission => permission.ReadMode),
                    overrides.Any(permission => permission.CanCreate),
                    overrides.Any(permission => permission.CanUpdate),
                    field.MaskingStrategy);
            },
            StringComparer.OrdinalIgnoreCase);
    }

    private static Guid? GetUserId(ClaimsPrincipal user)
    {
        var value = user.FindFirstValue(ClaimTypes.NameIdentifier);
        return Guid.TryParse(value, out var userId) ? userId : null;
    }
}

