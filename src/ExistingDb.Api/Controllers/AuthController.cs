using System.Security.Claims;
using ExistingDb.Api.Auth;
using ExistingDb.Api.Contracts.Auth;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Route("api/auth")]
public sealed class AuthController(IAuthService authService) : ControllerBase
{
    [HttpPost("login")]
    [AllowAnonymous]
    public async Task<ActionResult<AuthResponse>> Login(LoginRequest request, CancellationToken cancellationToken)
    {
        var result = await authService.LoginAsync(request.UserName, request.Password, GetIpAddress(), cancellationToken);
        if (result is null)
        {
            return Unauthorized();
        }

        return Ok(ToResponse(result));
    }

    [HttpPost("refresh")]
    [AllowAnonymous]
    public async Task<ActionResult<AuthResponse>> Refresh(RefreshTokenRequest request, CancellationToken cancellationToken)
    {
        var result = await authService.RefreshAsync(request.RefreshToken, GetIpAddress(), cancellationToken);
        if (result is null)
        {
            return Unauthorized();
        }

        return Ok(ToResponse(result));
    }

    [HttpPost("logout")]
    [Authorize]
    public async Task<IActionResult> Logout(RefreshTokenRequest request, CancellationToken cancellationToken)
    {
        await authService.LogoutAsync(request.RefreshToken, cancellationToken);
        return NoContent();
    }

    [HttpPost("change-password")]
    [Authorize]
    public async Task<IActionResult> ChangePassword(ChangePasswordRequest request, CancellationToken cancellationToken)
    {
        var userIdValue = User.FindFirstValue(ClaimTypes.NameIdentifier);
        if (!Guid.TryParse(userIdValue, out var userId))
        {
            return Unauthorized();
        }

        var changed = await authService.ChangePasswordAsync(userId, request.CurrentPassword, request.NewPassword, cancellationToken);
        if (!changed)
        {
            return BadRequest(new { message = "Current password is invalid." });
        }

        return NoContent();
    }

    [HttpGet("me")]
    [Authorize]
    public ActionResult<CurrentUserResponse> Me()
    {
        var userIdValue = User.FindFirstValue(ClaimTypes.NameIdentifier);
        if (!Guid.TryParse(userIdValue, out var userId))
        {
            return Unauthorized();
        }

        var legacyUserGuidValue = User.FindFirstValue(ApiClaimTypes.LegacyUserGuid);
        var legacyUserGuid = Guid.TryParse(legacyUserGuidValue, out var parsedLegacyGuid) ? parsedLegacyGuid : (Guid?)null;
        var roles = User.FindAll(ClaimTypes.Role).Select(claim => claim.Value).ToArray();

        return Ok(new CurrentUserResponse(userId, User.Identity?.Name ?? string.Empty, legacyUserGuid, roles));
    }

    private string? GetIpAddress() => HttpContext.Connection.RemoteIpAddress?.ToString();

    private static AuthResponse ToResponse(AuthResult result) =>
        new(
            result.UserId,
            result.UserName,
            result.DisplayName,
            result.AccessToken,
            result.AccessTokenExpiresAt,
            result.RefreshToken,
            result.RefreshTokenExpiresAt,
            result.Roles);
}

