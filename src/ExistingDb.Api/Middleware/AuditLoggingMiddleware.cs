using System.Diagnostics;
using System.Security.Claims;
using ExistingDb.Api.Auth;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;

namespace ExistingDb.Api.Middleware;

public sealed class AuditLoggingMiddleware(RequestDelegate next, ILogger<AuditLoggingMiddleware> logger)
{
    public async Task InvokeAsync(HttpContext context, ApiManagementDbContext dbContext)
    {
        if (ShouldSkip(context))
        {
            await next(context);
            return;
        }

        string? errorMessage = null;
        var stopwatch = Stopwatch.StartNew();

        try
        {
            await next(context);
        }
        catch (Exception exception)
        {
            errorMessage = exception.Message;
            context.Response.StatusCode = StatusCodes.Status500InternalServerError;
            throw;
        }
        finally
        {
            stopwatch.Stop();
            try
            {
                dbContext.AuditLogs.Add(CreateAuditLog(context, errorMessage));
                await dbContext.SaveChangesAsync(context.RequestAborted);
            }
            catch (Exception exception)
            {
                logger.LogWarning(exception, "Failed to write API audit log after {ElapsedMilliseconds} ms.", stopwatch.ElapsedMilliseconds);
            }
        }
    }

    private static bool ShouldSkip(HttpContext context)
    {
        var path = context.Request.Path.Value ?? string.Empty;
        return path.StartsWith("/swagger", StringComparison.OrdinalIgnoreCase)
            || path.StartsWith("/favicon", StringComparison.OrdinalIgnoreCase)
            || path.StartsWith("/api/health", StringComparison.OrdinalIgnoreCase);
    }

    private static ApiAuditLog CreateAuditLog(HttpContext context, string? errorMessage)
    {
        var userIdValue = context.User.FindFirstValue(ClaimTypes.NameIdentifier);
        var legacyUserGuidValue = context.User.FindFirstValue(ApiClaimTypes.LegacyUserGuid);

        return new ApiAuditLog
        {
            UserId = Guid.TryParse(userIdValue, out var userId) ? userId : null,
            UserName = context.User.Identity?.Name,
            LegacyUserGuid = Guid.TryParse(legacyUserGuidValue, out var legacyUserGuid) ? legacyUserGuid : null,
            Action = BuildAction(context),
            HttpMethod = context.Request.Method,
            Path = context.Request.Path.Value ?? string.Empty,
            IpAddress = context.Connection.RemoteIpAddress?.ToString(),
            UserAgent = context.Request.Headers.UserAgent.ToString(),
            StatusCode = context.Response.StatusCode,
            ErrorMessage = errorMessage,
            CreatedAt = DateTimeOffset.UtcNow
        };
    }

    private static string BuildAction(HttpContext context)
    {
        var path = (context.Request.Path.Value ?? string.Empty).Trim('/').Replace('/', '.');
        return string.IsNullOrWhiteSpace(path)
            ? context.Request.Method.ToLowerInvariant()
            : $"{context.Request.Method.ToLowerInvariant()}.{path}";
    }
}

