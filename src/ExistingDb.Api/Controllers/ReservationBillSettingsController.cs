using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Bills;
using ExistingDb.Api.Data;
using ExistingDb.Api.Services.Bills;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/bills")]
public sealed class ReservationBillSettingsController(
    MainDbContext mainDbContext,
    IReservationBillSettingsService settingsService,
    IReservationBillPatternReader patternReader) : ControllerBase
{
    [HttpGet("reservation-patterns")]
    [RequirePermission("bills.read")]
    public async Task<ActionResult<IReadOnlyList<ReservationBillTypeListItemResponse>>> ListPatterns(
        CancellationToken cancellationToken)
    {
        var items = await mainDbContext.BillTypes
            .AsNoTracking()
            .OrderBy(type => type.Name)
            .ThenBy(type => type.LatinName)
            .Select(type => new ReservationBillTypeListItemResponse(type.Guid, type.Name, type.LatinName))
            .ToListAsync(cancellationToken);
        return Ok(items);
    }

    [HttpGet("reservation-patterns/{billTypeGuid:guid}")]
    [RequirePermission("bills.read")]
    public async Task<ActionResult<ReservationBillPatternResponse>> GetPattern(
        Guid billTypeGuid,
        CancellationToken cancellationToken)
    {
        var settings = await settingsService.GetAsync(cancellationToken);
        var pattern = await patternReader.ReadAsync(billTypeGuid, cancellationToken);
        if (pattern is null)
        {
            return NotFound(new { message = "Bill type was not found." });
        }

        return Ok(ReservationBillPatternPresenter.Present(pattern, settings.OwnerNameSource));
    }

    [HttpGet("reservation-settings")]
    [RequirePermission("bills.read")]
    public async Task<ActionResult<ReservationBillSettingsResponse>> GetSettings(CancellationToken cancellationToken)
    {
        var settings = await settingsService.GetAsync(cancellationToken);
        ReservationBillPatternResponse? pattern = null;
        if (settings.BillTypeGuid is { } billTypeGuid && billTypeGuid != Guid.Empty)
        {
            var read = await patternReader.ReadAsync(billTypeGuid, cancellationToken);
            if (read is not null)
            {
                pattern = ReservationBillPatternPresenter.Present(read, settings.OwnerNameSource);
            }
        }

        return Ok(new ReservationBillSettingsResponse(
            settings.BillTypeGuid,
            settings.OwnerNameSource,
            settings.SyncMode,
            pattern));
    }

    [HttpPut("reservation-settings")]
    [RequirePermission("bills.reserve")]
    public async Task<ActionResult<ReservationBillSettingsResponse>> SaveSettings(
        [FromBody] SaveReservationBillSettingsRequest? request,
        CancellationToken cancellationToken)
    {
        if (request is null || request.BillTypeGuid == Guid.Empty)
        {
            return BadRequest(new { message = "A bill type is required." });
        }

        if (!ReservationOwnerNameSources.IsAllowed(request.OwnerNameSource))
        {
            return BadRequest(new { message = "Owner name source is not one of the changeable options." });
        }

        if (!string.IsNullOrWhiteSpace(request.SyncMode) && !ReservationSyncModes.IsAllowed(request.SyncMode))
        {
            return BadRequest(new { message = "Sync mode must be automatic or manual." });
        }

        var pattern = await patternReader.ReadAsync(request.BillTypeGuid, cancellationToken);
        if (pattern is null)
        {
            return NotFound(new { message = "Bill type was not found." });
        }

        var ownerNameSource = request.OwnerNameSource!.Trim();
        var syncMode = string.IsNullOrWhiteSpace(request.SyncMode)
            ? (await settingsService.GetAsync(cancellationToken)).SyncMode
            : request.SyncMode.Trim();
        await settingsService.SaveAsync(request.BillTypeGuid, ownerNameSource, syncMode, cancellationToken);
        return Ok(new ReservationBillSettingsResponse(
            request.BillTypeGuid,
            ownerNameSource,
            syncMode,
            ReservationBillPatternPresenter.Present(pattern, ownerNameSource)));
    }
}
