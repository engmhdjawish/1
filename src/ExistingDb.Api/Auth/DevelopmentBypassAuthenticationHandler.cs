using System.Security.Claims;
using System.Text.Encodings.Web;
using ExistingDb.Api.Options;
using Microsoft.AspNetCore.Authentication;
using Microsoft.Extensions.Options;

namespace ExistingDb.Api.Auth;

public sealed class DevelopmentBypassAuthenticationHandler(
    IOptionsMonitor<AuthenticationSchemeOptions> options,
    ILoggerFactory logger,
    UrlEncoder encoder,
    IOptions<DevelopmentAuthOptions> developmentAuthOptions)
    : AuthenticationHandler<AuthenticationSchemeOptions>(options, logger, encoder)
{
    public const string SchemeName = "DevelopmentBypass";

    protected override Task<AuthenticateResult> HandleAuthenticateAsync()
    {
        var configured = developmentAuthOptions.Value;
        var userId = Guid.TryParse(configured.UserId, out var parsedUserId)
            ? parsedUserId
            : Guid.Parse("11111111-1111-1111-1111-111111111111");
        var userName = string.IsNullOrWhiteSpace(configured.UserName)
            ? "dev-admin"
            : configured.UserName;
        var role = string.IsNullOrWhiteSpace(configured.Role)
            ? "Admin"
            : configured.Role;

        var claims = new[]
        {
            new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
            new Claim(ClaimTypes.Name, userName),
            new Claim(ClaimTypes.Role, role)
        };

        var identity = new ClaimsIdentity(claims, SchemeName);
        var principal = new ClaimsPrincipal(identity);
        var ticket = new AuthenticationTicket(principal, SchemeName);
        return Task.FromResult(AuthenticateResult.Success(ticket));
    }
}
