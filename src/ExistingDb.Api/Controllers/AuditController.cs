using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Audit;
using ExistingDb.Api.Data;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/audit")]
public sealed class AuditController(ApiManagementDbContext dbContext) : ControllerBase
{
    [HttpGet]
    [RequirePermission("audit.read")]
    public async Task<ActionResult<IReadOnlyCollection<AuditLogResponse>>> GetAuditLogs(
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var logs = await dbContext.AuditLogs
            .OrderByDescending(log => log.CreatedAt)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .Select(log => new AuditLogResponse(
                log.Id,
                log.UserId,
                log.UserName,
                log.LegacyUserGuid,
                log.Action,
                log.EntityName,
                log.RecordId,
                log.RecordGuid,
                log.HttpMethod,
                log.Path,
                log.IpAddress,
                log.StatusCode,
                log.ErrorMessage,
                log.CreatedAt))
            .ToListAsync(cancellationToken);

        return Ok(logs);
    }
}

