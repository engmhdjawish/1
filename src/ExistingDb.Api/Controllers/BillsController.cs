using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Bills;
using ExistingDb.Api.Contracts.Common;
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
[Route("api/bills")]
[RequirePermission("bills.read")]
public sealed class BillsController(MainDbContext mainDbContext) : ControllerBase
{
    private static readonly string[] InvoiceTotalCandidates = ["Total", "FinalTotal", "TotalValue", "Value", "Net", "NetTotal", "Amount"];
    private static readonly string[] VoucherTotalCandidates = ["Total", "Value", "Amount", "Net", "Debit", "Credit"];
    private static readonly string[] DiscountCandidates = ["TotalDisc", "Discount", "Disc", "TotalDiscount", "DiscValue"];
    private static readonly string[] AdditionsCandidates = ["TotalAdd", "Additions", "Addition", "Extra", "TotalExtra", "Expenses"];
    private static readonly string[] NetCandidates = ["Net", "NetTotal", "FinalTotal", "TotalNet", "AmountDue"];
    private static readonly string[] ItemQuantityCandidates = ["Qty", "Quantity", "MatQty", "QTY"];
    private static readonly string[] ItemPriceCandidates = ["Price", "UnitPrice", "Value", "Amount"];
    private static readonly string[] ItemDiscountCandidates = ["Discount", "Disc", "ItemDiscount"];
    private static readonly string[] ItemAdditionCandidates = ["Addition", "Add", "Extra"];
    private static readonly string[] ItemLineTotalCandidates = ["Total", "LineTotal", "FinalTotal", "Net", "Amount", "Value"];
    private static readonly string[] CustomerGuidCandidates = ["CustGUID", "CustomerGUID", "CusGUID"];
    private static readonly string[] AccountGuidCandidates = ["AccountGUID", "AccGUID", "MainAccGUID"];
    private static readonly string[] MaterialGuidCandidates = ["MatGUID", "MaterialGUID", "MatGuid"];
    private static readonly string[] NotesCandidates = ["Notes", "Statement", "Description"];

    [HttpGet("invoices")]
    public async Task<ActionResult<PagedResponse<BillDocumentResponse>>> GetInvoices(
        [FromQuery] string? search = null,
        [FromQuery] Guid? typeGuid = null,
        [FromQuery] string? type = null,
        [FromQuery] DateTime? fromDate = null,
        [FromQuery] DateTime? toDate = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 100,
        CancellationToken cancellationToken = default)
    {
        if (!TryNormalizeDateRange(fromDate, toDate, out var fromDateOnly, out var toExclusive))
        {
            return BadRequest(new { message = "fromDate must be less than or equal to toDate." });
        }

        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 500);

        var query = mainDbContext.Bills
            .AsNoTracking()
            .AsQueryable();

        if (typeGuid.HasValue && typeGuid.Value != Guid.Empty)
        {
            query = query.Where(bill => bill.TypeGuid == typeGuid.Value);
        }

        if (!string.IsNullOrWhiteSpace(type))
        {
            var typeGuidsByText = await ResolveTypeGuidsByTextAsync(type, TypeLookupSource.Bill, cancellationToken);
            if (typeGuidsByText.Length == 0)
            {
                return Ok(new PagedResponse<BillDocumentResponse>([], page, pageSize, 0));
            }

            query = query.Where(bill => bill.TypeGuid.HasValue && typeGuidsByText.Contains(bill.TypeGuid.Value));
        }

        if (fromDateOnly.HasValue)
        {
            query = query.Where(bill => bill.Date >= fromDateOnly.Value);
        }

        if (toExclusive.HasValue)
        {
            query = query.Where(bill => bill.Date < toExclusive.Value);
        }

        query = ApplySearchFilter(query, search);

        var totalCount = await query.CountAsync(cancellationToken);
        var records = await query
            .OrderByDescending(bill => bill.Date)
            .ThenByDescending(bill => bill.Number)
            .ThenByDescending(bill => bill.Guid)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var documentGuids = records.Select(record => record.Guid).ToArray();
        var types = await ResolveTypeLookupAsync(records.Select(record => record.TypeGuid), TypeLookupSource.Bill, cancellationToken);
        var rawRows = await LoadTableRowsByGuidsAsync("bu000", documentGuids, cancellationToken);
        var links = await ResolveDocumentLinksAsync(documentGuids, DocumentKind.Invoice, rawRows, cancellationToken);
        var items = records
            .Select(record =>
            {
                var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
                    ? resolvedType
                    : null;
                rawRows.TryGetValue(record.Guid, out var rawRow);
                links.TryGetValue(record.Guid, out var link);
                return BuildDocumentResponse(record, type, rawRow, link, isVoucher: false);
            })
            .ToArray();

        return Ok(new PagedResponse<BillDocumentResponse>(items, page, pageSize, totalCount));
    }

    [HttpGet("invoices/{guid:guid}")]
    public async Task<ActionResult<BillDocumentDetailsResponse>> GetInvoice(Guid guid, CancellationToken cancellationToken = default)
    {
        var record = await mainDbContext.Bills
            .AsNoTracking()
            .SingleOrDefaultAsync(bill => bill.Guid == guid, cancellationToken);
        if (record is null)
        {
            return NotFound();
        }

        var types = await ResolveTypeLookupAsync([record.TypeGuid], TypeLookupSource.Bill, cancellationToken);
        var rawRows = await LoadTableRowsByGuidsAsync("bu000", [guid], cancellationToken);
        var links = await ResolveDocumentLinksAsync([guid], DocumentKind.Invoice, rawRows, cancellationToken);
        rawRows.TryGetValue(guid, out var rawRow);
        links.TryGetValue(guid, out var link);
        var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
            ? resolvedType
            : null;
        var document = BuildDocumentResponse(record, type, rawRow, link, isVoucher: false);
        var billItemRows = await LoadBillItemRowsAsync(guid, cancellationToken);
        var billItems = await BuildBillItemResponsesAsync(billItemRows, cancellationToken);
        return Ok(new BillDocumentDetailsResponse(document, billItems));
    }

    [HttpGet("invoice-types")]
    public async Task<ActionResult<IReadOnlyCollection<BillTypeOptionResponse>>> GetInvoiceTypes(
        CancellationToken cancellationToken = default)
    {
        var groupedTypes = await mainDbContext.Bills
            .AsNoTracking()
            .Where(bill => bill.TypeGuid.HasValue && bill.TypeGuid != Guid.Empty)
            .GroupBy(bill => bill.TypeGuid!.Value)
            .Select(group => new
            {
                TypeGuid = group.Key,
                Count = group.Count()
            })
            .ToListAsync(cancellationToken);

        var typeLookup = await ResolveTypeLookupAsync(
            groupedTypes.Select(group => (Guid?)group.TypeGuid).ToArray(),
            preferredSource: TypeLookupSource.Bill,
            cancellationToken);
        var items = groupedTypes
            .Select(group =>
            {
                typeLookup.TryGetValue(group.TypeGuid, out var type);
                return new BillTypeOptionResponse(
                    group.TypeGuid,
                    type?.Code,
                    type?.Name,
                    group.Count);
            })
            .OrderBy(item => item.TypeName)
            .ThenBy(item => item.TypeCode)
            .ToArray();

        return Ok(items);
    }

    [HttpGet("vouchers")]
    public async Task<ActionResult<PagedResponse<BillDocumentResponse>>> GetVouchers(
        [FromQuery] string? search = null,
        [FromQuery] Guid? typeGuid = null,
        [FromQuery] string? type = null,
        [FromQuery] DateTime? fromDate = null,
        [FromQuery] DateTime? toDate = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 100,
        CancellationToken cancellationToken = default)
    {
        if (!TryNormalizeDateRange(fromDate, toDate, out var fromDateOnly, out var toExclusive))
        {
            return BadRequest(new { message = "fromDate must be less than or equal to toDate." });
        }

        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 500);

        var query = mainDbContext.Payments
            .AsNoTracking()
            .AsQueryable();

        if (typeGuid.HasValue && typeGuid.Value != Guid.Empty)
        {
            query = query.Where(payment => payment.TypeGuid == typeGuid.Value);
        }

        if (!string.IsNullOrWhiteSpace(type))
        {
            var typeGuidsByText = await ResolveTypeGuidsByTextAsync(type, TypeLookupSource.Entry, cancellationToken);
            if (typeGuidsByText.Length == 0)
            {
                return Ok(new PagedResponse<BillDocumentResponse>([], page, pageSize, 0));
            }

            query = query.Where(payment => payment.TypeGuid.HasValue && typeGuidsByText.Contains(payment.TypeGuid.Value));
        }

        if (fromDateOnly.HasValue)
        {
            query = query.Where(payment => payment.Date >= fromDateOnly.Value);
        }

        if (toExclusive.HasValue)
        {
            query = query.Where(payment => payment.Date < toExclusive.Value);
        }

        query = ApplySearchFilter(query, search);

        var totalCount = await query.CountAsync(cancellationToken);
        var records = await query
            .OrderByDescending(payment => payment.Date)
            .ThenByDescending(payment => payment.Number)
            .ThenByDescending(payment => payment.Guid)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var documentGuids = records.Select(record => record.Guid).ToArray();
        var types = await ResolveTypeLookupAsync(records.Select(record => record.TypeGuid), TypeLookupSource.Entry, cancellationToken);
        var rawRows = await LoadTableRowsByGuidsAsync("py000", documentGuids, cancellationToken);
        var links = await ResolveDocumentLinksAsync(documentGuids, DocumentKind.Voucher, rawRows, cancellationToken);
        var items = records
            .Select(record =>
            {
                var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
                    ? resolvedType
                    : null;
                rawRows.TryGetValue(record.Guid, out var rawRow);
                links.TryGetValue(record.Guid, out var link);
                return BuildDocumentResponse(record, type, rawRow, link, isVoucher: true);
            })
            .ToArray();

        return Ok(new PagedResponse<BillDocumentResponse>(items, page, pageSize, totalCount));
    }

    [HttpGet("vouchers/{guid:guid}")]
    public async Task<ActionResult<BillDocumentDetailsResponse>> GetVoucher(Guid guid, CancellationToken cancellationToken = default)
    {
        var record = await mainDbContext.Payments
            .AsNoTracking()
            .SingleOrDefaultAsync(payment => payment.Guid == guid, cancellationToken);
        if (record is null)
        {
            return NotFound();
        }

        var types = await ResolveTypeLookupAsync([record.TypeGuid], TypeLookupSource.Entry, cancellationToken);
        var rawRows = await LoadTableRowsByGuidsAsync("py000", [guid], cancellationToken);
        var links = await ResolveDocumentLinksAsync([guid], DocumentKind.Voucher, rawRows, cancellationToken);
        rawRows.TryGetValue(guid, out var rawRow);
        links.TryGetValue(guid, out var link);
        var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
            ? resolvedType
            : null;
        var document = BuildDocumentResponse(record, type, rawRow, link, isVoucher: true);
        return Ok(new BillDocumentDetailsResponse(document, []));
    }

    [HttpGet("voucher-types")]
    public async Task<ActionResult<IReadOnlyCollection<BillTypeOptionResponse>>> GetVoucherTypes(
        CancellationToken cancellationToken = default)
    {
        var groupedTypes = await mainDbContext.Payments
            .AsNoTracking()
            .Where(payment => payment.TypeGuid.HasValue && payment.TypeGuid != Guid.Empty)
            .GroupBy(payment => payment.TypeGuid!.Value)
            .Select(group => new
            {
                TypeGuid = group.Key,
                Count = group.Count()
            })
            .ToListAsync(cancellationToken);

        var typeLookup = await ResolveTypeLookupAsync(
            groupedTypes.Select(group => (Guid?)group.TypeGuid).ToArray(),
            preferredSource: TypeLookupSource.Entry,
            cancellationToken);
        var items = groupedTypes
            .Select(group =>
            {
                typeLookup.TryGetValue(group.TypeGuid, out var type);
                return new BillTypeOptionResponse(
                    group.TypeGuid,
                    type?.Code,
                    type?.Name,
                    group.Count);
            })
            .OrderBy(item => item.TypeName)
            .ThenBy(item => item.TypeCode)
            .ToArray();

        return Ok(items);
    }

    private BillDocumentResponse BuildDocumentResponse(
        BillHeaderRecord record,
        ResolvedType? resolvedType,
        IReadOnlyDictionary<string, object?>? rawRow,
        DocumentLinkInfo? link,
        bool isVoucher)
    {
        var (total, discount, additions, net) = ResolveDocumentTotals(rawRow, isVoucher);
        var notes = FirstNotBlank(record.Notes, GetStringValue(rawRow, NotesCandidates));
        var (settlementTypeCode, settlementTypeName) = ResolveSettlementType(resolvedType?.Name ?? resolvedType?.Code, isVoucher);
        return new BillDocumentResponse(
            record.Guid,
            record.Number,
            record.Date,
            record.TypeGuid,
            resolvedType?.Code,
            resolvedType?.Name,
            settlementTypeCode,
            settlementTypeName,
            link?.Customer?.Guid,
            link?.Customer?.CustomerName,
            link?.Account?.Guid,
            link?.Account?.Number,
            link?.Account?.Code,
            link?.Account?.Name,
            total,
            discount,
            additions,
            net,
            notes);
    }

    private BillDocumentResponse BuildDocumentResponse(
        PaymentRecord record,
        ResolvedType? resolvedType,
        IReadOnlyDictionary<string, object?>? rawRow,
        DocumentLinkInfo? link,
        bool isVoucher)
    {
        var (total, discount, additions, net) = ResolveDocumentTotals(rawRow, isVoucher);
        var notes = FirstNotBlank(record.Notes, GetStringValue(rawRow, NotesCandidates));
        var (settlementTypeCode, settlementTypeName) = ResolveSettlementType(resolvedType?.Name ?? resolvedType?.Code, isVoucher);
        return new BillDocumentResponse(
            record.Guid,
            record.Number,
            record.Date,
            record.TypeGuid,
            resolvedType?.Code,
            resolvedType?.Name,
            settlementTypeCode,
            settlementTypeName,
            link?.Customer?.Guid,
            link?.Customer?.CustomerName,
            link?.Account?.Guid,
            link?.Account?.Number,
            link?.Account?.Code,
            link?.Account?.Name,
            total,
            discount,
            additions,
            net,
            notes);
    }

    private async Task<Dictionary<Guid, DocumentLinkInfo>> ResolveDocumentLinksAsync(
        IReadOnlyCollection<Guid> documentGuids,
        DocumentKind documentKind,
        IReadOnlyDictionary<Guid, IReadOnlyDictionary<string, object?>> rawRows,
        CancellationToken cancellationToken)
    {
        if (documentGuids.Count == 0)
        {
            return [];
        }

        var normalizedDocumentGuids = documentGuids.Distinct().ToArray();
        Dictionary<Guid, Guid[]> entryGuidsByDocument;
        if (documentKind == DocumentKind.Invoice)
        {
            var billRelations = await mainDbContext.EntryBillRelations
                .AsNoTracking()
                .Where(relation => relation.BillGuid.HasValue && normalizedDocumentGuids.Contains(relation.BillGuid.Value))
                .ToListAsync(cancellationToken);
            entryGuidsByDocument = billRelations
                .GroupBy(relation => relation.BillGuid!.Value)
                .ToDictionary(
                    group => group.Key,
                    group => group.Select(relation => relation.EntryGuid).Distinct().ToArray());
        }
        else
        {
            var paymentRelations = await mainDbContext.EntryPaymentRelations
                .AsNoTracking()
                .Where(relation => relation.PaymentGuid.HasValue && normalizedDocumentGuids.Contains(relation.PaymentGuid.Value))
                .ToListAsync(cancellationToken);
            entryGuidsByDocument = paymentRelations
                .GroupBy(relation => relation.PaymentGuid!.Value)
                .ToDictionary(
                    group => group.Key,
                    group => group.Select(relation => relation.EntryGuid).Distinct().ToArray());
        }

        var entryGuids = entryGuidsByDocument.Values
            .SelectMany(value => value)
            .Distinct()
            .ToArray();
        var entries = entryGuids.Length == 0
            ? []
            : await mainDbContext.Entries
                .AsNoTracking()
                .Where(entry => entryGuids.Contains(entry.Guid))
                .ToListAsync(cancellationToken);
        var entryLookup = entries.ToDictionary(entry => entry.Guid);

        var rowCustomerGuidByDocument = normalizedDocumentGuids.ToDictionary(
            guid => guid,
            guid => rawRows.TryGetValue(guid, out var row) ? GetGuidValue(row, CustomerGuidCandidates) : null);
        var rowAccountGuidByDocument = normalizedDocumentGuids.ToDictionary(
            guid => guid,
            guid => rawRows.TryGetValue(guid, out var row) ? GetGuidValue(row, AccountGuidCandidates) : null);

        var customerGuids = entries
            .Select(entry => entry.CustomerGuid)
            .Concat(rowCustomerGuidByDocument.Values)
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();
        var accountGuids = entries
            .Select(entry => entry.AccountGuid)
            .Concat(rowAccountGuidByDocument.Values)
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();

        var customers = customerGuids.Length == 0
            ? []
            : await mainDbContext.Customers
                .AsNoTracking()
                .Where(customer => customerGuids.Contains(customer.Guid))
                .ToListAsync(cancellationToken);
        var customerLookup = customers.ToDictionary(customer => customer.Guid);

        var accounts = accountGuids.Length == 0
            ? []
            : await mainDbContext.Accounts
                .AsNoTracking()
                .Where(account => accountGuids.Contains(account.Guid))
                .ToListAsync(cancellationToken);
        var accountLookup = accounts.ToDictionary(account => account.Guid);

        var result = new Dictionary<Guid, DocumentLinkInfo>(normalizedDocumentGuids.Length);
        foreach (var documentGuid in normalizedDocumentGuids)
        {
            var linkedEntries = entryGuidsByDocument.TryGetValue(documentGuid, out var relatedEntryGuids)
                ? relatedEntryGuids.Select(entryGuid => entryLookup.GetValueOrDefault(entryGuid)).Where(entry => entry is not null).Cast<EntryRecord>().ToArray()
                : [];
            var preferredEntry = linkedEntries.FirstOrDefault(entry => entry.CustomerGuid.HasValue || entry.AccountGuid.HasValue)
                ?? linkedEntries.FirstOrDefault();

            var customerGuid = preferredEntry?.CustomerGuid ?? rowCustomerGuidByDocument.GetValueOrDefault(documentGuid);
            var accountGuid = preferredEntry?.AccountGuid ?? rowAccountGuidByDocument.GetValueOrDefault(documentGuid);
            var customer = customerGuid.HasValue && customerLookup.TryGetValue(customerGuid.Value, out var resolvedCustomer)
                ? resolvedCustomer
                : null;
            var account = accountGuid.HasValue && accountLookup.TryGetValue(accountGuid.Value, out var resolvedAccount)
                ? resolvedAccount
                : null;
            result[documentGuid] = new DocumentLinkInfo(customer, account);
        }

        return result;
    }

    private async Task<IReadOnlyCollection<BillDocumentItemResponse>> BuildBillItemResponsesAsync(
        IReadOnlyCollection<IReadOnlyDictionary<string, object?>> billItemRows,
        CancellationToken cancellationToken)
    {
        if (billItemRows.Count == 0)
        {
            return [];
        }

        var materialGuids = billItemRows
            .Select(row => GetGuidValue(row, MaterialGuidCandidates))
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();
        var materials = materialGuids.Length == 0
            ? []
            : await mainDbContext.Materials
                .AsNoTracking()
                .Where(material => materialGuids.Contains(material.Guid))
                .ToListAsync(cancellationToken);
        var materialLookup = materials.ToDictionary(material => material.Guid);

        var items = billItemRows
            .Select(row =>
            {
                var itemGuid = GetGuidValue(row, "GUID") ?? Guid.Empty;
                var materialGuid = GetGuidValue(row, MaterialGuidCandidates);
                var quantity = GetNumberValue(row, ItemQuantityCandidates);
                var price = GetNumberValue(row, ItemPriceCandidates);
                var discount = GetNumberValue(row, ItemDiscountCandidates);
                var additions = GetNumberValue(row, ItemAdditionCandidates);
                var lineTotal = GetNumberValue(row, ItemLineTotalCandidates)
                    ?? ComputeLineTotal(quantity, price, discount, additions);
                var material = materialGuid.HasValue && materialLookup.TryGetValue(materialGuid.Value, out var resolvedMaterial)
                    ? resolvedMaterial
                    : null;

                return new BillDocumentItemResponse(
                    itemGuid,
                    materialGuid,
                    material?.Number,
                    material?.Code,
                    material?.Name,
                    quantity,
                    price,
                    discount,
                    additions,
                    lineTotal);
            })
            .ToArray();

        return items;
    }

    private async Task<IReadOnlyCollection<IReadOnlyDictionary<string, object?>>> LoadBillItemRowsAsync(
        Guid billGuid,
        CancellationToken cancellationToken)
    {
        return await UseOpenSqlConnectionAsync(async connection =>
        {
            await using var command = connection.CreateCommand();
            command.CommandText = "SELECT * FROM [bi000] WHERE [ParentGUID] = @parentGuid";
            command.CommandType = CommandType.Text;
            command.Parameters.Add(new SqlParameter("@parentGuid", SqlDbType.UniqueIdentifier) { Value = billGuid });
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);

            var rows = new List<IReadOnlyDictionary<string, object?>>();
            while (await reader.ReadAsync(cancellationToken))
            {
                var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                for (var index = 0; index < reader.FieldCount; index++)
                {
                    row[reader.GetName(index)] = await reader.IsDBNullAsync(index, cancellationToken)
                        ? null
                        : reader.GetValue(index);
                }

                rows.Add(row);
            }

            return (IReadOnlyCollection<IReadOnlyDictionary<string, object?>>)rows;
        }, cancellationToken);
    }

    private async Task<Dictionary<Guid, IReadOnlyDictionary<string, object?>>> LoadTableRowsByGuidsAsync(
        string tableName,
        IReadOnlyCollection<Guid> guids,
        CancellationToken cancellationToken)
    {
        if (guids.Count == 0)
        {
            return [];
        }

        if (!string.Equals(tableName, "bu000", StringComparison.OrdinalIgnoreCase)
            && !string.Equals(tableName, "py000", StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException($"Table '{tableName}' is not allowed for dynamic document loading.");
        }

        var normalizedGuids = guids.Distinct().ToArray();
        return await UseOpenSqlConnectionAsync(async connection =>
        {
            await using var command = connection.CreateCommand();
            command.CommandType = CommandType.Text;
            var parameterNames = new string[normalizedGuids.Length];
            for (var index = 0; index < normalizedGuids.Length; index++)
            {
                var parameterName = $"@p{index}";
                parameterNames[index] = parameterName;
                command.Parameters.Add(new SqlParameter(parameterName, SqlDbType.UniqueIdentifier) { Value = normalizedGuids[index] });
            }

            command.CommandText = $"SELECT * FROM [{tableName}] WHERE [GUID] IN ({string.Join(", ", parameterNames)})";
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);

            var rows = new Dictionary<Guid, IReadOnlyDictionary<string, object?>>(normalizedGuids.Length);
            while (await reader.ReadAsync(cancellationToken))
            {
                var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                for (var index = 0; index < reader.FieldCount; index++)
                {
                    row[reader.GetName(index)] = await reader.IsDBNullAsync(index, cancellationToken)
                        ? null
                        : reader.GetValue(index);
                }

                if (GetGuidValue(row, "GUID") is { } guid && guid != Guid.Empty)
                {
                    rows[guid] = row;
                }
            }

            return rows;
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

    private static (double? Total, double? Discount, double? Additions, double? Net) ResolveDocumentTotals(
        IReadOnlyDictionary<string, object?>? row,
        bool isVoucher)
    {
        if (row is null)
        {
            return (null, null, null, null);
        }

        var total = isVoucher
            ? GetNumberValue(row, VoucherTotalCandidates)
            : GetNumberValue(row, InvoiceTotalCandidates);
        var discount = GetNumberValue(row, DiscountCandidates);
        var additions = GetNumberValue(row, AdditionsCandidates);
        var net = GetNumberValue(row, NetCandidates);

        if (!net.HasValue && total.HasValue)
        {
            net = total.Value + (additions ?? 0d) - (discount ?? 0d);
        }

        if (!total.HasValue && net.HasValue)
        {
            total = net;
        }

        return (total, discount, additions, net);
    }

    private static (string? SettlementTypeCode, string? SettlementTypeName) ResolveSettlementType(string? typeText, bool isVoucher)
    {
        if (string.IsNullOrWhiteSpace(typeText))
        {
            return (null, null);
        }

        var normalized = typeText.Trim().ToLowerInvariant();
        if (normalized.Contains("نقد") || normalized.Contains("cash"))
        {
            return ("cash", "نقد");
        }

        if (normalized.Contains("آجل") || normalized.Contains("اجل") || normalized.Contains("credit"))
        {
            return ("credit", "آجل");
        }

        if (isVoucher && normalized.Contains("قبض"))
        {
            return ("receipt", "قبض");
        }

        if (isVoucher && normalized.Contains("دفع"))
        {
            return ("payment", "دفع");
        }

        return (null, null);
    }

    private static double? ComputeLineTotal(double? quantity, double? price, double? discount, double? additions)
    {
        if (!quantity.HasValue && !price.HasValue && !discount.HasValue && !additions.HasValue)
        {
            return null;
        }

        var subtotal = (quantity ?? 1d) * (price ?? 0d);
        return subtotal - (discount ?? 0d) + (additions ?? 0d);
    }

    private static IQueryable<BillHeaderRecord> ApplySearchFilter(IQueryable<BillHeaderRecord> query, string? search)
    {
        if (string.IsNullOrWhiteSpace(search))
        {
            return query;
        }

        var term = search.Trim();
        if (int.TryParse(term, out var number))
        {
            return query.Where(record => record.Number == number || (record.Notes != null && record.Notes.Contains(term)));
        }

        return query.Where(record => record.Notes != null && record.Notes.Contains(term));
    }

    private static IQueryable<PaymentRecord> ApplySearchFilter(IQueryable<PaymentRecord> query, string? search)
    {
        if (string.IsNullOrWhiteSpace(search))
        {
            return query;
        }

        var term = search.Trim();
        if (int.TryParse(term, out var number))
        {
            return query.Where(record => record.Number == number || (record.Notes != null && record.Notes.Contains(term)));
        }

        return query.Where(record => record.Notes != null && record.Notes.Contains(term));
    }

    private static bool TryNormalizeDateRange(
        DateTime? fromDate,
        DateTime? toDate,
        out DateTime? fromDateOnly,
        out DateTime? toExclusive)
    {
        fromDateOnly = fromDate?.Date;
        toExclusive = toDate?.Date.AddDays(1);
        if (fromDateOnly.HasValue && toExclusive.HasValue && fromDateOnly.Value >= toExclusive.Value)
        {
            return false;
        }

        return true;
    }

    private async Task<Guid[]> ResolveTypeGuidsByTextAsync(
        string type,
        TypeLookupSource preferredSource,
        CancellationToken cancellationToken)
    {
        var term = type.Trim();
        if (term.Length == 0)
        {
            return [];
        }

        var billViewGuids = await mainDbContext.BillTypeViews
            .AsNoTracking()
            .Where(typeRow =>
                (typeRow.Abbrev != null && typeRow.Abbrev.Contains(term)) ||
                (typeRow.LatinAbbrev != null && typeRow.LatinAbbrev.Contains(term)) ||
                (typeRow.Name != null && typeRow.Name.Contains(term)) ||
                (typeRow.LatinName != null && typeRow.LatinName.Contains(term)))
            .Select(typeRow => typeRow.Guid)
            .Distinct()
            .ToListAsync(cancellationToken);
        var billRecordGuids = await mainDbContext.BillTypes
            .AsNoTracking()
            .Where(typeRow =>
                (typeRow.Name != null && typeRow.Name.Contains(term)) ||
                (typeRow.LatinName != null && typeRow.LatinName.Contains(term)))
            .Select(typeRow => typeRow.Guid)
            .Distinct()
            .ToListAsync(cancellationToken);
        var entryViewGuids = await mainDbContext.EntryTypeViews
            .AsNoTracking()
            .Where(typeRow =>
                (typeRow.Abbrev != null && typeRow.Abbrev.Contains(term)) ||
                (typeRow.LatinAbbrev != null && typeRow.LatinAbbrev.Contains(term)) ||
                (typeRow.Name != null && typeRow.Name.Contains(term)) ||
                (typeRow.LatinName != null && typeRow.LatinName.Contains(term)))
            .Select(typeRow => typeRow.Guid)
            .Distinct()
            .ToListAsync(cancellationToken);
        var entryRecordGuids = await mainDbContext.EntryTypes
            .AsNoTracking()
            .Where(typeRow =>
                (typeRow.Name != null && typeRow.Name.Contains(term)) ||
                (typeRow.LatinName != null && typeRow.LatinName.Contains(term)))
            .Select(typeRow => typeRow.Guid)
            .Distinct()
            .ToListAsync(cancellationToken);
        var noteViewGuids = await mainDbContext.NoteTypeViews
            .AsNoTracking()
            .Where(typeRow =>
                (typeRow.Abbrev != null && typeRow.Abbrev.Contains(term)) ||
                (typeRow.LatinAbbrev != null && typeRow.LatinAbbrev.Contains(term)) ||
                (typeRow.Name != null && typeRow.Name.Contains(term)) ||
                (typeRow.LatinName != null && typeRow.LatinName.Contains(term)))
            .Select(typeRow => typeRow.Guid)
            .Distinct()
            .ToListAsync(cancellationToken);
        var noteRecordGuids = await mainDbContext.NoteTypes
            .AsNoTracking()
            .Where(typeRow =>
                (typeRow.Name != null && typeRow.Name.Contains(term)) ||
                (typeRow.LatinName != null && typeRow.LatinName.Contains(term)))
            .Select(typeRow => typeRow.Guid)
            .Distinct()
            .ToListAsync(cancellationToken);

        return preferredSource switch
        {
            TypeLookupSource.Bill => billViewGuids
                .Concat(billRecordGuids)
                .Concat(entryViewGuids)
                .Concat(entryRecordGuids)
                .Concat(noteViewGuids)
                .Concat(noteRecordGuids)
                .Distinct()
                .ToArray(),
            _ => entryViewGuids
                .Concat(entryRecordGuids)
                .Concat(billViewGuids)
                .Concat(billRecordGuids)
                .Concat(noteViewGuids)
                .Concat(noteRecordGuids)
                .Distinct()
                .ToArray()
        };
    }

    private async Task<Dictionary<Guid, ResolvedType>> ResolveTypeLookupAsync(
        IEnumerable<Guid?> typeGuids,
        TypeLookupSource preferredSource,
        CancellationToken cancellationToken)
    {
        var normalizedTypeGuids = typeGuids
            .Where(typeGuid => typeGuid.HasValue && typeGuid.Value != Guid.Empty)
            .Select(typeGuid => typeGuid!.Value)
            .Distinct()
            .ToArray();
        if (normalizedTypeGuids.Length == 0)
        {
            return [];
        }

        var billViews = await mainDbContext.BillTypeViews
            .AsNoTracking()
            .Where(type => normalizedTypeGuids.Contains(type.Guid))
            .ToListAsync(cancellationToken);
        var billRecords = await mainDbContext.BillTypes
            .AsNoTracking()
            .Where(type => normalizedTypeGuids.Contains(type.Guid))
            .ToListAsync(cancellationToken);
        var entryViews = await mainDbContext.EntryTypeViews
            .AsNoTracking()
            .Where(type => normalizedTypeGuids.Contains(type.Guid))
            .ToListAsync(cancellationToken);
        var entryRecords = await mainDbContext.EntryTypes
            .AsNoTracking()
            .Where(type => normalizedTypeGuids.Contains(type.Guid))
            .ToListAsync(cancellationToken);
        var noteViews = await mainDbContext.NoteTypeViews
            .AsNoTracking()
            .Where(type => normalizedTypeGuids.Contains(type.Guid))
            .ToListAsync(cancellationToken);
        var noteRecords = await mainDbContext.NoteTypes
            .AsNoTracking()
            .Where(type => normalizedTypeGuids.Contains(type.Guid))
            .ToListAsync(cancellationToken);

        var billViewLookup = billViews
            .GroupBy(type => type.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var billRecordLookup = billRecords
            .GroupBy(type => type.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var entryViewLookup = entryViews
            .GroupBy(type => type.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var entryRecordLookup = entryRecords
            .GroupBy(type => type.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var noteViewLookup = noteViews
            .GroupBy(type => type.Guid)
            .ToDictionary(group => group.Key, group => group.First());
        var noteRecordLookup = noteRecords
            .GroupBy(type => type.Guid)
            .ToDictionary(group => group.Key, group => group.First());

        var result = new Dictionary<Guid, ResolvedType>(normalizedTypeGuids.Length);
        foreach (var typeGuid in normalizedTypeGuids)
        {
            ResolvedType? BuildFromBillView()
            {
                if (!billViewLookup.TryGetValue(typeGuid, out var type))
                {
                    return null;
                }

                return BuildFromView(type.Abbrev, type.LatinAbbrev, type.Name, type.LatinName);
            }

            ResolvedType? BuildFromBillRecord()
            {
                if (!billRecordLookup.TryGetValue(typeGuid, out var type))
                {
                    return null;
                }

                return BuildFromRecord(type.Name, type.LatinName);
            }

            ResolvedType? BuildFromEntryView()
            {
                if (!entryViewLookup.TryGetValue(typeGuid, out var type))
                {
                    return null;
                }

                return BuildFromView(type.Abbrev, type.LatinAbbrev, type.Name, type.LatinName);
            }

            ResolvedType? BuildFromEntryRecord()
            {
                if (!entryRecordLookup.TryGetValue(typeGuid, out var type))
                {
                    return null;
                }

                return BuildFromRecord(type.Name, type.LatinName);
            }

            ResolvedType? BuildFromNoteView()
            {
                if (!noteViewLookup.TryGetValue(typeGuid, out var type))
                {
                    return null;
                }

                return BuildFromView(type.Abbrev, type.LatinAbbrev, type.Name, type.LatinName);
            }

            ResolvedType? BuildFromNoteRecord()
            {
                if (!noteRecordLookup.TryGetValue(typeGuid, out var type))
                {
                    return null;
                }

                return BuildFromRecord(type.Name, type.LatinName);
            }

            ResolvedType? ResolveBillFirst()
            {
                return BuildFromBillView()
                    ?? BuildFromBillRecord()
                    ?? BuildFromEntryView()
                    ?? BuildFromEntryRecord()
                    ?? BuildFromNoteView()
                    ?? BuildFromNoteRecord();
            }

            ResolvedType? ResolveEntryFirst()
            {
                return BuildFromEntryView()
                    ?? BuildFromEntryRecord()
                    ?? BuildFromBillView()
                    ?? BuildFromBillRecord()
                    ?? BuildFromNoteView()
                    ?? BuildFromNoteRecord();
            }

            ResolvedType? resolvedType = preferredSource switch
            {
                TypeLookupSource.Bill => ResolveBillFirst(),
                TypeLookupSource.Entry => ResolveEntryFirst(),
                _ => ResolveBillFirst()
            };

            if (resolvedType is not null)
            {
                result[typeGuid] = resolvedType;
            }
        }

        return result;
    }

    private static ResolvedType? BuildFromView(
        string? abbrev,
        string? latinAbbrev,
        string? name,
        string? latinName)
    {
        var code = FirstNotBlank(abbrev, latinAbbrev);
        var resolvedName = FirstNotBlank(name, latinName, code);
        if (resolvedName is null)
        {
            return null;
        }

        return new ResolvedType(code, resolvedName);
    }

    private static ResolvedType? BuildFromRecord(string? name, string? latinName)
    {
        var resolvedName = FirstNotBlank(name, latinName);
        if (resolvedName is null)
        {
            return null;
        }

        return new ResolvedType(null, resolvedName);
    }

    private static Guid? GetGuidValue(IReadOnlyDictionary<string, object?>? row, params string[] candidates)
    {
        if (row is null)
        {
            return null;
        }

        foreach (var candidate in candidates)
        {
            if (!row.TryGetValue(candidate, out var rawValue) || rawValue is null)
            {
                continue;
            }

            if (rawValue is Guid guidValue)
            {
                return guidValue;
            }

            if (rawValue is string stringValue && Guid.TryParse(stringValue, out var parsedGuid))
            {
                return parsedGuid;
            }
        }

        return null;
    }

    private static double? GetNumberValue(IReadOnlyDictionary<string, object?>? row, params string[] candidates)
    {
        if (row is null)
        {
            return null;
        }

        foreach (var candidate in candidates)
        {
            if (!row.TryGetValue(candidate, out var rawValue) || rawValue is null)
            {
                continue;
            }

            if (TryConvertToDouble(rawValue, out var convertedValue))
            {
                return convertedValue;
            }
        }

        return null;
    }

    private static string? GetStringValue(IReadOnlyDictionary<string, object?>? row, params string[] candidates)
    {
        if (row is null)
        {
            return null;
        }

        foreach (var candidate in candidates)
        {
            if (!row.TryGetValue(candidate, out var rawValue) || rawValue is null)
            {
                continue;
            }

            var convertedValue = Convert.ToString(rawValue);
            if (!string.IsNullOrWhiteSpace(convertedValue))
            {
                return convertedValue.Trim();
            }
        }

        return null;
    }

    private static bool TryConvertToDouble(object value, out double number)
    {
        switch (value)
        {
            case double doubleValue:
                number = doubleValue;
                return true;
            case float floatValue:
                number = floatValue;
                return true;
            case decimal decimalValue:
                number = Convert.ToDouble(decimalValue);
                return true;
            case int intValue:
                number = intValue;
                return true;
            case long longValue:
                number = longValue;
                return true;
            case short shortValue:
                number = shortValue;
                return true;
            case byte byteValue:
                number = byteValue;
                return true;
            case string stringValue when double.TryParse(stringValue, out var parsedValue):
                number = parsedValue;
                return true;
            default:
                number = 0;
                return false;
        }
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

    private sealed record ResolvedType(string? Code, string? Name);
    private sealed record DocumentLinkInfo(CustomerRecord? Customer, AccountRecord? Account);

    private enum TypeLookupSource
    {
        Bill = 0,
        Entry = 1
    }

    private enum DocumentKind
    {
        Invoice = 0,
        Voucher = 1
    }
}
