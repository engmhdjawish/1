using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Customers;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.Data.SqlClient;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using System.Data;

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
        var summaryContraAccounts = await ResolveContraAccountsAsync(
            new[] { lastCreditorEntry, lastDebtorEntry }.Where(entry => entry is not null).Select(entry => entry!).ToArray(),
            cancellationToken);

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
                : ToMovement(
                    lastCreditorEntry,
                    references.GetValueOrDefault(lastCreditorEntry.Guid),
                    summaryContraAccounts.GetValueOrDefault(lastCreditorEntry.Guid),
                    accountCurrencyRate),
            lastDebtorEntry is null
                ? null
                : ToMovement(
                    lastDebtorEntry,
                    references.GetValueOrDefault(lastDebtorEntry.Guid),
                    summaryContraAccounts.GetValueOrDefault(lastDebtorEntry.Guid),
                    accountCurrencyRate)));
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
        var contraAccounts = await ResolveContraAccountsAsync(pageEntries, cancellationToken);

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

    [HttpGet("general-ledger")]
    [RequirePermission("entries.read")]
    public async Task<ActionResult<GeneralLedgerResponse>> GetGeneralLedger(
        [FromQuery] Guid? accountGuid = null,
        [FromQuery] Guid? customerGuid = null,
        [FromQuery] Guid? sourceGuid = null,
        [FromQuery] DateTime? fromDate = null,
        [FromQuery] DateTime? toDate = null,
        [FromQuery] Guid? currencyGuid = null,
        [FromQuery] bool isCalledByWeb = false,
        [FromQuery] bool detailByAccountCurrency = false,
        [FromQuery] bool showRunningBalance = true,
        CancellationToken cancellationToken = default)
    {
        var (target, errorResult) = await ResolveAccountScopeAsync(accountGuid, customerGuid, cancellationToken);
        if (errorResult is not null)
        {
            return errorResult;
        }

        var account = target!.Account;
        var customer = target.Customer;
        var effectiveFromDate = (fromDate ?? DateTime.UtcNow.Date.AddMonths(-1)).Date;
        var effectiveToDate = (toDate ?? DateTime.UtcNow.Date).Date;
        if (effectiveFromDate > effectiveToDate)
        {
            return BadRequest(new { message = "fromDate must be less than or equal to toDate." });
        }

        if (!sourceGuid.HasValue || sourceGuid.Value == Guid.Empty)
        {
            return BadRequest(new
            {
                message = "sourceGuid is required for prcGeneralLedger (maps report sources in RepSrcs).",
                sourceGuid
            });
        }

        var effectiveCurrencyGuid = currencyGuid
            ?? account.CurrencyGuid
            ?? Guid.Empty;

        var (rows, resultSetCount) = await ExecuteGeneralLedgerProcedureAsync(
            account.Guid,
            target.CustomerGuidFilter ?? Guid.Empty,
            sourceGuid.Value,
            effectiveFromDate,
            effectiveToDate,
            effectiveCurrencyGuid,
            isCalledByWeb,
            detailByAccountCurrency,
            showRunningBalance,
            cancellationToken);

        return Ok(new GeneralLedgerResponse(
            customer?.Guid,
            customer?.CustomerName,
            account.Guid,
            sourceGuid.Value,
            isCalledByWeb,
            effectiveCurrencyGuid,
            effectiveFromDate,
            effectiveToDate,
            resultSetCount,
            rows.Count,
            rows));
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

    private async Task<(List<IReadOnlyDictionary<string, object?>> Rows, int ResultSetCount)> ExecuteGeneralLedgerProcedureAsync(
        Guid accountGuid,
        Guid customerGuid,
        Guid sourceGuid,
        DateTime fromDate,
        DateTime toDate,
        Guid currencyGuid,
        bool isCalledByWeb,
        bool detailByAccountCurrency,
        bool showRunningBalance,
        CancellationToken cancellationToken)
    {
        var connection = mainDbContext.Database.GetDbConnection();
        var sqlConnection = connection as SqlConnection
            ?? throw new InvalidOperationException("MainDb provider must be SQL Server.");

        var shouldCloseConnection = sqlConnection.State != ConnectionState.Open;
        if (shouldCloseConnection)
        {
            await sqlConnection.OpenAsync(cancellationToken);
        }

        try
        {
            await using var sqlCommand = sqlConnection.CreateCommand();
            sqlCommand.CommandText = "prcGeneralLedger";
            sqlCommand.CommandType = CommandType.StoredProcedure;
            SqlCommandBuilder.DeriveParameters(sqlCommand);

            var toDateInclusive = toDate.Date.AddDays(1).AddMilliseconds(-3);
            var parameterValues = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["@IsCalledByWeb"] = isCalledByWeb ? 1 : 0,
                ["@Account"] = accountGuid,
                ["@CustGUID"] = customerGuid,
                ["@CostGuid"] = Guid.Empty,
                ["@MatGUID"] = Guid.Empty,
                ["@GroupGUID"] = Guid.Empty,
                ["@FromCheckDate"] = 0,
                ["@StartDate"] = fromDate.Date,
                ["@EndDate"] = toDateInclusive,
                ["@CurGUID"] = currencyGuid,
                ["@Class"] = string.Empty,
                ["@ShowUnPosted"] = 0,
                ["@Level"] = 0,
                ["@Contain"] = string.Empty,
                ["@NotContain"] = string.Empty,
                ["@PrevBalance"] = 1,
                ["@ObverseAcc"] = Guid.Empty,
                ["@UnifyAccEn"] = 0,
                ["@ShowIsCheck"] = 0,
                ["@rid"] = 0,
                ["@ItemChecked"] = 3,
                ["@ShowEmptyBal"] = 0,
                ["@CheckForUsers"] = 0,
                ["@CollectCheck"] = 0,
                ["@User"] = Guid.Empty,
                ["@ShwUser"] = 0,
                ["@SrcGuid"] = sourceGuid,
                ["@EntryCond"] = Guid.Empty,
                ["@BillCond"] = Guid.Empty,
                ["@FromPostDate"] = fromDate.Date,
                ["@ToPostDate"] = toDateInclusive,
                ["@IsFilterByDate"] = 1,
                ["@IsFilterByPostDate"] = 0,
                ["@DetialByAccountCurrency"] = detailByAccountCurrency ? 1 : 0,
                ["@ShowRelatedMatInfo"] = 0,
                ["@IsGroupedByCost"] = 0,
                ["@IsGroupedByClass"] = 0,
                ["@ShowRunningBalance"] = showRunningBalance ? 1 : 0,
                ["@ShowObverseAcc"] = 1,
                ["@ShowMainAcc"] = 1,
                ["@NoAccessStr"] = "لا توجد صلاحية",
                ["@isOpeningEntryAsPrevBalance"] = 0
            };

            foreach (SqlParameter parameter in sqlCommand.Parameters)
            {
                if (parameter.Direction == ParameterDirection.ReturnValue)
                {
                    continue;
                }

                if (parameterValues.TryGetValue(parameter.ParameterName, out var assignedValue))
                {
                    parameter.Value = assignedValue ?? DBNull.Value;
                    continue;
                }

                parameter.Value = GetFallbackParameterValue(parameter);
            }

            await using var reader = await sqlCommand.ExecuteReaderAsync(cancellationToken);
            var rows = new List<IReadOnlyDictionary<string, object?>>();
            var resultSetCount = 0;

            do
            {
                resultSetCount++;
                while (await reader.ReadAsync(cancellationToken))
                {
                    var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                    for (var index = 0; index < reader.FieldCount; index++)
                    {
                        var fieldName = reader.GetName(index);
                        var value = await reader.IsDBNullAsync(index, cancellationToken)
                            ? null
                            : NormalizeSqlValue(reader.GetValue(index));
                        row[fieldName] = value;
                    }

                    rows.Add(row);
                }
            } while (await reader.NextResultAsync(cancellationToken));

            return (rows, resultSetCount);
        }
        finally
        {
            if (shouldCloseConnection)
            {
                await sqlConnection.CloseAsync();
            }
        }
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
        var parentTypeHintByCode = new Dictionary<int, EntryReferenceInfo>();

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
        double accountCurrencyRate)
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
            entry.Notes);
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

        var contraGuids = entries
            .Select(entry => entry.ContraAccountGuid)
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();

        if (contraGuids.Length == 0)
        {
            return new Dictionary<Guid, ContraAccountInfo>();
        }

        var accounts = await mainDbContext.Accounts
            .AsNoTracking()
            .Where(account => contraGuids.Contains(account.Guid))
            .ToListAsync(cancellationToken);
        var accountLookup = accounts.ToDictionary(account => account.Guid);

        var result = new Dictionary<Guid, ContraAccountInfo>();
        foreach (var entry in entries)
        {
            if (entry.ContraAccountGuid is not { } contraGuid || contraGuid == Guid.Empty)
            {
                continue;
            }

            if (!accountLookup.TryGetValue(contraGuid, out var contraAccount))
            {
                continue;
            }

            result[entry.Guid] = new ContraAccountInfo(
                contraAccount.Guid,
                contraAccount.Number,
                contraAccount.Code,
                contraAccount.Name);
        }

        return result;
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

    private static object GetFallbackParameterValue(SqlParameter parameter)
    {
        return parameter.SqlDbType switch
        {
            SqlDbType.UniqueIdentifier => Guid.Empty,
            SqlDbType.Bit => 0,
            SqlDbType.TinyInt or SqlDbType.SmallInt or SqlDbType.Int or SqlDbType.BigInt => 0,
            SqlDbType.Decimal or SqlDbType.Float or SqlDbType.Real or SqlDbType.Money or SqlDbType.SmallMoney => 0m,
            SqlDbType.Date or SqlDbType.DateTime or SqlDbType.DateTime2 or SqlDbType.SmallDateTime => new DateTime(1900, 1, 1),
            SqlDbType.NChar or SqlDbType.NText or SqlDbType.NVarChar or SqlDbType.Char or SqlDbType.Text or SqlDbType.VarChar => string.Empty,
            _ => DBNull.Value
        };
    }

    private static object? NormalizeSqlValue(object? value)
    {
        return value switch
        {
            null => null,
            DBNull => null,
            byte[] bytes => Convert.ToBase64String(bytes),
            _ => value
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

    private sealed record ContraAccountInfo(
        Guid Guid,
        int? Number,
        string? Code,
        string? Name);
}
