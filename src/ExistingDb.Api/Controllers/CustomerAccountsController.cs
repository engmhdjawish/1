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
[Route("api/accounts")]
[RequirePermission("customers.read")]
[RequirePermission("accounts.read")]
public sealed class CustomerAccountsController(MainDbContext mainDbContext) : ControllerBase
{
    [HttpGet("~/api/customers/{customerGuid:guid}/account/summary")]
    public Task<ActionResult<CustomerAccountSummaryResponse>> GetSummaryByCustomer(Guid customerGuid, CancellationToken cancellationToken)
    {
        return GetSummary(null, customerGuid, cancellationToken);
    }

    [HttpGet("summary")]
    public async Task<ActionResult<CustomerAccountSummaryResponse>> GetSummary(
        [FromQuery] Guid? accountGuid = null,
        [FromQuery] Guid? customerGuid = null,
        CancellationToken cancellationToken = default)
    {
        var (target, errorResult) = await ResolveAccountScopeAsync(accountGuid, customerGuid, cancellationToken);
        if (errorResult is not null)
        {
            return errorResult;
        }

        var customer = target!.Customer;
        var account = target.Account;
        var customerGuidFilter = target.CustomerGuidFilter;
        var summaryEntriesQuery = BuildEntriesQuery(account.Guid, customerGuidFilter);

        var accountCurrencyRate = GetAccountCurrencyRate(account);
        var defaultRate = accountCurrencyRate > 0 ? accountCurrencyRate : 1d;
        var initDebitInAccountCurrency = ConvertMainToAccountCurrency(account.InitDebit ?? 0, accountCurrencyRate);
        var initCreditInAccountCurrency = ConvertMainToAccountCurrency(account.InitCredit ?? 0, accountCurrencyRate);
        var debitFromEntriesInAccountCurrency = await summaryEntriesQuery
            .Select(entry => (double?)((entry.Debit ?? 0) / (entry.CurrencyVal.HasValue && entry.CurrencyVal.Value > 0
                ? entry.CurrencyVal.Value
                : defaultRate)))
            .SumAsync(cancellationToken) ?? 0;
        var creditFromEntriesInAccountCurrency = await summaryEntriesQuery
            .Select(entry => (double?)((entry.Credit ?? 0) / (entry.CurrencyVal.HasValue && entry.CurrencyVal.Value > 0
                ? entry.CurrencyVal.Value
                : defaultRate)))
            .SumAsync(cancellationToken) ?? 0;
        var currentDebit = initDebitInAccountCurrency + debitFromEntriesInAccountCurrency;
        var currentCredit = initCreditInAccountCurrency + creditFromEntriesInAccountCurrency;

        var lastCreditorEntry = await summaryEntriesQuery
            .Where(entry => (entry.Credit ?? 0) > 0)
            .OrderByDescending(entry => entry.Date)
            .ThenByDescending(entry => entry.Number)
            .ThenByDescending(entry => entry.Guid)
            .FirstOrDefaultAsync(cancellationToken);

        var lastDebtorEntry = await summaryEntriesQuery
            .Where(entry => (entry.Debit ?? 0) > 0)
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
            customer?.Guid,
            customer?.CustomerName,
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

    [HttpGet("~/api/customers/{customerGuid:guid}/account/statement")]
    [RequirePermission("entries.read")]
    public Task<ActionResult<CustomerAccountStatementResponse>> GetStatementByCustomer(
        Guid customerGuid,
        [FromQuery] DateTime? fromDate = null,
        [FromQuery] DateTime? toDate = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 100,
        CancellationToken cancellationToken = default)
    {
        return GetStatement(null, customerGuid, fromDate, toDate, page, pageSize, cancellationToken);
    }

    [HttpGet("statement")]
    [RequirePermission("entries.read")]
    public async Task<ActionResult<CustomerAccountStatementResponse>> GetStatement(
        [FromQuery] Guid? accountGuid = null,
        [FromQuery] Guid? customerGuid = null,
        [FromQuery] DateTime? fromDate = null,
        [FromQuery] DateTime? toDate = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 100,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 500);

        var (target, errorResult) = await ResolveAccountScopeAsync(accountGuid, customerGuid, cancellationToken);
        if (errorResult is not null)
        {
            return errorResult;
        }

        var customer = target!.Customer;
        var account = target.Account;
        var customerGuidFilter = target.CustomerGuidFilter;

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
        var entriesQuery = BuildEntriesQuery(account.Guid, customerGuidFilter);

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
            ? await BuildEntriesQuery(account.Guid, customerGuidFilter)
                .Where(entry => entry.Date < fromDateOnly.Value)
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
            customer?.Guid,
            customer?.CustomerName,
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

    private IQueryable<EntryRecord> BuildEntriesQuery(Guid accountGuid, Guid? customerGuid)
    {
        var query = mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.AccountGuid == accountGuid);

        if (customerGuid.HasValue)
        {
            query = query.Where(entry => entry.CustomerGuid == customerGuid.Value);
        }

        return query;
    }

    private async Task<(AccountQueryTarget? Target, ActionResult? ErrorResult)> ResolveAccountScopeAsync(
        Guid? accountGuid,
        Guid? customerGuid,
        CancellationToken cancellationToken)
    {
        if (!accountGuid.HasValue && !customerGuid.HasValue)
        {
            return (null, BadRequest(new
            {
                message = "Either accountGuid or customerGuid must be provided.",
                accountGuid,
                customerGuid
            }));
        }

        CustomerRecord? customer = null;
        if (customerGuid.HasValue)
        {
            customer = await mainDbContext.Customers
                .AsNoTracking()
                .SingleOrDefaultAsync(record => record.Guid == customerGuid.Value, cancellationToken);
            if (customer is null)
            {
                return (null, NotFound(new { message = "Customer was not found.", customerGuid }));
            }
        }

        var resolvedAccountGuid = accountGuid
            ?? (customer?.AccountGuid is { } linkedAccountGuid && linkedAccountGuid != Guid.Empty
                ? linkedAccountGuid
                : null);
        if (!resolvedAccountGuid.HasValue)
        {
            return (null, BadRequest(new
            {
                message = "No account could be resolved. Provide accountGuid explicitly when the customer has no linked account.",
                accountGuid,
                customerGuid
            }));
        }

        var account = await mainDbContext.Accounts
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == resolvedAccountGuid.Value, cancellationToken);
        if (account is null)
        {
            return (null, NotFound(new { message = "Account was not found.", accountGuid = resolvedAccountGuid }));
        }

        return (new AccountQueryTarget(customer, account, customerGuid), null);
    }

    private async Task<Dictionary<Guid, EntryReferenceInfo>> ResolveEntryReferencesAsync(
        IReadOnlyCollection<Guid> entryGuids,
        CancellationToken cancellationToken)
    {
        if (entryGuids.Count == 0)
        {
            return new Dictionary<Guid, EntryReferenceInfo>();
        }

        var entries = await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entryGuids.Contains(entry.Guid))
            .ToListAsync(cancellationToken);

        var entryParentByGuid = entries.ToDictionary(entry => entry.Guid, entry => entry.ParentGuid);
        var baseRelations = await mainDbContext.EntryRelations
            .AsNoTracking()
            .Where(relation => entryGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);
        var baseRelationByEntry = baseRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.First());

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

        var parentGuids = entryParentByGuid.Values
            .Concat(baseRelations.Select(relation => relation.ParentGuid))
            .Where(guid => guid.HasValue && guid != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();

        var billGuids = billRelations
            .Select(relation => relation.BillGuid!.Value)
            .Concat(parentGuids)
            .Distinct()
            .ToArray();
        var paymentGuids = paymentRelations
            .Select(relation => relation.PaymentGuid!.Value)
            .Concat(parentGuids)
            .Distinct()
            .ToArray();
        var noteGuids = noteRelations
            .Select(relation => relation.NoteGuid!.Value)
            .Concat(collectedNoteRelations.Select(relation => relation.NoteGuid!.Value))
            .Concat(parentGuids)
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
        var notes = noteGuids.Length == 0
            ? new List<CreditDebitNoteRecord>()
            : await mainDbContext.CreditDebitNotes
                .AsNoTracking()
                .Where(note => noteGuids.Contains(note.Guid))
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
        var noteLookup = notes.ToDictionary(item => item.Guid);
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

        Guid? ResolveParentGuid(Guid entryGuid)
        {
            return entryParentByGuid.GetValueOrDefault(entryGuid)
                ?? baseRelationByEntry.GetValueOrDefault(entryGuid)?.ParentGuid;
        }

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
            var parentGuid = ResolveParentGuid(entryGuid);

            if (billByEntry.TryGetValue(entryGuid, out var billGuid) ||
                (parentGuid.HasValue && billLookup.ContainsKey(parentGuid.Value) && (billGuid = parentGuid.Value) != Guid.Empty))
            {
                billLookup.TryGetValue(billGuid, out var bill);
                var baseRelation = baseRelationByEntry.GetValueOrDefault(entryGuid);
                result[entryGuid] = new EntryReferenceInfo(
                    "invoice",
                    ResolveDocumentTypeName(bill?.TypeGuid) ?? "invoice",
                    billGuid,
                    bill?.Number ?? baseRelation?.ParentNumber,
                    bill?.Date,
                    bill?.Notes);
                continue;
            }

            if (paymentByEntry.TryGetValue(entryGuid, out var paymentGuid) ||
                (parentGuid.HasValue && paymentLookup.ContainsKey(parentGuid.Value) && (paymentGuid = parentGuid.Value) != Guid.Empty))
            {
                paymentLookup.TryGetValue(paymentGuid, out var payment);
                var paymentTypeGuid = paymentTypeByEntry.GetValueOrDefault(entryGuid) ?? payment?.TypeGuid;
                var baseRelation = baseRelationByEntry.GetValueOrDefault(entryGuid);
                result[entryGuid] = new EntryReferenceInfo(
                    "payment",
                    ResolveDocumentTypeName(paymentTypeGuid) ?? "payment",
                    paymentGuid,
                    payment?.Number ?? baseRelation?.ParentNumber,
                    payment?.Date,
                    payment?.Notes);
                continue;
            }

            if (noteByEntry.TryGetValue(entryGuid, out var noteGuid) ||
                collectedNoteByEntry.TryGetValue(entryGuid, out noteGuid) ||
                (parentGuid.HasValue && noteLookup.ContainsKey(parentGuid.Value) && (noteGuid = parentGuid.Value) != Guid.Empty))
            {
                noteLookup.TryGetValue(noteGuid, out var note);
                var noteTypeName = ResolveDocumentTypeName(
                    noteTypeByEntry.GetValueOrDefault(entryGuid)
                    ?? collectedNoteTypeByEntry.GetValueOrDefault(entryGuid))
                    ?? ResolveNoteTypeLabel(note?.NoteType)
                    ?? "discount";
                var baseRelation = baseRelationByEntry.GetValueOrDefault(entryGuid);
                result[entryGuid] = new EntryReferenceInfo(
                    "discount",
                    noteTypeName,
                    noteGuid,
                    note?.Number ?? baseRelation?.ParentNumber,
                    note?.Date,
                    note?.Statement);
                continue;
            }

            var unknownRelation = baseRelationByEntry.GetValueOrDefault(entryGuid);
            result[entryGuid] = new EntryReferenceInfo(
                "unknown",
                unknownRelation?.ParentType is null ? null : $"parent-type-{unknownRelation.ParentType}",
                unknownRelation?.ParentGuid,
                unknownRelation?.ParentNumber,
                null,
                null);
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

    private static string? ResolveNoteTypeLabel(int? noteType)
    {
        return noteType switch
        {
            1 => "debit-note",
            2 => "credit-note",
            _ => null
        };
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

    private sealed record AccountQueryTarget(
        CustomerRecord? Customer,
        AccountRecord Account,
        Guid? CustomerGuidFilter);
}
