using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Customers;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Data.SqlClient;
using Microsoft.EntityFrameworkCore;
using System.Data;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/accounts")]
[RequirePermission("accounts.read")]
public sealed class CustomerAccountsController(MainDbContext mainDbContext) : ControllerBase
{
    [HttpGet("~/api/customers/{customerGuid:guid}/account/summary")]
    [RequirePermission("customers.read")]
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
        var summaryContraAccounts = await ResolveContraAccountsAsync(
            new[] { lastCreditorEntry, lastDebtorEntry }.Where(entry => entry is not null).Select(entry => entry!).ToArray(),
            cancellationToken);
        var accountCurrency = await ResolveCurrencyInfoAsync(account.CurrencyGuid, cancellationToken);
        var summaryMovementCurrencyGuids = await LoadEntryCurrencyGuidsAsync(entryGuids, cancellationToken);
        var summaryMovementCurrencyLookup = await ResolveCurrencyInfosAsync(
            summaryMovementCurrencyGuids.Values.ToArray(),
            cancellationToken);

        (Guid? Guid, CurrencyDisplayInfo? Currency) ResolveMovementCurrency(EntryRecord? entry)
        {
            if (entry is null)
            {
                return (account.CurrencyGuid, accountCurrency);
            }

            var currencyGuid = summaryMovementCurrencyGuids.TryGetValue(entry.Guid, out var resolvedGuid)
                ? resolvedGuid
                : account.CurrencyGuid;
            var currency = currencyGuid.HasValue
                ? summaryMovementCurrencyLookup.GetValueOrDefault(currencyGuid.Value) ?? accountCurrency
                : accountCurrency;
            return (currencyGuid, currency);
        }

        var creditorMovementCurrency = ResolveMovementCurrency(lastCreditorEntry);
        var debtorMovementCurrency = ResolveMovementCurrency(lastDebtorEntry);

        return Ok(new CustomerAccountSummaryResponse(
            customer?.Guid,
            customer?.CustomerName,
            account.Guid,
            account.Number,
            account.Code,
            account.Name,
            account.CurrencyGuid,
            accountCurrencyRate,
            accountCurrency?.Name,
            accountCurrency?.Code,
            accountCurrency?.Symbol,
            currentDebit,
            currentCredit,
            currentDebit - currentCredit,
            lastCreditorEntry is null
                ? null
                : ToMovement(
                    lastCreditorEntry,
                    references.GetValueOrDefault(lastCreditorEntry.Guid),
                    summaryContraAccounts.GetValueOrDefault(lastCreditorEntry.Guid),
                    accountCurrencyRate,
                    creditorMovementCurrency.Guid,
                    creditorMovementCurrency.Currency),
            lastDebtorEntry is null
                ? null
                : ToMovement(
                    lastDebtorEntry,
                    references.GetValueOrDefault(lastDebtorEntry.Guid),
                    summaryContraAccounts.GetValueOrDefault(lastDebtorEntry.Guid),
                    accountCurrencyRate,
                    debtorMovementCurrency.Guid,
                    debtorMovementCurrency.Currency)));
    }

    [HttpGet("~/api/customers/{customerGuid:guid}/account/statement")]
    [RequirePermission("customers.read")]
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
        var accountCurrency = await ResolveCurrencyInfoAsync(account.CurrencyGuid, cancellationToken);
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

        var pageEntryGuids = pageEntries.Select(entry => entry.Guid).ToArray();
        var references = await ResolveEntryReferencesAsync(pageEntryGuids, cancellationToken);
        var contraAccounts = await ResolveContraAccountsAsync(pageEntries, cancellationToken);
        var movementCurrencyGuids = await LoadEntryCurrencyGuidsAsync(pageEntryGuids, cancellationToken);
        var movementCurrencyLookup = await ResolveCurrencyInfosAsync(
            movementCurrencyGuids.Values.ToArray(),
            cancellationToken);

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

            var contraAccount = contraAccounts.GetValueOrDefault(entry.Guid);
            var reference = ResolveReferenceForDisplay(
                entry,
                references.GetValueOrDefault(entry.Guid),
                contraAccount);
            var movementCurrencyGuid = movementCurrencyGuids.TryGetValue(entry.Guid, out var resolvedMovementCurrencyGuid)
                ? resolvedMovementCurrencyGuid
                : account.CurrencyGuid;
            var movementCurrency = movementCurrencyGuid.HasValue
                ? movementCurrencyLookup.GetValueOrDefault(movementCurrencyGuid.Value) ?? accountCurrency
                : accountCurrency;
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
                contraAccount?.Guid,
                contraAccount?.Number,
                contraAccount?.Code,
                contraAccount?.Name,
                entry.Notes,
                movementCurrencyGuid,
                movementCurrency?.Name,
                movementCurrency?.Code,
                movementCurrency?.Symbol));
        }

        return Ok(new CustomerAccountStatementResponse(
            customer?.Guid,
            customer?.CustomerName,
            account.Guid,
            account.CurrencyGuid,
            accountCurrencyRate,
            accountCurrency?.Name,
            accountCurrency?.Code,
            accountCurrency?.Symbol,
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
        var entryByGuid = entries.ToDictionary(entry => entry.Guid);
        var ceGuidByEntry = entries
            .Where(entry => entry.ParentGuid.HasValue && entry.ParentGuid.Value != Guid.Empty)
            .ToDictionary(entry => entry.Guid, entry => entry.ParentGuid!.Value);
        var ceGuids = ceGuidByEntry.Values
            .Distinct()
            .ToArray();

        if (ceGuids.Length == 0)
        {
            return entryGuids.ToDictionary(
                entryGuid => entryGuid,
                entryGuid =>
                {
                    var entry = entryByGuid.GetValueOrDefault(entryGuid);
                    return new EntryReferenceInfo(
                        "unknown",
                        null,
                        entry?.ParentGuid,
                        entry?.Number,
                        entry?.Date,
                        entry?.Notes);
                });
        }

        var baseRelations = await mainDbContext.EntryRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);
        var baseRelationByCeGuid = baseRelations
            .GroupBy(relation => relation.EntryGuid)
            .ToDictionary(group => group.Key, group => group.First());

        var billRelations = await mainDbContext.EntryBillRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid) && relation.BillGuid.HasValue)
            .ToListAsync(cancellationToken);

        var paymentRelations = await mainDbContext.EntryPaymentRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid) && relation.PaymentGuid.HasValue)
            .ToListAsync(cancellationToken);

        var paymentTypeRelations = await mainDbContext.EntryPaymentTypeRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);

        var noteRelations = await mainDbContext.EntryNoteRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid) && relation.NoteGuid.HasValue)
            .ToListAsync(cancellationToken);

        var noteTypeRelations = await mainDbContext.EntryNoteTypeRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);

        var collectedNoteRelations = await mainDbContext.EntryCollectedNoteRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid) && relation.NoteGuid.HasValue)
            .ToListAsync(cancellationToken);

        var collectedNoteTypeRelations = await mainDbContext.EntryCollectedNoteTypeRelations
            .AsNoTracking()
            .Where(relation => ceGuids.Contains(relation.EntryGuid))
            .ToListAsync(cancellationToken);

        var parentGuids = baseRelations.Select(relation => relation.ParentGuid)
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
        var billTypeViews = typeGuids.Length == 0
            ? new List<BillTypeViewRecord>()
            : await mainDbContext.BillTypeViews
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);
        var noteTypes = typeGuids.Length == 0
            ? new List<NoteTypeRecord>()
            : await mainDbContext.NoteTypes
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);
        var noteTypeViews = typeGuids.Length == 0
            ? new List<NoteTypeViewRecord>()
            : await mainDbContext.NoteTypeViews
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);
        var entryTypes = typeGuids.Length == 0
            ? new List<EntryTypeRecord>()
            : await mainDbContext.EntryTypes
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);
        var entryTypeViews = typeGuids.Length == 0
            ? new List<EntryTypeViewRecord>()
            : await mainDbContext.EntryTypeViews
                .AsNoTracking()
                .Where(type => typeGuids.Contains(type.Guid))
                .ToListAsync(cancellationToken);

        var billLookup = bills.ToDictionary(item => item.Guid);
        var paymentLookup = payments.ToDictionary(item => item.Guid);
        var noteLookup = notes.ToDictionary(item => item.Guid);
        var billTypeLookup = billTypes.ToDictionary(item => item.Guid);
        var billTypeViewLookup = billTypeViews
            .GroupBy(item => item.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var noteTypeLookup = noteTypes.ToDictionary(item => item.Guid);
        var noteTypeViewLookup = noteTypeViews
            .GroupBy(item => item.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var entryTypeLookup = entryTypes.ToDictionary(item => item.Guid);
        var entryTypeViewLookup = entryTypeViews
            .GroupBy(item => item.Guid)
            .ToDictionary(group => group.Key, group => group.First());
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
        var parentTypeHintByCode = new Dictionary<int, EntryReferenceInfo>();

        static string? PickPreferredTypeLabel(string? abbrev, string? latinAbbrev, string? name, string? latinName)
        {
            if (!string.IsNullOrWhiteSpace(abbrev))
            {
                return abbrev.Trim();
            }

            if (!string.IsNullOrWhiteSpace(latinAbbrev))
            {
                return latinAbbrev.Trim();
            }

            if (!string.IsNullOrWhiteSpace(name))
            {
                return name.Trim();
            }

            if (!string.IsNullOrWhiteSpace(latinName))
            {
                return latinName.Trim();
            }

            return null;
        }

        string? ResolveDocumentTypeName(Guid? typeGuid)
        {
            if (!typeGuid.HasValue || typeGuid == Guid.Empty)
            {
                return null;
            }

            if (billTypeViewLookup.TryGetValue(typeGuid.Value, out var billTypeView))
            {
                var resolved = PickPreferredTypeLabel(
                    billTypeView.Abbrev,
                    billTypeView.LatinAbbrev,
                    billTypeView.Name,
                    billTypeView.LatinName);
                if (!string.IsNullOrWhiteSpace(resolved))
                {
                    return resolved;
                }
            }

            if (billTypeLookup.TryGetValue(typeGuid.Value, out var billType) && !string.IsNullOrWhiteSpace(billType.Name))
            {
                return billType.Name;
            }

            if (noteTypeViewLookup.TryGetValue(typeGuid.Value, out var noteTypeView))
            {
                var resolved = PickPreferredTypeLabel(
                    noteTypeView.Abbrev,
                    noteTypeView.LatinAbbrev,
                    noteTypeView.Name,
                    noteTypeView.LatinName);
                if (!string.IsNullOrWhiteSpace(resolved))
                {
                    return resolved;
                }
            }

            if (noteTypeLookup.TryGetValue(typeGuid.Value, out var noteType) && !string.IsNullOrWhiteSpace(noteType.Name))
            {
                return noteType.Name;
            }

            if (entryTypeViewLookup.TryGetValue(typeGuid.Value, out var entryTypeView))
            {
                var resolved = PickPreferredTypeLabel(
                    entryTypeView.Abbrev,
                    entryTypeView.LatinAbbrev,
                    entryTypeView.Name,
                    entryTypeView.LatinName);
                if (!string.IsNullOrWhiteSpace(resolved))
                {
                    return resolved;
                }
            }

            if (entryTypeLookup.TryGetValue(typeGuid.Value, out var entryType) && !string.IsNullOrWhiteSpace(entryType.Name))
            {
                return entryType.Name;
            }

            return null;
        }

        void RegisterParentTypeHint(EntryRelationRecord? baseRelation, EntryReferenceInfo info)
        {
            var parentType = baseRelation?.ParentType;
            if (!parentType.HasValue || parentType.Value <= 0 || parentTypeHintByCode.ContainsKey(parentType.Value))
            {
                return;
            }

            parentTypeHintByCode[parentType.Value] = info;
        }

        var result = new Dictionary<Guid, EntryReferenceInfo>();
        foreach (var entryGuid in entryGuids)
        {
            var entry = entryByGuid.GetValueOrDefault(entryGuid);
            var hasCeGuid = ceGuidByEntry.TryGetValue(entryGuid, out var ceGuid);
            var baseRelation = hasCeGuid
                ? baseRelationByCeGuid.GetValueOrDefault(ceGuid)
                : null;
            var parentGuid = baseRelation?.ParentGuid;

            if ((hasCeGuid && billByEntry.TryGetValue(ceGuid, out var billGuid)) ||
                (parentGuid.HasValue && billLookup.ContainsKey(parentGuid.Value) && (billGuid = parentGuid.Value) != Guid.Empty))
            {
                billLookup.TryGetValue(billGuid, out var bill);
                var info = new EntryReferenceInfo(
                    "invoice",
                    ResolveDocumentTypeName(bill?.TypeGuid) ?? "invoice",
                    billGuid,
                    bill?.Number ?? baseRelation?.ParentNumber ?? entry?.Number,
                    bill?.Date,
                    bill?.Notes);
                result[entryGuid] = info;
                RegisterParentTypeHint(baseRelation, info);
                continue;
            }

            if ((hasCeGuid && paymentByEntry.TryGetValue(ceGuid, out var paymentGuid)) ||
                (parentGuid.HasValue && paymentLookup.ContainsKey(parentGuid.Value) && (paymentGuid = parentGuid.Value) != Guid.Empty))
            {
                paymentLookup.TryGetValue(paymentGuid, out var payment);
                var paymentTypeGuid = hasCeGuid
                    ? paymentTypeByEntry.GetValueOrDefault(ceGuid) ?? payment?.TypeGuid
                    : payment?.TypeGuid;
                var info = new EntryReferenceInfo(
                    "payment",
                    ResolveDocumentTypeName(paymentTypeGuid) ?? "payment",
                    paymentGuid,
                    payment?.Number ?? baseRelation?.ParentNumber ?? entry?.Number,
                    payment?.Date,
                    payment?.Notes);
                result[entryGuid] = info;
                RegisterParentTypeHint(baseRelation, info);
                continue;
            }

            if ((hasCeGuid && noteByEntry.TryGetValue(ceGuid, out var noteGuid)) ||
                (hasCeGuid && collectedNoteByEntry.TryGetValue(ceGuid, out noteGuid)) ||
                (parentGuid.HasValue && noteLookup.ContainsKey(parentGuid.Value) && (noteGuid = parentGuid.Value) != Guid.Empty))
            {
                noteLookup.TryGetValue(noteGuid, out var note);
                var noteTypeName = ResolveDocumentTypeName(
                    (hasCeGuid ? noteTypeByEntry.GetValueOrDefault(ceGuid) : null)
                    ?? (hasCeGuid ? collectedNoteTypeByEntry.GetValueOrDefault(ceGuid) : null))
                    ?? ResolveNoteTypeLabel(note?.NoteType)
                    ?? "discount";
                var info = new EntryReferenceInfo(
                    "discount",
                    noteTypeName,
                    noteGuid,
                    note?.Number ?? baseRelation?.ParentNumber ?? entry?.Number,
                    note?.Date,
                    note?.Statement);
                result[entryGuid] = info;
                RegisterParentTypeHint(baseRelation, info);
                continue;
            }

            if (baseRelation?.ParentType is { } parentType
                && parentTypeHintByCode.TryGetValue(parentType, out var parentTypeHint))
            {
                result[entryGuid] = new EntryReferenceInfo(
                    parentTypeHint.ReasonType,
                    parentTypeHint.ReasonDocumentType,
                    baseRelation?.ParentGuid ?? entry?.ParentGuid ?? parentTypeHint.ReferenceGuid,
                    baseRelation?.ParentNumber ?? entry?.Number ?? parentTypeHint.ReferenceNumber,
                    entry?.Date ?? parentTypeHint.ReferenceDate,
                    entry?.Notes ?? parentTypeHint.ReferenceNotes);
                continue;
            }

            var parentTypeDocumentType = InferDocumentTypeFromParentType(baseRelation?.ParentType, entry);
            if (!string.IsNullOrWhiteSpace(parentTypeDocumentType))
            {
                result[entryGuid] = new EntryReferenceInfo(
                    MapReasonTypeFromDocumentType(parentTypeDocumentType),
                    parentTypeDocumentType,
                    baseRelation?.ParentGuid ?? entry?.ParentGuid,
                    baseRelation?.ParentNumber ?? entry?.Number,
                    entry?.Date,
                    entry?.Notes);
                continue;
            }

            var inferredDocumentType = InferDocumentTypeFromEntryNotes(entry?.Notes);
            if (!string.IsNullOrWhiteSpace(inferredDocumentType))
            {
                result[entryGuid] = new EntryReferenceInfo(
                    MapReasonTypeFromDocumentType(inferredDocumentType),
                    inferredDocumentType,
                    baseRelation?.ParentGuid ?? entry?.ParentGuid,
                    baseRelation?.ParentNumber ?? entry?.Number,
                    entry?.Date,
                    entry?.Notes);
                continue;
            }

            result[entryGuid] = new EntryReferenceInfo(
                "unknown",
                baseRelation?.ParentType is null ? null : $"parent-type-{baseRelation.ParentType}",
                baseRelation?.ParentGuid ?? entry?.ParentGuid,
                baseRelation?.ParentNumber ?? entry?.Number,
                entry?.Date,
                entry?.Notes);
        }

        return result;
    }

    private static CustomerAccountMovementResponse ToMovement(
        EntryRecord entry,
        EntryReferenceInfo? reference,
        ContraAccountInfo? contraAccount,
        double accountCurrencyRate,
        Guid? movementCurrencyGuid = null,
        CurrencyDisplayInfo? movementCurrency = null)
    {
        var debitMain = entry.Debit ?? 0;
        var creditMain = entry.Credit ?? 0;
        var conversionRate = ResolveConversionRate(entry.CurrencyVal, accountCurrencyRate);
        var debit = ConvertMainToAccountCurrency(debitMain, conversionRate);
        var credit = ConvertMainToAccountCurrency(creditMain, conversionRate);
        var effectiveReference = ResolveReferenceForDisplay(entry, reference, contraAccount);
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
            contraAccount?.Guid,
            contraAccount?.Number,
            contraAccount?.Code,
            contraAccount?.Name,
            entry.Notes,
            movementCurrencyGuid,
            movementCurrency?.Name,
            movementCurrency?.Code,
            movementCurrency?.Symbol);
    }

    private static EntryReferenceInfo ResolveReferenceForDisplay(
        EntryRecord entry,
        EntryReferenceInfo? reference,
        ContraAccountInfo? contraAccount)
    {
        var effectiveReference = reference ?? EntryReferenceInfo.Unknown;
        var hasReferenceClassification = !string.Equals(effectiveReference.ReasonType, "unknown", StringComparison.OrdinalIgnoreCase)
            && !string.IsNullOrWhiteSpace(effectiveReference.ReasonDocumentType);
        if (hasReferenceClassification)
        {
            return effectiveReference;
        }

        var inferredDocumentType = InferDocumentTypeFromContra(entry, contraAccount)
            ?? InferDocumentTypeFromEntryNotes(entry.Notes);
        if (string.IsNullOrWhiteSpace(inferredDocumentType))
        {
            return effectiveReference;
        }

        return new EntryReferenceInfo(
            MapReasonTypeFromDocumentType(inferredDocumentType),
            inferredDocumentType,
            effectiveReference.ReferenceGuid ?? entry.ParentGuid,
            effectiveReference.ReferenceNumber ?? entry.Number,
            effectiveReference.ReferenceDate ?? entry.Date,
            effectiveReference.ReferenceNotes ?? entry.Notes);
    }

    private async Task<Dictionary<Guid, ContraAccountInfo>> ResolveContraAccountsAsync(
        IReadOnlyCollection<EntryRecord> entries,
        CancellationToken cancellationToken)
    {
        if (entries.Count == 0)
        {
            return new Dictionary<Guid, ContraAccountInfo>();
        }

        var directContraGuids = entries
            .Select(entry => entry.ContraAccountGuid)
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .ToList();

        // For compound entries (ce000), a single en000 line frequently has no direct
        // ContraAccGUID; its counterpart is the sibling line(s) sharing the same parent.
        var parentGuids = entries
            .Where(entry => (!entry.ContraAccountGuid.HasValue || entry.ContraAccountGuid.Value == Guid.Empty)
                && entry.ParentGuid.HasValue && entry.ParentGuid.Value != Guid.Empty)
            .Select(entry => entry.ParentGuid!.Value)
            .Distinct()
            .ToArray();

        var siblingsByParent = new Dictionary<Guid, List<EntryRecord>>();
        if (parentGuids.Length > 0)
        {
            var siblings = await mainDbContext.Entries
                .AsNoTracking()
                .Where(entry => entry.ParentGuid.HasValue && parentGuids.Contains(entry.ParentGuid.Value))
                .ToListAsync(cancellationToken);
            siblingsByParent = siblings
                .GroupBy(entry => entry.ParentGuid!.Value)
                .ToDictionary(group => group.Key, group => group.ToList());
        }

        var accountGuids = new HashSet<Guid>(directContraGuids);
        foreach (var siblingGroup in siblingsByParent.Values)
        {
            foreach (var sibling in siblingGroup)
            {
                if (sibling.AccountGuid is { } accountGuid && accountGuid != Guid.Empty)
                {
                    accountGuids.Add(accountGuid);
                }
            }
        }

        if (accountGuids.Count == 0)
        {
            return new Dictionary<Guid, ContraAccountInfo>();
        }

        var accounts = await mainDbContext.Accounts
            .AsNoTracking()
            .Where(account => accountGuids.Contains(account.Guid))
            .ToListAsync(cancellationToken);
        var accountLookup = accounts.ToDictionary(account => account.Guid);

        var result = new Dictionary<Guid, ContraAccountInfo>();
        foreach (var entry in entries)
        {
            Guid? contraGuid = entry.ContraAccountGuid is { } directGuid && directGuid != Guid.Empty
                ? directGuid
                : null;

            if (contraGuid is null
                && entry.ParentGuid is { } parentGuid && parentGuid != Guid.Empty
                && siblingsByParent.TryGetValue(parentGuid, out var entrySiblings))
            {
                contraGuid = ResolveContraFromSiblings(entry, entrySiblings);
            }

            if (contraGuid is { } resolvedGuid && resolvedGuid != Guid.Empty
                && accountLookup.TryGetValue(resolvedGuid, out var contraAccount))
            {
                result[entry.Guid] = new ContraAccountInfo(
                    contraAccount.Guid,
                    contraAccount.Number,
                    contraAccount.Code,
                    contraAccount.Name);
            }
        }

        return result;
    }

    private static Guid? ResolveContraFromSiblings(EntryRecord entry, IReadOnlyCollection<EntryRecord> siblings)
    {
        var candidates = siblings
            .Where(sibling => sibling.Guid != entry.Guid
                && sibling.AccountGuid.HasValue && sibling.AccountGuid.Value != Guid.Empty
                && sibling.AccountGuid != entry.AccountGuid)
            .ToList();
        if (candidates.Count == 0)
        {
            return null;
        }

        var entryIsDebit = (entry.Debit ?? 0) > 0;
        var entryIsCredit = (entry.Credit ?? 0) > 0;

        // The counterpart of a debit line is a credit line (and vice versa).
        var opposite = candidates
            .Where(sibling => entryIsDebit
                ? (sibling.Credit ?? 0) > 0
                : entryIsCredit && (sibling.Debit ?? 0) > 0)
            .OrderByDescending(sibling => entryIsDebit ? (sibling.Credit ?? 0) : (sibling.Debit ?? 0))
            .FirstOrDefault();

        return (opposite ?? candidates[0]).AccountGuid;
    }

    private async Task<CurrencyDisplayInfo?> ResolveCurrencyInfoAsync(Guid? currencyGuid, CancellationToken cancellationToken)
    {
        if (!currencyGuid.HasValue || currencyGuid.Value == Guid.Empty)
        {
            return null;
        }

        var lookup = await ResolveCurrencyInfosAsync([currencyGuid.Value], cancellationToken);
        return lookup.GetValueOrDefault(currencyGuid.Value);
    }

    private async Task<Dictionary<Guid, CurrencyDisplayInfo>> ResolveCurrencyInfosAsync(
        IReadOnlyCollection<Guid> currencyGuids,
        CancellationToken cancellationToken)
    {
        var normalized = currencyGuids
            .Where(guid => guid != Guid.Empty)
            .Distinct()
            .ToArray();
        if (normalized.Length == 0)
        {
            return new Dictionary<Guid, CurrencyDisplayInfo>();
        }

        return await UseOpenSqlConnectionAsync(async connection =>
        {
            await using var command = connection.CreateCommand();
            command.CommandType = CommandType.Text;
            var parameterNames = new string[normalized.Length];
            for (var index = 0; index < normalized.Length; index++)
            {
                parameterNames[index] = $"@c{index}";
                command.Parameters.Add(new SqlParameter(parameterNames[index], SqlDbType.UniqueIdentifier) { Value = normalized[index] });
            }

            command.CommandText = $"SELECT * FROM [my000] WHERE [GUID] IN ({string.Join(", ", parameterNames)})";
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);

            var result = new Dictionary<Guid, CurrencyDisplayInfo>(normalized.Length);
            while (await reader.ReadAsync(cancellationToken))
            {
                var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                for (var index = 0; index < reader.FieldCount; index++)
                {
                    row[reader.GetName(index)] = await reader.IsDBNullAsync(index, cancellationToken)
                        ? null
                        : reader.GetValue(index);
                }

                var guid = GetGuidValue(row, "GUID");
                if (guid is not { } currencyGuid)
                {
                    continue;
                }

                var name = FirstNotBlank(
                    GetStringValue(row, "Name", "AName", "ArabicName", "LatinName", "CurrencyName", "CurName"),
                    GetStringValue(row, "ArabicName"),
                    GetStringValue(row, "AName"));
                var code = FirstNotBlank(
                    GetStringValue(row, "Code", "CurCode", "CurrencyCode", "Abbrev", "LatinCode"),
                    GetStringValue(row, "Code"),
                    GetStringValue(row, "Abbrev"));
                var symbol = FirstNotBlank(
                    GetStringValue(row, "Symbol", "CurSymbol", "CurrencySymbol", "Sign", "CurrencySign"),
                    ResolveCurrencySymbolFromCode(code),
                    ResolveCurrencySymbolFromCode(name),
                    "ل.س");
                result[currencyGuid] = new CurrencyDisplayInfo(name, code, symbol);
            }

            return result;
        }, cancellationToken);
    }

    private async Task<Dictionary<Guid, Guid>> LoadEntryCurrencyGuidsAsync(
        IReadOnlyCollection<Guid> entryGuids,
        CancellationToken cancellationToken)
    {
        var normalized = entryGuids.Distinct().ToArray();
        if (normalized.Length == 0)
        {
            return new Dictionary<Guid, Guid>();
        }

        return await UseOpenSqlConnectionAsync(async connection =>
        {
            await using var command = connection.CreateCommand();
            command.CommandType = CommandType.Text;
            var parameterNames = new string[normalized.Length];
            for (var index = 0; index < normalized.Length; index++)
            {
                parameterNames[index] = $"@e{index}";
                command.Parameters.Add(new SqlParameter(parameterNames[index], SqlDbType.UniqueIdentifier) { Value = normalized[index] });
            }

            command.CommandText = $"SELECT * FROM [en000] WHERE [GUID] IN ({string.Join(", ", parameterNames)})";
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);

            var result = new Dictionary<Guid, Guid>(normalized.Length);
            while (await reader.ReadAsync(cancellationToken))
            {
                var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                for (var index = 0; index < reader.FieldCount; index++)
                {
                    row[reader.GetName(index)] = await reader.IsDBNullAsync(index, cancellationToken)
                        ? null
                        : reader.GetValue(index);
                }

                var guid = GetGuidValue(row, "GUID");
                var currencyGuid = GetGuidValue(row, "CurrencyGUID", "CurGUID", "CurrGUID", "CurrancyGUID", "CurrencyGuid");
                if (guid is { } entryGuid && currencyGuid is { } resolvedCurrencyGuid)
                {
                    result[entryGuid] = resolvedCurrencyGuid;
                }
            }

            return result;
        }, cancellationToken);
    }

    private async Task<T> UseOpenSqlConnectionAsync<T>(
        Func<SqlConnection, Task<T>> callback,
        CancellationToken cancellationToken)
    {
        var connection = mainDbContext.Database.GetDbConnection() as SqlConnection
            ?? throw new InvalidOperationException("MainDb provider must be SQL Server.");
        var shouldCloseConnection = connection.State != ConnectionState.Open;
        if (shouldCloseConnection)
        {
            await connection.OpenAsync(cancellationToken);
        }

        try
        {
            return await callback(connection);
        }
        finally
        {
            if (shouldCloseConnection)
            {
                await connection.CloseAsync();
            }
        }
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

    private static string MapReasonTypeFromDocumentType(string? documentTypeName)
    {
        if (string.IsNullOrWhiteSpace(documentTypeName))
        {
            return "unknown";
        }

        var normalized = documentTypeName.Trim().ToLowerInvariant();
        if (normalized.Contains("مبيع")
            || normalized.Contains("بيع")
            || normalized.Contains("شراء")
            || normalized.Contains("مردود")
            || normalized.Contains("فاتورة"))
        {
            return "invoice";
        }

        if (normalized.Contains("قبض")
            || normalized.Contains("دفع")
            || normalized.Contains("سند"))
        {
            return "payment";
        }

        if (normalized.Contains("حسم")
            || normalized.Contains("اشعار")
            || normalized.Contains("إشعار")
            || normalized.Contains("debit-note")
            || normalized.Contains("credit-note"))
        {
            return "discount";
        }

        if (normalized.Contains("افتتاح"))
        {
            return "opening";
        }

        return "unknown";
    }

    private static string? InferDocumentTypeFromContra(EntryRecord entry, ContraAccountInfo? contraAccount)
    {
        if (contraAccount is null)
        {
            return null;
        }

        var accountName = contraAccount.Name?.Trim();
        if (string.IsNullOrWhiteSpace(accountName))
        {
            return null;
        }

        var debit = entry.Debit ?? 0;
        var credit = entry.Credit ?? 0;
        var normalizedName = accountName.ToLowerInvariant();

        var isCashOrBank = normalizedName.Contains("صندوق")
            || normalizedName.Contains("بنك")
            || normalizedName.Contains("cash")
            || normalizedName.Contains("bank");
        if (isCashOrBank)
        {
            if (credit > 0)
            {
                return "سند قبض";
            }

            if (debit > 0)
            {
                return "سند دفع";
            }

            return "سند";
        }

        if (normalizedName.Contains("مردود") && normalizedName.Contains("مبيعات"))
        {
            return "مردود مبيعات";
        }

        if (normalizedName.Contains("مردود") && (normalizedName.Contains("مشتريات") || normalizedName.Contains("شراء")))
        {
            return "مردود مشتريات";
        }

        if (normalizedName.Contains("مبيعات") || normalizedName.Contains("بيع"))
        {
            return debit > 0 ? "فاتورة مبيع" : "مردود مبيعات";
        }

        if (normalizedName.Contains("مشتريات") || normalizedName.Contains("شراء"))
        {
            return credit > 0 ? "فاتورة شراء" : "مردود مشتريات";
        }

        if (normalizedName.Contains("حسم"))
        {
            return "حسم";
        }

        return null;
    }

    private static string? InferDocumentTypeFromParentType(int? parentType, EntryRecord? entry)
    {
        if (!parentType.HasValue)
        {
            return null;
        }

        return parentType.Value switch
        {
            2 => "مبيع",
            4 => (entry?.Credit ?? 0) > 0 ? "قبض" : "دفع",
            5 => "حسم",
            _ => null
        };
    }

    private static string? InferDocumentTypeFromEntryNotes(string? notes)
    {
        if (string.IsNullOrWhiteSpace(notes))
        {
            return null;
        }

        var normalized = notes.Trim().ToLowerInvariant();
        if (normalized.Contains("قبض"))
        {
            return "سند قبض";
        }

        if (normalized.Contains("دفع"))
        {
            return "سند دفع";
        }

        if (normalized.Contains("مبيع") || normalized.Contains("بيع"))
        {
            return "فاتورة مبيع";
        }

        if (normalized.Contains("شراء"))
        {
            return "فاتورة شراء";
        }

        if (normalized.Contains("افتتاح"))
        {
            return "قيد افتتاحي";
        }

        return null;
    }

    private static Guid? GetGuidValue(IReadOnlyDictionary<string, object?> row, params string[] candidates)
    {
        foreach (var candidate in candidates)
        {
            if (!row.TryGetValue(candidate, out var rawValue) || rawValue is null)
            {
                continue;
            }

            switch (rawValue)
            {
                case Guid guidValue when guidValue != Guid.Empty:
                    return guidValue;
                case string stringValue when Guid.TryParse(stringValue, out var parsedGuid) && parsedGuid != Guid.Empty:
                    return parsedGuid;
            }
        }

        return null;
    }

    private static string? GetStringValue(IReadOnlyDictionary<string, object?> row, params string[] candidates)
    {
        foreach (var candidate in candidates)
        {
            if (!row.TryGetValue(candidate, out var rawValue) || rawValue is null)
            {
                continue;
            }

            var value = Convert.ToString(rawValue);
            if (!string.IsNullOrWhiteSpace(value))
            {
                return value.Trim();
            }
        }

        return null;
    }

    private static string? FirstNotBlank(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
            {
                return value.Trim();
            }
        }

        return null;
    }

    private static string? ResolveCurrencySymbolFromCode(string? currencyText)
    {
        if (string.IsNullOrWhiteSpace(currencyText))
        {
            return null;
        }

        var normalized = currencyText.Trim().ToUpperInvariant();
        if (normalized.Contains("SYP")
            || normalized.Contains("SYR")
            || normalized.Contains("SYRIAN")
            || normalized.Contains("ليرة")
            || normalized.Contains("سورية")
            || normalized.Contains("سوري")
            || normalized.Contains("ل.س")
            || normalized.Contains("ل س"))
        {
            return "ل.س";
        }

        if (normalized.Contains("USD") || normalized.Contains("DOLLAR"))
        {
            return "$";
        }

        if (normalized.Contains("EUR"))
        {
            return "€";
        }

        if (normalized.Contains("TRY"))
        {
            return "₺";
        }

        if (normalized.Contains("SAR"))
        {
            return "﷼";
        }

        if (normalized.Contains("AED"))
        {
            return "د.إ";
        }

        return null;
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

    private sealed record ContraAccountInfo(
        Guid Guid,
        int? Number,
        string? Code,
        string? Name);

    private sealed record CurrencyDisplayInfo(
        string? Name,
        string? Code,
        string? Symbol);
}
