using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Customers;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/customers/{customerGuid:guid}/account")]
[RequirePermission("customers.read")]
[RequirePermission("accounts.read")]
public sealed class CustomerAccountsController(MainDbContext mainDbContext) : ControllerBase
{
    [HttpGet("summary")]
    public async Task<ActionResult<CustomerAccountSummaryResponse>> GetSummary(Guid customerGuid, CancellationToken cancellationToken)
    {
        var (customer, account) = await ResolveCustomerAccountAsync(customerGuid, cancellationToken);
        if (customer is null)
        {
            return NotFound(new { message = "Customer was not found.", customerGuid });
        }

        if (account is null)
        {
            return NotFound(new { message = "Customer account was not found.", customerGuid });
        }

        var lastCreditorEntry = await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.AccountGuid == account.Guid && (entry.Credit ?? 0) > 0)
            .OrderByDescending(entry => entry.Date)
            .ThenByDescending(entry => entry.Number)
            .ThenByDescending(entry => entry.Guid)
            .FirstOrDefaultAsync(cancellationToken);

        var lastDebtorEntry = await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.AccountGuid == account.Guid && (entry.Debit ?? 0) > 0)
            .OrderByDescending(entry => entry.Date)
            .ThenByDescending(entry => entry.Number)
            .ThenByDescending(entry => entry.Guid)
            .FirstOrDefaultAsync(cancellationToken);

        var entryGuids = new[] { lastCreditorEntry?.Guid, lastDebtorEntry?.Guid }
            .Where(guid => guid.HasValue)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();
        var references = await ResolveEntryReferencesAsync(entryGuids, cancellationToken);

        return Ok(new CustomerAccountSummaryResponse(
            customer.Guid,
            customer.CustomerName,
            account.Guid,
            account.Number,
            account.Code,
            account.Name,
            account.Debit ?? 0,
            account.Credit ?? 0,
            (account.Debit ?? 0) - (account.Credit ?? 0),
            lastCreditorEntry is null ? null : ToMovement(lastCreditorEntry, references.GetValueOrDefault(lastCreditorEntry.Guid)),
            lastDebtorEntry is null ? null : ToMovement(lastDebtorEntry, references.GetValueOrDefault(lastDebtorEntry.Guid))));
    }

    [HttpGet("statement")]
    [RequirePermission("entries.read")]
    public async Task<ActionResult<CustomerAccountStatementResponse>> GetStatement(
        Guid customerGuid,
        [FromQuery] DateTime? fromDate = null,
        [FromQuery] DateTime? toDate = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 100,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 500);

        var (customer, account) = await ResolveCustomerAccountAsync(customerGuid, cancellationToken);
        if (customer is null)
        {
            return NotFound(new { message = "Customer was not found.", customerGuid });
        }

        if (account is null)
        {
            return NotFound(new { message = "Customer account was not found.", customerGuid });
        }

        if (fromDate.HasValue && toDate.HasValue && fromDate.Value.Date > toDate.Value.Date)
        {
            return BadRequest(new { message = "fromDate must be less than or equal to toDate." });
        }

        var fromDateOnly = fromDate?.Date;
        var toExclusive = toDate?.Date.AddDays(1);
        var entriesQuery = mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.AccountGuid == account.Guid);

        if (fromDateOnly.HasValue)
        {
            entriesQuery = entriesQuery.Where(entry => entry.Date >= fromDateOnly.Value);
        }

        if (toExclusive.HasValue)
        {
            entriesQuery = entriesQuery.Where(entry => entry.Date < toExclusive.Value);
        }

        var orderedEntries = entriesQuery
            .OrderBy(entry => entry.Date)
            .ThenBy(entry => entry.Number)
            .ThenBy(entry => entry.Guid);

        var totalCount = await orderedEntries.CountAsync(cancellationToken);
        var offset = (page - 1) * pageSize;

        var openingBalance = fromDateOnly.HasValue
            ? await mainDbContext.Entries
                .AsNoTracking()
                .Where(entry => entry.AccountGuid == account.Guid && entry.Date < fromDateOnly.Value)
                .Select(entry => (double?)((entry.Debit ?? 0) - (entry.Credit ?? 0)))
                .SumAsync(cancellationToken) ?? 0
            : 0d;

        var balanceBeforePage = openingBalance;
        if (offset > 0)
        {
            var amountBeforePage = await orderedEntries
                .Take(offset)
                .Select(entry => (double?)((entry.Debit ?? 0) - (entry.Credit ?? 0)))
                .SumAsync(cancellationToken) ?? 0;
            balanceBeforePage += amountBeforePage;
        }

        var pageEntries = await orderedEntries
            .Skip(offset)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var references = await ResolveEntryReferencesAsync(pageEntries.Select(entry => entry.Guid).ToArray(), cancellationToken);

        var runningBalance = balanceBeforePage;
        var statementEntries = new List<CustomerAccountStatementEntryResponse>(pageEntries.Count);
        foreach (var entry in pageEntries)
        {
            var debit = entry.Debit ?? 0;
            var credit = entry.Credit ?? 0;
            var signedAmount = debit - credit;
            runningBalance += signedAmount;

            var reference = references.GetValueOrDefault(entry.Guid) ?? EntryReferenceInfo.Unknown;
            statementEntries.Add(new CustomerAccountStatementEntryResponse(
                entry.Guid,
                entry.Date,
                entry.Number,
                debit,
                credit,
                signedAmount,
                runningBalance,
                reference.ReasonType,
                reference.ReferenceGuid,
                reference.ReferenceNumber,
                reference.ReferenceDate,
                reference.ReferenceNotes,
                entry.Notes));
        }

        return Ok(new CustomerAccountStatementResponse(
            customer.Guid,
            customer.CustomerName,
            account.Guid,
            fromDateOnly,
            toDate?.Date,
            openingBalance,
            statementEntries,
            page,
            pageSize,
            totalCount));
    }

    private async Task<(CustomerRecord? Customer, AccountRecord? Account)> ResolveCustomerAccountAsync(Guid customerGuid, CancellationToken cancellationToken)
    {
        var customer = await mainDbContext.Customers
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == customerGuid, cancellationToken);
        if (customer?.AccountGuid is null || customer.AccountGuid == Guid.Empty)
        {
            return (customer, null);
        }

        var account = await mainDbContext.Accounts
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == customer.AccountGuid.Value, cancellationToken);
        return (customer, account);
    }

    private async Task<Dictionary<Guid, EntryReferenceInfo>> ResolveEntryReferencesAsync(
        IReadOnlyCollection<Guid> entryGuids,
        CancellationToken cancellationToken)
    {
        if (entryGuids.Count == 0)
        {
            return new Dictionary<Guid, EntryReferenceInfo>();
        }

        var billRelations = await mainDbContext.EntryBillRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid) && relation.BillGuid.HasValue)
            .ToListAsync(cancellationToken);

        var paymentRelations = await mainDbContext.EntryPaymentRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid) && relation.PaymentGuid.HasValue)
            .ToListAsync(cancellationToken);

        var noteRelations = await mainDbContext.EntryNoteRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid) && relation.NoteGuid.HasValue)
            .ToListAsync(cancellationToken);

        var collectedNoteRelations = await mainDbContext.EntryCollectedNoteRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid) && relation.NoteGuid.HasValue)
            .ToListAsync(cancellationToken);

        var billGuids = billRelations
            .Select(relation => relation.BillGuid!.Value)
            .Distinct()
            .ToArray();
        var paymentGuids = paymentRelations
            .Select(relation => relation.PaymentGuid!.Value)
            .Distinct()
            .ToArray();

        var bills = billGuids.Length == 0
            ? new List<BillHeaderRecord>()
            : await mainDbContext.Bills
                .AsNoTracking()
                .Where(bill => billGuids.Contains(bill.Guid))
                .ToListAsync(cancellationToken);
        var payments = paymentGuids.Length == 0
            ? new List<PaymentRecord>()
            : await mainDbContext.Payments
                .AsNoTracking()
                .Where(payment => paymentGuids.Contains(payment.Guid))
                .ToListAsync(cancellationToken);

        var billLookup = bills.ToDictionary(item => item.Guid);
        var paymentLookup = payments.ToDictionary(item => item.Guid);
        var billByEntry = billRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.BillGuid!.Value).First());
        var paymentByEntry = paymentRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.PaymentGuid!.Value).First());
        var noteByEntry = noteRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.NoteGuid!.Value).First());
        var collectedNoteByEntry = collectedNoteRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.NoteGuid!.Value).First());

        var result = new Dictionary<Guid, EntryReferenceInfo>();
        foreach (var entryGuid in entryGuids)
        {
            if (billByEntry.TryGetValue(entryGuid, out var billGuid))
            {
                billLookup.TryGetValue(billGuid, out var bill);
                result[entryGuid] = new EntryReferenceInfo(
                    "invoice",
                    billGuid,
                    bill?.Number,
                    bill?.Date,
                    bill?.Notes);
                continue;
            }

            if (paymentByEntry.TryGetValue(entryGuid, out var paymentGuid))
            {
                paymentLookup.TryGetValue(paymentGuid, out var payment);
                result[entryGuid] = new EntryReferenceInfo(
                    "payment",
                    paymentGuid,
                    payment?.Number,
                    payment?.Date,
                    payment?.Notes);
                continue;
            }

            if (noteByEntry.TryGetValue(entryGuid, out var noteGuid))
            {
                result[entryGuid] = new EntryReferenceInfo("discount", noteGuid, null, null, null);
                continue;
            }

            if (collectedNoteByEntry.TryGetValue(entryGuid, out var collectedNoteGuid))
            {
                result[entryGuid] = new EntryReferenceInfo("discount", collectedNoteGuid, null, null, null);
                continue;
            }

            result[entryGuid] = EntryReferenceInfo.Unknown;
        }

        return result;
    }

    private static CustomerAccountMovementResponse ToMovement(EntryRecord entry, EntryReferenceInfo? reference)
    {
        var debit = entry.Debit ?? 0;
        var credit = entry.Credit ?? 0;
        var effectiveReference = reference ?? EntryReferenceInfo.Unknown;
        return new CustomerAccountMovementResponse(
            entry.Guid,
            entry.Date,
            entry.Number,
            entry.Debit,
            entry.Credit,
            debit - credit,
            effectiveReference.ReasonType,
            effectiveReference.ReferenceGuid,
            effectiveReference.ReferenceNumber,
            effectiveReference.ReferenceDate,
            effectiveReference.ReferenceNotes,
            entry.Notes);
    }

    private sealed record EntryReferenceInfo(
        string ReasonType,
        Guid? ReferenceGuid,
        int? ReferenceNumber,
        DateTime? ReferenceDate,
        string? ReferenceNotes)
    {
        public static readonly EntryReferenceInfo Unknown = new("unknown", null, null, null, null);
    }
}
