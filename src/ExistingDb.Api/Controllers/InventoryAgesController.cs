using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Inventory;
using ExistingDb.Api.Services.Inventory;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/inventory/ages")]
[RequirePermission("materials.read")]
public sealed class InventoryAgesController(InventoryAgeQueryService inventoryAgeQueryService) : ControllerBase
{
    /// <summary>
    /// Stagnant inventory decision support: FIFO layers from dbo.repGetAges_bi
    /// filtered by Remaining &gt; 0 and Age &gt;= minAgeDays, enriched with material prices/images.
    /// </summary>
    [HttpGet]
    public async Task<ActionResult<InventoryAgePagedResponse>> GetAges(
        [FromQuery] int minAgeDays = 50,
        [FromQuery] DateTime? untilDate = null,
        [FromQuery] DateTime? yearStartDate = null,
        [FromQuery] string? groupGuids = null,
        [FromQuery] string? sort = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        var groups = ParseGuids(groupGuids);
        try
        {
            var result = await inventoryAgeQueryService.QueryAsync(
                User,
                minAgeDays,
                untilDate,
                yearStartDate,
                groups,
                sort,
                page,
                pageSize,
                cancellationToken);
            return Ok(result);
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }
    }

    private static List<Guid> ParseGuids(string? csv)
    {
        if (string.IsNullOrWhiteSpace(csv))
        {
            return [];
        }

        var result = new List<Guid>();
        foreach (var part in csv.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            if (Guid.TryParse(part, out var guid) && guid != Guid.Empty)
            {
                result.Add(guid);
            }
        }

        return result;
    }
}
