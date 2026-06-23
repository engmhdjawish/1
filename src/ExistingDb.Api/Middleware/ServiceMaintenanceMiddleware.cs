using ExistingDb.Api.Images;

namespace ExistingDb.Api.Middleware;

public sealed class ServiceMaintenanceMiddleware(RequestDelegate next)
{
    public async Task InvokeAsync(HttpContext context, IServiceSettingsService serviceSettingsService)
    {
        var path = context.Request.Path.Value ?? string.Empty;
        if (IsExempt(path))
        {
            await next(context);
            return;
        }

        var settings = await serviceSettingsService.GetAsync(context.RequestAborted);
        if (settings.Enabled)
        {
            await next(context);
            return;
        }

        context.Response.StatusCode = StatusCodes.Status503ServiceUnavailable;
        context.Response.ContentType = "application/json; charset=utf-8";
        await context.Response.WriteAsJsonAsync(new
        {
            message = "خدمة API الأمين متوقفة مؤقتاً.",
            code = "service_disabled",
        });
    }

    private static bool IsExempt(string path)
    {
        if (path.StartsWith("/api/health", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        if (path.StartsWith("/api/auth", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        if (path.StartsWith("/api/admin", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        if (path.StartsWith("/swagger", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        if (path.StartsWith("/portal", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        return false;
    }
}
