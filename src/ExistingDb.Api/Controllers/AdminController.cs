using ExistingDb.Api.Auth;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Admin;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/admin")]
public sealed class AdminController(
    ApiManagementDbContext dbContext,
    IPasswordHasher passwordHasher) : ControllerBase
{
    [HttpGet("users")]
    [RequirePermission("admin.users.manage")]
    public async Task<ActionResult<IReadOnlyCollection<UserResponse>>> GetUsers(CancellationToken cancellationToken)
    {
        var users = await dbContext.Users
            .Include(user => user.UserRoles)
            .ThenInclude(userRole => userRole.Role)
            .OrderBy(user => user.UserName)
            .ToListAsync(cancellationToken);

        return Ok(users.Select(ToUserResponse).ToArray());
    }

    [HttpPost("users")]
    [RequirePermission("admin.users.manage")]
    public async Task<ActionResult<UserResponse>> CreateUser(CreateUserRequest request, CancellationToken cancellationToken)
    {
        var normalizedUserName = Normalize(request.UserName);
        var exists = await dbContext.Users.AnyAsync(user => user.NormalizedUserName == normalizedUserName, cancellationToken);
        if (exists)
        {
            return Conflict(new { message = "User name already exists." });
        }

        int[] roleIds = request.RoleIds.Count == 0 ? [4] : request.RoleIds.Distinct().ToArray();
        var validRoleCount = await dbContext.Roles.CountAsync(role => roleIds.Contains(role.Id), cancellationToken);
        if (validRoleCount != roleIds.Length)
        {
            return BadRequest(new { message = "One or more role IDs are invalid." });
        }

        var user = new ApiUser
        {
            UserName = request.UserName.Trim(),
            NormalizedUserName = normalizedUserName,
            Email = request.Email.Trim(),
            NormalizedEmail = Normalize(request.Email),
            PasswordHash = passwordHasher.HashPassword(request.Password),
            DisplayName = request.DisplayName.Trim(),
            LegacyUserGuid = request.LegacyUserGuid,
            IsActive = true,
            CreatedAt = DateTimeOffset.UtcNow
        };

        dbContext.Users.Add(user);
        foreach (var roleId in roleIds)
        {
            dbContext.UserRoles.Add(new ApiUserRole { UserId = user.Id, RoleId = roleId });
        }

        await dbContext.SaveChangesAsync(cancellationToken);

        var createdUser = await dbContext.Users
            .Include(apiUser => apiUser.UserRoles)
            .ThenInclude(userRole => userRole.Role)
            .SingleAsync(apiUser => apiUser.Id == user.Id, cancellationToken);

        return CreatedAtAction(nameof(GetUsers), new { id = user.Id }, ToUserResponse(createdUser));
    }

    [HttpPost("users/{userId:guid}/reset-password")]
    [RequirePermission("admin.users.manage")]
    public async Task<IActionResult> ResetPassword(Guid userId, ResetPasswordRequest request, CancellationToken cancellationToken)
    {
        var user = await dbContext.Users.SingleOrDefaultAsync(apiUser => apiUser.Id == userId, cancellationToken);
        if (user is null)
        {
            return NotFound();
        }

        user.PasswordHash = passwordHasher.HashPassword(request.NewPassword);
        user.UpdatedAt = DateTimeOffset.UtcNow;

        var now = DateTimeOffset.UtcNow;
        var activeTokens = await dbContext.RefreshTokens
            .Where(token => token.UserId == userId)
            .Where(token => token.RevokedAt == null && token.ExpiresAt > now)
            .ToListAsync(cancellationToken);

        foreach (var token in activeTokens)
        {
            token.RevokedAt = now;
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpGet("roles")]
    [RequirePermission("admin.permissions.read")]
    public async Task<ActionResult<IReadOnlyCollection<RoleResponse>>> GetRoles(CancellationToken cancellationToken)
    {
        var roles = await dbContext.Roles
            .OrderBy(role => role.Id)
            .Select(role => new RoleResponse(role.Id, role.Name, role.Description, role.IsSystemRole))
            .ToListAsync(cancellationToken);

        return Ok(roles);
    }

    [HttpGet("permissions")]
    [RequirePermission("admin.permissions.read")]
    public async Task<ActionResult<IReadOnlyCollection<PermissionResponse>>> GetPermissions(CancellationToken cancellationToken)
    {
        var permissions = await dbContext.Permissions
            .OrderBy(permission => permission.Category)
            .ThenBy(permission => permission.Code)
            .Select(permission => new PermissionResponse(permission.Id, permission.Code, permission.Name, permission.Category, permission.Description))
            .ToListAsync(cancellationToken);

        return Ok(permissions);
    }

    [HttpPut("roles/{roleId:int}/permissions")]
    [RequirePermission("admin.roles.manage")]
    public async Task<IActionResult> UpdateRolePermissions(
        int roleId,
        UpdateRolePermissionsRequest request,
        CancellationToken cancellationToken)
    {
        var roleExists = await dbContext.Roles.AnyAsync(role => role.Id == roleId, cancellationToken);
        if (!roleExists)
        {
            return NotFound();
        }

        var permissionIds = request.PermissionIds.Distinct().ToArray();
        var validPermissionCount = await dbContext.Permissions.CountAsync(permission => permissionIds.Contains(permission.Id), cancellationToken);
        if (validPermissionCount != permissionIds.Length)
        {
            return BadRequest(new { message = "One or more permission IDs are invalid." });
        }

        var existingPermissions = await dbContext.RolePermissions
            .Where(rolePermission => rolePermission.RoleId == roleId)
            .ToListAsync(cancellationToken);

        dbContext.RolePermissions.RemoveRange(existingPermissions);
        dbContext.RolePermissions.AddRange(permissionIds.Select(permissionId => new ApiRolePermission
        {
            RoleId = roleId,
            PermissionId = permissionId
        }));

        await dbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpPut("roles/{roleId:int}/field-permissions/{resourceFieldId:int}")]
    [RequirePermission("admin.roles.manage")]
    public async Task<IActionResult> UpsertFieldPermission(
        int roleId,
        int resourceFieldId,
        UpdateFieldPermissionRequest request,
        CancellationToken cancellationToken)
    {
        var roleExists = await dbContext.Roles.AnyAsync(role => role.Id == roleId, cancellationToken);
        var fieldExists = await dbContext.ResourceFields.AnyAsync(field => field.Id == resourceFieldId, cancellationToken);
        if (!roleExists || !fieldExists)
        {
            return NotFound();
        }

        var fieldPermission = await dbContext.FieldPermissions
            .SingleOrDefaultAsync(permission => permission.RoleId == roleId && permission.ResourceFieldId == resourceFieldId, cancellationToken);

        if (fieldPermission is null)
        {
            dbContext.FieldPermissions.Add(new ApiFieldPermission
            {
                RoleId = roleId,
                ResourceFieldId = resourceFieldId,
                ReadMode = request.ReadMode,
                CanCreate = request.CanCreate,
                CanUpdate = request.CanUpdate
            });
        }
        else
        {
            fieldPermission.ReadMode = request.ReadMode;
            fieldPermission.CanCreate = request.CanCreate;
            fieldPermission.CanUpdate = request.CanUpdate;
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return NoContent();
    }

    [HttpGet("resources")]
    [RequirePermission("admin.permissions.read")]
    public async Task<ActionResult<IReadOnlyCollection<ResourceResponse>>> GetResources(CancellationToken cancellationToken)
    {
        var resources = await dbContext.Resources
            .Include(resource => resource.Fields)
            .OrderBy(resource => resource.Code)
            .ToListAsync(cancellationToken);

        return Ok(resources.Select(resource => new ResourceResponse(
            resource.Id,
            resource.Code,
            resource.Name,
            resource.Description,
            resource.Fields
                .OrderBy(field => field.FieldName)
                .Select(field => new ResourceFieldResponse(
                    field.Id,
                    field.FieldName,
                    field.DisplayName,
                    field.IsSensitive,
                    field.DefaultReadMode,
                    field.DefaultCanCreate,
                    field.DefaultCanUpdate,
                    field.MaskingStrategy))
                .ToArray())).ToArray());
    }

    private static UserResponse ToUserResponse(ApiUser user)
    {
        var roles = user.UserRoles
            .Select(userRole => userRole.Role?.Name)
            .Where(role => !string.IsNullOrWhiteSpace(role))
            .Cast<string>()
            .OrderBy(role => role)
            .ToArray();

        return new UserResponse(
            user.Id,
            user.LegacyUserGuid,
            user.UserName,
            user.Email,
            user.DisplayName,
            user.IsActive,
            user.CreatedAt,
            user.LastLoginAt,
            roles);
    }

    private static string Normalize(string value) => value.Trim().ToUpperInvariant();
}

