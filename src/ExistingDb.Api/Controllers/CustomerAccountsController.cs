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

        var accountCurrencyRate = GetAccountCurrencyRate(account);
        var defaultRate = accountCurrencyRate > 0 ? accountCurrencyRate : 1d;
        var initDebitInAccountCurrency = ConvertMainToAccountCurrency(account.InitDebit ?? 0, accountCurrencyRate);
        var initCreditInAccountCurrency = ConvertMainToAccountCurrency(account.InitCredit ?? 0, accountCurrencyRate);
        var debitFromEntriesInAccountCurrency = await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.AccountGuid == account.Guid)
            .Select(entry => (double?)((entry.Debit ?? 0) / (entry.CurrencyVal.HasValue && entry.CurrencyVal.Value > 0
                ? entry.CurrencyVal.Value
                : defaultRate)))
            .SumAsync(cancellationToken) ?? 0;
        var creditFromEntriesInAccountCurrency = await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.AccountGuid == account.Guid)
            .Select(entry => (double?)((entry.Credit ?? 0) / (entry.CurrencyVal.HasValue && entry.CurrencyVal.Value > 0
                ? entry.CurrencyVal.Value
                : defaultRate)))
            .SumAsync(cancellationToken) ?? 0;
        var currentDebit = initDebitInAccountCurrency + debitFromEntriesInAccountCurrency;
        var currentCredit = initCreditInAccountCurrency + creditFromEntriesInAccountCurrency;

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
            account.CurrencyGuid,
            accountCurrencyRate,
            currentDebit,
            currentCredit,
            currentDebit - currentCredit,
            lastCreditorEntry is null
                ? null
                : ToMovement(lastCreditorEntry, references.GetValueOrDefault(lastCreditorEntry.Guid), accountCurrencyRate),
            lastDebtorEntry is null
                ? null
                : ToMovement(lastDebtorEntry, references.GetValueOrDefault(lastDebtorEntry.Guid), accountCurrencyRate)));
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

        var accountCurrencyRate = GetAccountCurrencyRate(account);
        var defaultRate = accountCurrencyRate > 0 ? accountCurrencyRate : 1d;
        var initialBalanceInAccountCurrency = ConvertMainToAccountCurrency(
            (account.InitDebit ?? 0) - (account.InitCredit ?? 0),
            accountCurrencyRate);

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
                .Select(entry => (double?)(((entry.Debit ?? 0) - (entry.Credit ?? 0)) / (entry.CurrencyVal.HasValue && entry.CurrencyVal.Value > 0
                    ? entry.CurrencyVal.Value
                    : defaultRate)))
                .SumAsync(cancellationToken) ?? 0
            : 0d;
        var openingBalanceInAccountCurrency = initialBalanceInAccountCurrency + openingBalance;

        var balanceBeforePage = openingBalanceInAccountCurrency;
        if (offset > 0)
        {
            var amountBeforePage = await orderedEntries
                .Take(offset)
                .Select(entry => (double?)(((entry.Debit ?? 0) - (entry.Credit ?? 0)) / (entry.CurrencyVal.HasValue && entry.CurrencyVal.Value > 0
                    ? entry.CurrencyVal.Value
                    : defaultRate)))
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
            var debitMain = entry.Debit ?? 0;
            var creditMain = entry.Credit ?? 0;
            var conversionRate = ResolveConversionRate(entry.CurrencyVal, accountCurrencyRate);
            var debit = ConvertMainToAccountCurrency(debitMain, conversionRate);
            var credit = ConvertMainToAccountCurrency(creditMain, conversionRate);
            var signedAmount = debit - credit;
            runningBalance += signedAmount;

            var reference = references.GetValueOrDefault(entry.Guid) ?? EntryReferenceInfo.Unknown;
            statementEntries.Add(new CustomerAccountStatementEntryResponse(
                entry.Guid,
                entry.Date,
                entry.Number,
                debitMain,
                creditMain,
                debit,
                credit,
                signedAmount,
                runningBalance,
                reference.ReasonType,
                reference.ReasonDocumentType,
                conversionRate,
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
            account.CurrencyGuid,
            accountCurrencyRate,
            fromDateOnly,
            toDate?.Date,
            openingBalanceInAccountCurrency,
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

        var paymentTypeRelations = await mainDbContext.EntryPaymentTypeRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);

        var noteRelations = await mainDbContext.EntryNoteRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid) && relation.NoteGuid.HasValue)
            .ToListAsync(cancellationToken);

        var noteTypeRelations = await mainDbContext.EntryNoteTypeRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);

        var collectedNoteRelations = await mainDbContext.EntryCollectedNoteRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid) && relation.NoteGuid.HasValue)
            .ToListAsync(cancellationToken);

        var collectedNoteTypeRelations = await mainDbContext.EntryCollectedNoteTypeRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid))
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

        var typeGuids = bills
            .Select(bill => bill.TypeGuid)
            .Concat(payments.Select(payment => payment.TypeGuid))
            .Concat(paymentTypeRelations.Select(relation => relation.TypeGuid))
            .Concat(noteTypeRelations.Select(relation => relation.TypeGuid))
            .Concat(collectedNoteTypeRelations.Select(relation => relation.TypeGuid))
            .Where(guid => guid.HasValue)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();

        var billTypes = typeGuids.Length == 0
            ? new List<BillTypeRecord>()
            : await mainDbContext.BillTypes
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);
        var noteTypes = typeGuids.Length == 0
            ? new List<NoteTypeRecord>()
            : await mainDbContext.NoteTypes
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);
        var entryTypes = typeGuids.Length == 0
            ? new List<EntryTypeRecord>()
            : await mainDbContext.EntryTypes
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);

        var billLookup = bills.ToDictionary(item => item.Guid);
        var paymentLookup = payments.ToDictionary(item => item.Guid);
        var billTypeLookup = billTypes.ToDictionary(item => item.Guid);
        var noteTypeLookup = noteTypes.ToDictionary(item => item.Guid);
        var entryTypeLookup = entryTypes.ToDictionary(item => item.Guid);
        var billByEntry = billRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.BillGuid!.Value).First());
        var paymentByEntry = paymentRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.PaymentGuid!.Value).First());
        var paymentTypeByEntry = paymentTypeRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.TypeGuid).FirstOrDefault());
        var noteByEntry = noteRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.NoteGuid!.Value).First());
        var noteTypeByEntry = noteTypeRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.TypeGuid).FirstOrDefault());
        var collectedNoteByEntry = collectedNoteRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.NoteGuid!.Value).First());
        var collectedNoteTypeByEntry = collectedNoteTypeRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.Select(item => item.TypeGuid).FirstOrDefault());

        string? ResolveDocumentTypeName(Guid? typeGuid)
        {
            if (!typeGuid.HasValue || typeGuid == Guid.Empty)
            {
                return null;
            }

            if (billTypeLookup.TryGetValue(typeGuid.Value, out var billType) && !string.IsNullOrWhiteSpace(billType.Name))
            {
                return billType.Name;
            }

            if (noteTypeLookup.TryGetValue(typeGuid.Value, out var noteType) && !string.IsNullOrWhiteSpace(noteType.Name))
            {
                return noteType.Name;
            }

            if (entryTypeLookup.TryGetValue(typeGuid.Value, out var entryType) && !string.IsNullOrWhiteSpace(entryType.Name))
            {
                return entryType.Name;
            }

            return null;
        }

        var result = new Dictionary<Guid, EntryReferenceInfo>();
        foreach (var entryGuid in entryGuids)
        {
            if (billByEntry.TryGetValue(entryGuid, out var billGuid))
            {
                billLookup.TryGetValue(billGuid, out var bill);
                result[entryGuid] = new EntryReferenceInfo(
                    "invoice",
                    ResolveDocumentTypeName(bill?.TypeGuid) ?? "invoice",
                    billGuid,
                    bill?.Number,
                    bill?.Date,
                    bill?.Notes);
                continue;
            }

            if (paymentByEntry.TryGetValue(entryGuid, out var paymentGuid))
            {
                paymentLookup.TryGetValue(paymentGuid, out var payment);
                var paymentTypeGuid = paymentTypeByEntry.GetValueOrDefault(entryGuid) ?? payment?.TypeGuid;
                result[entryGuid] = new EntryReferenceInfo(
                    "payment",
                    ResolveDocumentTypeName(paymentTypeGuid) ?? "payment",
                    paymentGuid,
                    payment?.Number,
                    payment?.Date,
                    payment?.Notes);
                continue;
            }

            if (noteByEntry.TryGetValue(entryGuid, out var noteGuid))
            {
                result[entryGuid] = new EntryReferenceInfo(
                    "discount",
                    ResolveDocumentTypeName(noteTypeByEntry.GetValueOrDefault(entryGuid)) ?? "discount",
                    noteGuid,
                    null,
                    null,
                    null);
                continue;
            }

            if (collectedNoteByEntry.TryGetValue(entryGuid, out var collectedNoteGuid))
            {
                result[entryGuid] = new EntryReferenceInfo(
                    "discount",
                    ResolveDocumentTypeName(collectedNoteTypeByEntry.GetValueOrDefault(entryGuid)) ?? "discount",
                    collectedNoteGuid,
                    null,
                    null,
                    null);
                continue;
            }

            result[entryGuid] = EntryReferenceInfo.Unknown;
        }

        return result;
    }

    private static CustomerAccountMovementResponse ToMovement(
        EntryRecord entry,
        EntryReferenceInfo? reference,
        double accountCurrencyRate)
    {
        var debitMain = entry.Debit ?? 0;
        var creditMain = entry.Credit ?? 0;
        var conversionRate = ResolveConversionRate(entry.CurrencyVal, accountCurrencyRate);
        var debit = ConvertMainToAccountCurrency(debitMain, conversionRate);
        var credit = ConvertMainToAccountCurrency(creditMain, conversionRate);
        var effectiveReference = reference ?? EntryReferenceInfo.Unknown;
        return new CustomerAccountMovementResponse(
            entry.Guid,
            entry.Date,
            entry.Number,
            debitMain,
            creditMain,
            debit,
            credit,
            debit - credit,
            effectiveReference.ReasonType,
            effectiveReference.ReasonDocumentType,
            conversionRate,
            effectiveReference.ReferenceGuid,
            effectiveReference.ReferenceNumber,
            effectiveReference.ReferenceDate,
            effectiveReference.ReferenceNotes,
            entry.Notes);
    }

    private static double GetAccountCurrencyRate(AccountRecord account)
    {
        return account.CurrencyVal is > 0 ? account.CurrencyVal.Value : 1d;
    }

    private static double ResolveConversionRate(double? entryCurrencyRate, double accountCurrencyRate)
    {
        if (entryCurrencyRate is > 0)
        {
            return entryCurrencyRate.Value;
        }

        if (accountCurrencyRate > 0)
        {
            return accountCurrencyRate;
        }

        return 1d;
    }

    private static double ConvertMainToAccountCurrency(double amountInMainCurrency, double conversionRate)
    {
        if (conversionRate <= 0)
        {
            return amountInMainCurrency;
        }

        return amountInMainCurrency / conversionRate;
    }

    private sealed record EntryReferenceInfo(
        string ReasonType,
        string? ReasonDocumentType,
        Guid? ReferenceGuid,
        int? ReferenceNumber,
        DateTime? ReferenceDate,
        string? ReferenceNotes)
    {
        public static readonly EntryReferenceInfo Unknown = new("unknown", null, null, null, null, null);
    }
}
