using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Bills;
using ExistingDb.Api.Services.Bills;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/bills/reservations")]
[RequirePermission("bills.reserve")]
public sealed class ReservationBillsController(IReservationBillService reservationBillService) : ControllerBase
{
    [HttpPost]
    public async Task<ActionResult<ReservationBillResponse>> Create(
        [FromBody] ReservationBillWriteRequest? request,
        CancellationToken cancellationToken)
    {
        var execution = await reservationBillService.CreateAsync(request, cancellationToken);
        return ToActionResult(execution);
    }

    [HttpPut("{guid:guid}")]
    public async Task<ActionResult<ReservationBillResponse>> Update(
        Guid guid,
        [FromBody] ReservationBillWriteRequest? request,
        CancellationToken cancellationToken)
    {
        var execution = await reservationBillService.UpdateAsync(guid, request, cancellationToken);
        return ToActionResult(execution);
    }

    [HttpDelete("{guid:guid}")]
    public async Task<ActionResult<ReservationBillResponse>> Delete(Guid guid, CancellationToken cancellationToken)
    {
        var execution = await reservationBillService.DeleteAsync(guid, cancellationToken);
        return ToActionResult(execution);
    }

    private ActionResult<ReservationBillResponse> ToActionResult(ReservationBillExecution execution)
    {
        if (execution.Decision.Succeeded && execution.Response is not null)
        {
            return Ok(execution.Response);
        }

        var message = execution.Decision.Message ?? "Reservation bill was not saved.";
        return execution.Decision.Failure switch
        {
            ReservationBillFailure.NotFound => NotFound(new { message }),
            ReservationBillFailure.NotReservation
                or ReservationBillFailure.HasLedgerRows
                or ReservationBillFailure.AssertFailed => Conflict(new { message }),
            _ => BadRequest(new { message })
        };
    }
}
