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
using System.Globalization;

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
    private static readonly string[] ItemQuantityUnit1Candidates = ["Qty1", "Quantity1", "QTY1", "Unit1Qty", "Qty", "Quantity", "MatQty", "QTY"];
    private static readonly string[] ItemQuantityUnit2Candidates = ["Qty2", "Quantity2", "QTY2", "Unit2Qty", "QtySecond", "Qty_2"];
    private static readonly string[] ItemUnitPriceUnit1Candidates = ["Price", "Price1", "UnitPrice", "UnitPrice1", "PiecePrice", "Value1"];
    private static readonly string[] ItemPriceCandidates = ["Price", "UnitPrice", "Value", "Amount"];
    private static readonly string[] ItemDiscountCandidates = ["Discount", "Disc", "ItemDiscount"];
    private static readonly string[] ItemAdditionCandidates = ["Addition", "Add", "Extra"];
    private static readonly string[] ItemLineTotalCandidates = ["Total", "LineTotal", "FinalTotal", "Net", "Amount", "Value"];
    private static readonly string[] CustomerGuidCandidates = ["CustGUID", "CustomerGUID", "CusGUID", "CustomerGuid"];
    private static readonly string[] AccountGuidCandidates = ["AccountGUID", "AccGUID", "MainAccGUID", "CustAccGUID", "CustomerAccGUID"];
    private static readonly string[] AccountNumberCandidates = ["AccountNum", "AccNum", "AccountNumber", "AccNumber", "MainAccNum"];
    private static readonly string[] AccountCodeCandidates = ["AccountCode", "AccCode", "MainAccCode"];
    private static readonly string[] AccountNameCandidates = ["AccountName", "AccName", "MainAccName"];
    private static readonly string[] PayTypeCandidates = ["PayType", "paytype", "Paytype", "PaymentType"];
    private static readonly string[] InvoiceCustomerNameCandidates =
    [
        "CustomerName",
        "CustName",
        "CusName",
        "BillCustomer",
        "PartyName",
        "ClientName",
        "AName"
    ];
    private static readonly string[] MaterialGuidCandidates = ["MatGUID", "MaterialGUID", "MatGuid"];
    private static readonly string[] CurrencyGuidCandidates = ["CurrencyGUID", "CurGUID", "CurrGUID", "CurrencyGuid", "CurrancyGUID", "CurrancyGuid"];
    private static readonly string[] CurrencyRateCandidates = ["CurrencyVal", "CurVal", "CurrancyVal", "CurrencyValue", "CurRate", "Rate", "ExchangeRate", "CurrancyValue"];
    private static readonly string[] DocumentCurrencyNameCandidates = ["CurrencyName", "CurName"];
    private static readonly string[] DocumentCurrencyCodeCandidates = ["CurrencyCode", "CurCode", "CodeCur", "CurrCode", "CurrancyCode"];
    private static readonly string[] DocumentCurrencySymbolCandidates = ["CurrencySymbol", "CurSymbol", "CurrencySign", "CurrSign"];
    private static readonly string[] CurrencyLookupNameCandidates = ["Name", "AName", "ArabicName", "LatinName", "CurrencyName", "CurName"];
    private static readonly string[] CurrencyLookupCodeCandidates = ["Code", "CurCode", "CurrencyCode", "Abbrev", "LatinCode"];
    private static readonly string[] CurrencyLookupSymbolCandidates = ["Symbol", "CurSymbol", "CurrencySymbol", "Sign", "CurrencySign"];
    private static readonly string[] PairsCountCandidates = ["Pairs", "PairsCount", "TotalPairs", "PairQty", "QtyPair", "Qty2"];
    private static readonly string[] PensCountCandidates = ["Pens", "PensCount", "TotalPens", "Pieces", "PiecesCount", "QTy1", "Qty1"];
    private static readonly string[] DiscountAccountGuidCandidates = ["DiscAccGUID", "DiscountAccGUID", "DiscountAccountGUID", "TotalDiscAccGUID"];
    private static readonly string[] DiscountAccountNumberCandidates = ["DiscAccNum", "DiscountAccNum", "DiscountAccountNumber", "TotalDiscAccNum"];
    private static readonly string[] DiscountAccountCodeCandidates = ["DiscAccCode", "DiscountAccCode", "DiscountAccountCode"];
    private static readonly string[] DiscountAccountNameCandidates = ["DiscAccName", "DiscountAccName", "DiscountAccountName"];
    private static readonly string[] AdditionAccountGuidCandidates = ["AddAccGUID", "AdditionAccGUID", "AdditionAccountGUID", "TotalAddAccGUID"];
    private static readonly string[] AdditionAccountNumberCandidates = ["AddAccNum", "AdditionAccNum", "AdditionAccountNumber", "TotalAddAccNum"];
    private static readonly string[] AdditionAccountCodeCandidates = ["AddAccCode", "AdditionAccCode", "AdditionAccountCode"];
    private static readonly string[] AdditionAccountNameCandidates = ["AddAccName", "AdditionAccName", "AdditionAccountName"];
    private static readonly string[] DocumentDateCandidates = ["Date", "DocDate", "BillDate", "VoucherDate", "PyDate", "TransDate"];
    private static readonly string[] NotesCandidates = ["Notes", "Statement", "Description"];

    [HttpGet("invoices")]
    public async Task<ActionResult<PagedResponse<BillDocumentResponse>>> GetInvoices(
        [FromQuery] string? keyword = null,
        [FromQuery(Name = "search")] string? legacySearch = null,
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

        var keywordTerms = ParseKeywordTerms(!string.IsNullOrWhiteSpace(keyword) ? keyword : legacySearch);
        foreach (var term in keywordTerms)
        {
            var relatedGuids = await ResolveSearchRelationGuidsAsync(term, DocumentKind.Invoice, cancellationToken);
            query = ApplySearchFilter(query, term, relatedGuids);
        }

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
        var currencyLookup = await ResolveCurrencyReferencesAsync(rawRows.Values, cancellationToken);
        var links = await ResolveDocumentLinksAsync(documentGuids, DocumentKind.Invoice, rawRows, cancellationToken);
        var items = records
            .Select(record =>
            {
                var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
                    ? resolvedType
                    : null;
                rawRows.TryGetValue(record.Guid, out var rawRow);
                links.TryGetValue(record.Guid, out var link);
                return BuildDocumentResponse(record, type, rawRow, link, currencyLookup, isVoucher: false);
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
        var currencyLookup = await ResolveCurrencyReferencesAsync(rawRows.Values, cancellationToken);
        var links = await ResolveDocumentLinksAsync([guid], DocumentKind.Invoice, rawRows, cancellationToken);
        rawRows.TryGetValue(guid, out var rawRow);
        links.TryGetValue(guid, out var link);
        var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
            ? resolvedType
            : null;
        var document = BuildDocumentResponse(record, type, rawRow, link, currencyLookup, isVoucher: false);
        var billItemRows = await LoadBillItemRowsAsync(guid, cancellationToken);
        var billItems = await BuildBillItemResponsesAsync(billItemRows, document.CurrencyRate, cancellationToken);
        var totalQuantity = billItems.Sum(item => item.Quantity ?? 0d);
        var totalPairs = document.PairsCount ?? totalQuantity;
        var totalPens = document.PensCount;
        return Ok(new BillDocumentDetailsResponse(
            document,
            billItems,
            billItems.Count,
            billItems.Count == 0 ? null : totalQuantity,
            totalPairs,
            totalPens,
            null));
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
        [FromQuery] string? keyword = null,
        [FromQuery(Name = "search")] string? legacySearch = null,
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

        var keywordTerms = ParseKeywordTerms(!string.IsNullOrWhiteSpace(keyword) ? keyword : legacySearch);
        foreach (var term in keywordTerms)
        {
            var relatedGuids = await ResolveSearchRelationGuidsAsync(term, DocumentKind.Voucher, cancellationToken);
            query = ApplySearchFilter(query, term, relatedGuids);
        }

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
        var currencyLookup = await ResolveCurrencyReferencesAsync(rawRows.Values, cancellationToken);
        var links = await ResolveDocumentLinksAsync(documentGuids, DocumentKind.Voucher, rawRows, cancellationToken);
        var items = records
            .Select(record =>
            {
                var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
                    ? resolvedType
                    : null;
                rawRows.TryGetValue(record.Guid, out var rawRow);
                links.TryGetValue(record.Guid, out var link);
                return BuildDocumentResponse(record, type, rawRow, link, currencyLookup, isVoucher: true);
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
        var currencyLookup = await ResolveCurrencyReferencesAsync(rawRows.Values, cancellationToken);
        var links = await ResolveDocumentLinksAsync([guid], DocumentKind.Voucher, rawRows, cancellationToken);
        rawRows.TryGetValue(guid, out var rawRow);
        links.TryGetValue(guid, out var link);
        var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
            ? resolvedType
            : null;
        var document = BuildDocumentResponse(record, type, rawRow, link, currencyLookup, isVoucher: true);
        var entryLineRecords = await LoadVoucherEntryLinesAsync(guid, cancellationToken);
        var entryLines = await BuildVoucherEntryLineResponsesAsync(entryLineRecords, document.CurrencyRate, cancellationToken);
        var totalDebit = entryLines.Sum(line => line.Debit ?? 0d);
        var totalCredit = entryLines.Sum(line => line.Credit ?? 0d);
        var netFromLines = totalDebit > 0 || totalCredit > 0 ? totalDebit - totalCredit : (double?)null;
        return Ok(new BillDocumentDetailsResponse(
            document,
            [],
            entryLines.Count,
            netFromLines,
            null,
            null,
            entryLines));
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
        IReadOnlyDictionary<Guid, CurrencyReference> currencyLookup,
        bool isVoucher)
    {
        var (currencyGuid, currencyName, currencyCode, currencySymbol, currencyRate) = ResolveCurrencyInfo(rawRow, currencyLookup);
        var (totalRaw, discountRaw, additionsRaw, netRaw) = ResolveDocumentTotals(rawRow, isVoucher);
        var total = ConvertToDocumentCurrency(totalRaw, currencyRate);
        var discount = ConvertToDocumentCurrency(discountRaw, currencyRate);
        var additions = ConvertToDocumentCurrency(additionsRaw, currencyRate);
        var net = ConvertToDocumentCurrency(netRaw, currencyRate);
        var documentDate = record.Date ?? GetDateValue(rawRow, DocumentDateCandidates);
        var notes = FirstNotBlank(record.Notes, GetStringValue(rawRow, NotesCandidates));
        var (settlementTypeCode, settlementTypeName) = ResolveInvoiceSettlementType(rawRow);
        if (settlementTypeCode is null)
        {
            (settlementTypeCode, settlementTypeName) = ResolveSettlementType(resolvedType?.Name ?? resolvedType?.Code, isVoucher: false);
        }

        var customerGuid = link?.Customer?.Guid ?? GetGuidValue(rawRow, CustomerGuidCandidates);
        var customerName = ResolveInvoiceCustomerName(rawRow, link);
        var accountGuid = link?.Account?.Guid ?? GetGuidValue(rawRow, AccountGuidCandidates);
        var accountNumber = link?.Account?.Number ?? GetNullableIntValue(rawRow, AccountNumberCandidates);
        var accountCode = FirstNotBlank(link?.Account?.Code, GetStringValue(rawRow, AccountCodeCandidates));
        var accountName = FirstNotBlank(link?.Account?.Name, GetStringValue(rawRow, AccountNameCandidates));
        var (pairsCount, pensCount) = ResolveQuantityCounters(rawRow);
        var discountAccountGuid = GetGuidValue(rawRow, DiscountAccountGuidCandidates) ?? link?.DiscountAccount?.Guid;
        var discountAccountNumber = GetNullableIntValue(rawRow, DiscountAccountNumberCandidates) ?? link?.DiscountAccount?.Number;
        var discountAccountCode = FirstNotBlank(GetStringValue(rawRow, DiscountAccountCodeCandidates), link?.DiscountAccount?.Code);
        var discountAccountName = FirstNotBlank(GetStringValue(rawRow, DiscountAccountNameCandidates), link?.DiscountAccount?.Name);
        var additionAccountGuid = GetGuidValue(rawRow, AdditionAccountGuidCandidates) ?? link?.AdditionAccount?.Guid;
        var additionAccountNumber = GetNullableIntValue(rawRow, AdditionAccountNumberCandidates) ?? link?.AdditionAccount?.Number;
        var additionAccountCode = FirstNotBlank(GetStringValue(rawRow, AdditionAccountCodeCandidates), link?.AdditionAccount?.Code);
        var additionAccountName = FirstNotBlank(GetStringValue(rawRow, AdditionAccountNameCandidates), link?.AdditionAccount?.Name);
        return new BillDocumentResponse(
            record.Guid,
            record.Number,
            documentDate,
            record.TypeGuid,
            resolvedType?.Code,
            resolvedType?.Name,
            currencyGuid,
            currencyName,
            currencyCode,
            currencySymbol,
            currencyRate,
            settlementTypeCode,
            settlementTypeName,
            customerGuid,
            customerName,
            accountGuid,
            accountNumber,
            accountCode,
            accountName,
            pairsCount,
            pensCount,
            total,
            discount,
            additions,
            net,
            discountAccountGuid,
            discountAccountNumber,
            discountAccountCode,
            discountAccountName,
            additionAccountGuid,
            additionAccountNumber,
            additionAccountCode,
            additionAccountName,
            notes);
    }

    private BillDocumentResponse BuildDocumentResponse(
        PaymentRecord record,
        ResolvedType? resolvedType,
        IReadOnlyDictionary<string, object?>? rawRow,
        DocumentLinkInfo? link,
        IReadOnlyDictionary<Guid, CurrencyReference> currencyLookup,
        bool isVoucher)
    {
        var (currencyGuid, currencyName, currencyCode, currencySymbol, currencyRate) = ResolveCurrencyInfo(rawRow, currencyLookup);
        var (totalRaw, discountRaw, additionsRaw, netRaw) = ResolveDocumentTotals(rawRow, isVoucher);
        var total = ConvertToDocumentCurrency(totalRaw, currencyRate);
        var discount = ConvertToDocumentCurrency(discountRaw, currencyRate);
        var additions = ConvertToDocumentCurrency(additionsRaw, currencyRate);
        var net = ConvertToDocumentCurrency(netRaw, currencyRate);
        var documentDate = record.Date ?? GetDateValue(rawRow, DocumentDateCandidates);
        var notes = FirstNotBlank(record.Notes, GetStringValue(rawRow, NotesCandidates));
        var (settlementTypeCode, settlementTypeName) = ResolveSettlementType(resolvedType?.Name ?? resolvedType?.Code, isVoucher);
        var (pairsCount, pensCount) = ResolveQuantityCounters(rawRow);
        var discountAccountGuid = GetGuidValue(rawRow, DiscountAccountGuidCandidates) ?? link?.DiscountAccount?.Guid;
        var discountAccountNumber = GetNullableIntValue(rawRow, DiscountAccountNumberCandidates) ?? link?.DiscountAccount?.Number;
        var discountAccountCode = FirstNotBlank(GetStringValue(rawRow, DiscountAccountCodeCandidates), link?.DiscountAccount?.Code);
        var discountAccountName = FirstNotBlank(GetStringValue(rawRow, DiscountAccountNameCandidates), link?.DiscountAccount?.Name);
        var additionAccountGuid = GetGuidValue(rawRow, AdditionAccountGuidCandidates) ?? link?.AdditionAccount?.Guid;
        var additionAccountNumber = GetNullableIntValue(rawRow, AdditionAccountNumberCandidates) ?? link?.AdditionAccount?.Number;
        var additionAccountCode = FirstNotBlank(GetStringValue(rawRow, AdditionAccountCodeCandidates), link?.AdditionAccount?.Code);
        var additionAccountName = FirstNotBlank(GetStringValue(rawRow, AdditionAccountNameCandidates), link?.AdditionAccount?.Name);
        return new BillDocumentResponse(
            record.Guid,
            record.Number,
            documentDate,
            record.TypeGuid,
            resolvedType?.Code,
            resolvedType?.Name,
            currencyGuid,
            currencyName,
            currencyCode,
            currencySymbol,
            currencyRate,
            settlementTypeCode,
            settlementTypeName,
            link?.Customer?.Guid,
            link?.Customer?.CustomerName,
            link?.Account?.Guid,
            link?.Account?.Number,
            link?.Account?.Code,
            link?.Account?.Name,
            pairsCount,
            pensCount,
            total,
            discount,
            additions,
            net,
            discountAccountGuid,
            discountAccountNumber,
            discountAccountCode,
            discountAccountName,
            additionAccountGuid,
            additionAccountNumber,
            additionAccountCode,
            additionAccountName,
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

        var compoundEntryGuids = entryGuidsByDocument.Values
            .SelectMany(value => value)
            .Distinct()
            .ToArray();
        var lineEntries = documentKind == DocumentKind.Voucher && compoundEntryGuids.Length > 0
            ? await mainDbContext.Entries
                .AsNoTracking()
                .Where(entry => entry.ParentGuid.HasValue && compoundEntryGuids.Contains(entry.ParentGuid.Value))
                .ToListAsync(cancellationToken)
            : [];
        var lineEntriesByCompoundGuid = lineEntries
            .GroupBy(entry => entry.ParentGuid!.Value)
            .ToDictionary(group => group.Key, group => group.ToArray());
        var entryGuids = compoundEntryGuids
            .Concat(lineEntries.Select(entry => entry.Guid))
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
            .Concat(entries.Select(entry => entry.ContraAccountGuid))
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
                ? relatedEntryGuids
                    .SelectMany(entryGuid =>
                    {
                        if (documentKind == DocumentKind.Voucher
                            && lineEntriesByCompoundGuid.TryGetValue(entryGuid, out var compoundLines)
                            && compoundLines.Length > 0)
                        {
                            return compoundLines;
                        }

                        var entry = entryLookup.GetValueOrDefault(entryGuid);
                        return entry is null ? [] : new[] { entry };
                    })
                    .DistinctBy(entry => entry.Guid)
                    .ToArray()
                : [];
            var preferredEntry = linkedEntries.FirstOrDefault(entry => entry.CustomerGuid.HasValue || entry.AccountGuid.HasValue)
                ?? linkedEntries.FirstOrDefault();

            Guid? customerGuid;
            Guid? accountGuid;
            if (documentKind == DocumentKind.Invoice)
            {
                accountGuid = rowAccountGuidByDocument.GetValueOrDefault(documentGuid) ?? preferredEntry?.AccountGuid;
                customerGuid = rowCustomerGuidByDocument.GetValueOrDefault(documentGuid) ?? preferredEntry?.CustomerGuid;
            }
            else
            {
                customerGuid = preferredEntry?.CustomerGuid ?? rowCustomerGuidByDocument.GetValueOrDefault(documentGuid);
                accountGuid = preferredEntry?.AccountGuid ?? rowAccountGuidByDocument.GetValueOrDefault(documentGuid);
            }
            var customer = customerGuid.HasValue && customerLookup.TryGetValue(customerGuid.Value, out var resolvedCustomer)
                ? resolvedCustomer
                : null;
            var account = accountGuid.HasValue && accountLookup.TryGetValue(accountGuid.Value, out var resolvedAccount)
                ? resolvedAccount
                : null;
            var candidateAccounts = linkedEntries
                .SelectMany(entry => new[] { entry.AccountGuid, entry.ContraAccountGuid })
                .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
                .Select(guid => accountLookup.GetValueOrDefault(guid!.Value))
                .Where(foundAccount => foundAccount is not null)
                .Cast<AccountRecord>()
                .DistinctBy(foundAccount => foundAccount.Guid)
                .ToArray();
            var discountAccount = candidateAccounts
                .FirstOrDefault(foundAccount => IsDiscountAccount(foundAccount.Name, foundAccount.Code));
            var additionAccount = candidateAccounts
                .FirstOrDefault(foundAccount => IsAdditionAccount(foundAccount.Name, foundAccount.Code));
            result[documentGuid] = new DocumentLinkInfo(
                customer,
                account,
                discountAccount is null ? null : new AccountReference(discountAccount.Guid, discountAccount.Number, discountAccount.Code, discountAccount.Name),
                additionAccount is null ? null : new AccountReference(additionAccount.Guid, additionAccount.Number, additionAccount.Code, additionAccount.Name));
        }

        return result;
    }

    private async Task<IReadOnlyCollection<BillDocumentItemResponse>> BuildBillItemResponsesAsync(
        IReadOnlyCollection<IReadOnlyDictionary<string, object?>> billItemRows,
        double? currencyRate,
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
                var quantityUnit1 = GetNumberValue(row, ItemQuantityUnit1Candidates);
                var quantityUnit2 = GetNumberValue(row, ItemQuantityUnit2Candidates);
                var quantity = quantityUnit1 ?? GetNumberValue(row, ItemQuantityCandidates) ?? quantityUnit2;
                var unitPriceMain = GetNumberValue(row, ItemUnitPriceUnit1Candidates)
                    ?? GetNumberValue(row, ItemPriceCandidates);
                var discountMain = GetNumberValue(row, ItemDiscountCandidates);
                var additionsMain = GetNumberValue(row, ItemAdditionCandidates);
                var lineTotalMain = GetNumberValue(row, ItemLineTotalCandidates)
                    ?? ComputeLineTotal(quantity, unitPriceMain, discountMain, additionsMain);
                var price = ConvertToDocumentCurrency(unitPriceMain, currencyRate);
                var discount = ConvertToDocumentCurrency(discountMain, currencyRate);
                var additions = ConvertToDocumentCurrency(additionsMain, currencyRate);
                var lineTotal = ConvertToDocumentCurrency(lineTotalMain, currencyRate);
                var material = materialGuid.HasValue && materialLookup.TryGetValue(materialGuid.Value, out var resolvedMaterial)
                    ? resolvedMaterial
                    : null;

                return new BillDocumentItemResponse(
                    itemGuid,
                    materialGuid,
                    material?.Number,
                    material?.Code,
                    material?.Name,
                    quantityUnit1,
                    quantityUnit2,
                    price,
                    quantity,
                    price,
                    discount,
                    additions,
                    lineTotal);
            })
            .ToArray();

        return items;
    }

    private async Task<Guid[]> ResolveVoucherCompoundEntryGuidsAsync(
        Guid paymentGuid,
        CancellationToken cancellationToken)
    {
        var compoundGuids = new HashSet<Guid>();

        var paymentRelationGuids = await mainDbContext.EntryPaymentRelations
            .AsNoTracking()
            .Where(relation => relation.PaymentGuid == paymentGuid)
            .Select(relation => relation.EntryGuid)
            .ToListAsync(cancellationToken);
        foreach (var entryGuid in paymentRelationGuids)
        {
            compoundGuids.Add(entryGuid);
        }

        var parentRelationGuids = await mainDbContext.EntryRelations
            .AsNoTracking()
            .Where(relation => relation.ParentGuid == paymentGuid)
            .Select(relation => relation.EntryGuid)
            .ToListAsync(cancellationToken);
        foreach (var entryGuid in parentRelationGuids)
        {
            compoundGuids.Add(entryGuid);
        }

        return compoundGuids.ToArray();
    }

    private async Task<IReadOnlyCollection<EntryRecord>> LoadVoucherEntryLinesAsync(
        Guid paymentGuid,
        CancellationToken cancellationToken)
    {
        var compoundGuids = await ResolveVoucherCompoundEntryGuidsAsync(paymentGuid, cancellationToken);
        if (compoundGuids.Length == 0)
        {
            return [];
        }

        var lineEntries = await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => entry.ParentGuid.HasValue && compoundGuids.Contains(entry.ParentGuid.Value))
            .OrderBy(entry => entry.Number)
            .ThenBy(entry => entry.Guid)
            .ToListAsync(cancellationToken);
        if (lineEntries.Count > 0)
        {
            return lineEntries;
        }

        return await mainDbContext.Entries
            .AsNoTracking()
            .Where(entry => compoundGuids.Contains(entry.Guid))
            .OrderBy(entry => entry.Number)
            .ThenBy(entry => entry.Guid)
            .ToListAsync(cancellationToken);
    }

    private async Task<IReadOnlyCollection<VoucherEntryLineResponse>> BuildVoucherEntryLineResponsesAsync(
        IReadOnlyCollection<EntryRecord> entryLineRecords,
        double? currencyRate,
        CancellationToken cancellationToken)
    {
        if (entryLineRecords.Count == 0)
        {
            return [];
        }

        var customerGuids = entryLineRecords
            .Select(entry => entry.CustomerGuid)
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();
        var accountGuids = entryLineRecords
            .SelectMany(entry => new[] { entry.AccountGuid, entry.ContraAccountGuid })
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

        return entryLineRecords
            .Select(entry =>
            {
                var account = entry.AccountGuid.HasValue && accountLookup.TryGetValue(entry.AccountGuid.Value, out var resolvedAccount)
                    ? resolvedAccount
                    : null;
                var contraAccount = entry.ContraAccountGuid.HasValue && accountLookup.TryGetValue(entry.ContraAccountGuid.Value, out var resolvedContraAccount)
                    ? resolvedContraAccount
                    : null;
                var customer = entry.CustomerGuid.HasValue && customerLookup.TryGetValue(entry.CustomerGuid.Value, out var resolvedCustomer)
                    ? resolvedCustomer
                    : null;
                var debit = ConvertToDocumentCurrency(entry.Debit, currencyRate);
                var credit = ConvertToDocumentCurrency(entry.Credit, currencyRate);
                return new VoucherEntryLineResponse(
                    entry.Guid,
                    entry.Number,
                    entry.Date,
                    account?.Guid,
                    account?.Number,
                    account?.Code,
                    account?.Name,
                    contraAccount?.Guid,
                    contraAccount?.Number,
                    contraAccount?.Code,
                    contraAccount?.Name,
                    customer?.Guid,
                    FirstNotBlank(customer?.CustomerName, customer?.LatinName),
                    debit,
                    credit,
                    entry.Notes);
            })
            .ToArray();
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

    private async Task<Dictionary<Guid, CurrencyReference>> ResolveCurrencyReferencesAsync(
        IEnumerable<IReadOnlyDictionary<string, object?>> rows,
        CancellationToken cancellationToken)
    {
        var currencyGuids = rows
            .Select(row => GetGuidValue(row, CurrencyGuidCandidates))
            .Where(guid => guid.HasValue && guid.Value != Guid.Empty)
            .Select(guid => guid!.Value)
            .Distinct()
            .ToArray();
        if (currencyGuids.Length == 0)
        {
            return [];
        }

        return await UseOpenSqlConnectionAsync(async connection =>
        {
            await using var command = connection.CreateCommand();
            command.CommandType = CommandType.Text;
            var parameterNames = new string[currencyGuids.Length];
            for (var index = 0; index < currencyGuids.Length; index++)
            {
                var parameterName = $"@c{index}";
                parameterNames[index] = parameterName;
                command.Parameters.Add(new SqlParameter(parameterName, SqlDbType.UniqueIdentifier) { Value = currencyGuids[index] });
            }

            command.CommandText = $"SELECT * FROM [my000] WHERE [GUID] IN ({string.Join(", ", parameterNames)})";
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);

            var lookup = new Dictionary<Guid, CurrencyReference>(currencyGuids.Length);
            while (await reader.ReadAsync(cancellationToken))
            {
                var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
                for (var index = 0; index < reader.FieldCount; index++)
                {
                    row[reader.GetName(index)] = await reader.IsDBNullAsync(index, cancellationToken)
                        ? null
                        : reader.GetValue(index);
                }

                if (GetGuidValue(row, "GUID") is not { } currencyGuid || currencyGuid == Guid.Empty)
                {
                    continue;
                }

                var name = FirstNotBlank(
                    GetStringValue(row, CurrencyLookupNameCandidates),
                    GetStringValue(row, "ArabicName"),
                    GetStringValue(row, "AName"));
                var code = FirstNotBlank(
                    GetStringValue(row, CurrencyLookupCodeCandidates),
                    GetStringValue(row, "Code"),
                    GetStringValue(row, "Abbrev"));
                var symbol = FirstNotBlank(
                    GetStringValue(row, CurrencyLookupSymbolCandidates),
                    ResolveCurrencySymbolFromCode(code));
                lookup[currencyGuid] = new CurrencyReference(currencyGuid, name, code, symbol);
            }

            return lookup;
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

    private static (string? SettlementTypeCode, string? SettlementTypeName) ResolveInvoiceSettlementType(
        IReadOnlyDictionary<string, object?>? row)
    {
        if (row is null)
        {
            return (null, null);
        }

        var payType = GetNullableIntValue(row, PayTypeCandidates);
        return payType switch
        {
            0 => ("cash", "نقد"),
            1 => ("credit", "آجل"),
            _ => (null, null)
        };
    }

    private static string? ResolveInvoiceCustomerName(
        IReadOnlyDictionary<string, object?>? row,
        DocumentLinkInfo? link)
    {
        if (!string.IsNullOrWhiteSpace(link?.Customer?.CustomerName))
        {
            return link.Customer.CustomerName;
        }

        return GetStringValue(row, InvoiceCustomerNameCandidates);
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

    private static double? ConvertToDocumentCurrency(double? amount, double? currencyRate)
    {
        if (!amount.HasValue)
        {
            return null;
        }

        if (!currencyRate.HasValue || currencyRate.Value <= 0d)
        {
            return amount;
        }

        return amount.Value / currencyRate.Value;
    }

    private static (Guid? CurrencyGuid, string? CurrencyName, string? CurrencyCode, string? CurrencySymbol, double? CurrencyRate) ResolveCurrencyInfo(
        IReadOnlyDictionary<string, object?>? row,
        IReadOnlyDictionary<Guid, CurrencyReference> currencyLookup)
    {
        var currencyGuid = GetGuidValue(row, CurrencyGuidCandidates);
        var currencyRate = GetNumberValue(row, CurrencyRateCandidates);
        if (!currencyRate.HasValue || currencyRate.Value <= 0d)
        {
            currencyRate = 1d;
        }

        currencyLookup.TryGetValue(currencyGuid ?? Guid.Empty, out var currencyReference);
        var currencyName = FirstNotBlank(
            currencyReference?.Name,
            GetStringValue(row, DocumentCurrencyNameCandidates));
        var currencyCode = FirstNotBlank(
            currencyReference?.Code,
            GetStringValue(row, DocumentCurrencyCodeCandidates));
        var resolvedSymbol = ResolveCurrencySymbol(currencyCode, currencyName);
        var currencySymbol = FirstNotBlank(
            resolvedSymbol,
            currencyReference?.Symbol,
            GetStringValue(row, DocumentCurrencySymbolCandidates),
            "ل.س");
        return (currencyGuid, currencyName, currencyCode, currencySymbol, currencyRate);
    }

    private static (double? PairsCount, double? PensCount) ResolveQuantityCounters(IReadOnlyDictionary<string, object?>? row)
    {
        if (row is null)
        {
            return (null, null);
        }

        var pairsCount = GetNumberValue(row, PairsCountCandidates);
        var pensCount = GetNumberValue(row, PensCountCandidates);
        return (pairsCount, pensCount);
    }

    private static string? ResolveCurrencySymbol(string? currencyCode, string? currencyName)
    {
        return FirstNotBlank(
            ResolveCurrencySymbolFromCode(currencyCode),
            ResolveCurrencySymbolFromCode(currencyName));
    }

    private static string? ResolveCurrencySymbolFromCode(string? currencyCode)
    {
        if (string.IsNullOrWhiteSpace(currencyCode))
        {
            return null;
        }

        var normalized = currencyCode.Trim().ToUpperInvariant();
        if (normalized is "$" or "US$")
        {
            return "$";
        }

        if (normalized is "€")
        {
            return "€";
        }

        if (normalized is "₺")
        {
            return "₺";
        }

        if (normalized is "ر.س" or "ر س")
        {
            return "ر.س";
        }

        if (normalized is "ل.س" or "ل س" or "LS" or "SP")
        {
            return "ل.س";
        }

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
            return "ر.س";
        }

        if (normalized.Contains("SYP") || normalized.Contains("LS") || normalized.Contains("SP"))
        {
            return "ل.س";
        }

        return null;
    }

    private async Task<Guid[]> ResolveSearchRelationGuidsAsync(
        string searchTerm,
        DocumentKind documentKind,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(searchTerm))
        {
            return [];
        }

        var term = searchTerm.Trim();
        var hasNumberTerm = int.TryParse(term, out var numberTerm);
        if (documentKind == DocumentKind.Invoice)
        {
            return await (
                from relation in mainDbContext.EntryBillRelations.AsNoTracking()
                where relation.BillGuid.HasValue
                join entry in mainDbContext.Entries.AsNoTracking()
                    on relation.EntryGuid equals entry.Guid
                join account in mainDbContext.Accounts.AsNoTracking()
                    on entry.AccountGuid equals (Guid?)account.Guid into accountJoin
                from account in accountJoin.DefaultIfEmpty()
                join contraAccount in mainDbContext.Accounts.AsNoTracking()
                    on entry.ContraAccountGuid equals (Guid?)contraAccount.Guid into contraAccountJoin
                from contraAccount in contraAccountJoin.DefaultIfEmpty()
                join customer in mainDbContext.Customers.AsNoTracking()
                    on entry.CustomerGuid equals (Guid?)customer.Guid into customerJoin
                from customer in customerJoin.DefaultIfEmpty()
                where (entry.Notes != null && entry.Notes.Contains(term))
                    || (account != null && (
                        (account.Name != null && account.Name.Contains(term))
                        || (account.Code != null && account.Code.Contains(term))
                        || (hasNumberTerm && account.Number.HasValue && account.Number.Value == numberTerm)))
                    || (contraAccount != null && (
                        (contraAccount.Name != null && contraAccount.Name.Contains(term))
                        || (contraAccount.Code != null && contraAccount.Code.Contains(term))
                        || (hasNumberTerm && contraAccount.Number.HasValue && contraAccount.Number.Value == numberTerm)))
                    || (customer != null && (
                        (customer.CustomerName != null && customer.CustomerName.Contains(term))
                        || (customer.LatinName != null && customer.LatinName.Contains(term))))
                select relation.BillGuid!.Value
            )
            .Distinct()
            .ToArrayAsync(cancellationToken);
        }

        var directPaymentGuids = await (
            from relation in mainDbContext.EntryPaymentRelations.AsNoTracking()
            where relation.PaymentGuid.HasValue
            join entry in mainDbContext.Entries.AsNoTracking()
                on relation.EntryGuid equals entry.Guid
            join account in mainDbContext.Accounts.AsNoTracking()
                on entry.AccountGuid equals (Guid?)account.Guid into accountJoin
            from account in accountJoin.DefaultIfEmpty()
            join contraAccount in mainDbContext.Accounts.AsNoTracking()
                on entry.ContraAccountGuid equals (Guid?)contraAccount.Guid into contraAccountJoin
            from contraAccount in contraAccountJoin.DefaultIfEmpty()
            join customer in mainDbContext.Customers.AsNoTracking()
                on entry.CustomerGuid equals (Guid?)customer.Guid into customerJoin
            from customer in customerJoin.DefaultIfEmpty()
            where (entry.Notes != null && entry.Notes.Contains(term))
                || (account != null && (
                    (account.Name != null && account.Name.Contains(term))
                    || (account.Code != null && account.Code.Contains(term))
                    || (hasNumberTerm && account.Number.HasValue && account.Number.Value == numberTerm)))
                || (contraAccount != null && (
                    (contraAccount.Name != null && contraAccount.Name.Contains(term))
                    || (contraAccount.Code != null && contraAccount.Code.Contains(term))
                    || (hasNumberTerm && contraAccount.Number.HasValue && contraAccount.Number.Value == numberTerm)))
                || (customer != null && (
                    (customer.CustomerName != null && customer.CustomerName.Contains(term))
                    || (customer.LatinName != null && customer.LatinName.Contains(term))))
            select relation.PaymentGuid!.Value
        )
        .Distinct()
        .ToArrayAsync(cancellationToken);

        var linePaymentGuids = await (
            from relation in mainDbContext.EntryPaymentRelations.AsNoTracking()
            where relation.PaymentGuid.HasValue
            join entry in mainDbContext.Entries.AsNoTracking()
                on relation.EntryGuid equals entry.ParentGuid
            join account in mainDbContext.Accounts.AsNoTracking()
                on entry.AccountGuid equals (Guid?)account.Guid into accountJoin
            from account in accountJoin.DefaultIfEmpty()
            join contraAccount in mainDbContext.Accounts.AsNoTracking()
                on entry.ContraAccountGuid equals (Guid?)contraAccount.Guid into contraAccountJoin
            from contraAccount in contraAccountJoin.DefaultIfEmpty()
            join customer in mainDbContext.Customers.AsNoTracking()
                on entry.CustomerGuid equals (Guid?)customer.Guid into customerJoin
            from customer in customerJoin.DefaultIfEmpty()
            where (entry.Notes != null && entry.Notes.Contains(term))
                || (account != null && (
                    (account.Name != null && account.Name.Contains(term))
                    || (account.Code != null && account.Code.Contains(term))
                    || (hasNumberTerm && account.Number.HasValue && account.Number.Value == numberTerm)))
                || (contraAccount != null && (
                    (contraAccount.Name != null && contraAccount.Name.Contains(term))
                    || (contraAccount.Code != null && contraAccount.Code.Contains(term))
                    || (hasNumberTerm && contraAccount.Number.HasValue && contraAccount.Number.Value == numberTerm)))
                || (customer != null && (
                    (customer.CustomerName != null && customer.CustomerName.Contains(term))
                    || (customer.LatinName != null && customer.LatinName.Contains(term))))
            select relation.PaymentGuid!.Value
        )
        .Distinct()
        .ToArrayAsync(cancellationToken);

        return directPaymentGuids
            .Concat(linePaymentGuids)
            .Distinct()
            .ToArray();
    }

    private static IQueryable<BillHeaderRecord> ApplySearchFilter(
        IQueryable<BillHeaderRecord> query,
        string search,
        IReadOnlyCollection<Guid> relatedDocumentGuids)
    {
        var term = search.Trim();
        var relatedGuids = relatedDocumentGuids.Count == 0
            ? Array.Empty<Guid>()
            : relatedDocumentGuids.Distinct().ToArray();
        var hasRelatedGuids = relatedGuids.Length > 0;

        if (int.TryParse(term, out var number))
        {
            return hasRelatedGuids
                ? query.Where(record => record.Number == number
                    || (record.Notes != null && record.Notes.Contains(term))
                    || relatedGuids.Contains(record.Guid))
                : query.Where(record => record.Number == number
                    || (record.Notes != null && record.Notes.Contains(term)));
        }

        if (DateTime.TryParse(term, out var date))
        {
            var targetDate = date.Date;
            return hasRelatedGuids
                ? query.Where(record => (record.Date.HasValue && record.Date.Value.Date == targetDate)
                    || (record.Notes != null && record.Notes.Contains(term))
                    || relatedGuids.Contains(record.Guid))
                : query.Where(record => (record.Date.HasValue && record.Date.Value.Date == targetDate)
                    || (record.Notes != null && record.Notes.Contains(term)));
        }

        return hasRelatedGuids
            ? query.Where(record => (record.Notes != null && record.Notes.Contains(term)) || relatedGuids.Contains(record.Guid))
            : query.Where(record => record.Notes != null && record.Notes.Contains(term));
    }

    private static IQueryable<PaymentRecord> ApplySearchFilter(
        IQueryable<PaymentRecord> query,
        string search,
        IReadOnlyCollection<Guid> relatedDocumentGuids)
    {
        var term = search.Trim();
        var relatedGuids = relatedDocumentGuids.Count == 0
            ? Array.Empty<Guid>()
            : relatedDocumentGuids.Distinct().ToArray();
        var hasRelatedGuids = relatedGuids.Length > 0;

        if (int.TryParse(term, out var number))
        {
            return hasRelatedGuids
                ? query.Where(record => record.Number == number
                    || (record.Notes != null && record.Notes.Contains(term))
                    || relatedGuids.Contains(record.Guid))
                : query.Where(record => record.Number == number
                    || (record.Notes != null && record.Notes.Contains(term)));
        }

        if (DateTime.TryParse(term, out var date))
        {
            var targetDate = date.Date;
            return hasRelatedGuids
                ? query.Where(record => (record.Date.HasValue && record.Date.Value.Date == targetDate)
                    || (record.Notes != null && record.Notes.Contains(term))
                    || relatedGuids.Contains(record.Guid))
                : query.Where(record => (record.Date.HasValue && record.Date.Value.Date == targetDate)
                    || (record.Notes != null && record.Notes.Contains(term)));
        }

        return hasRelatedGuids
            ? query.Where(record => (record.Notes != null && record.Notes.Contains(term)) || relatedGuids.Contains(record.Guid))
            : query.Where(record => record.Notes != null && record.Notes.Contains(term));
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

    private static IReadOnlyCollection<string> ParseKeywordTerms(string? keyword)
    {
        if (string.IsNullOrWhiteSpace(keyword))
        {
            return [];
        }

        return keyword
            .Split((char[]?)null, StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries)
            .Where(term => !string.IsNullOrWhiteSpace(term))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
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

    private static int? GetNullableIntValue(IReadOnlyDictionary<string, object?>? row, params string[] candidates)
    {
        var number = GetNumberValue(row, candidates);
        if (!number.HasValue)
        {
            return null;
        }

        return Convert.ToInt32(Math.Round(number.Value, MidpointRounding.AwayFromZero));
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

    private static DateTime? GetDateValue(IReadOnlyDictionary<string, object?>? row, params string[] candidates)
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

            switch (rawValue)
            {
                case DateTime dateTime:
                    return dateTime;
                case DateTimeOffset dateTimeOffset:
                    return dateTimeOffset.DateTime;
                case string stringValue when DateTime.TryParse(stringValue, out var parsedDate):
                    return parsedDate;
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
            case string stringValue:
                var normalized = stringValue.Trim();
                if (double.TryParse(normalized, NumberStyles.Any, CultureInfo.InvariantCulture, out var parsedInvariant))
                {
                    number = parsedInvariant;
                    return true;
                }

                if (double.TryParse(normalized, NumberStyles.Any, CultureInfo.CurrentCulture, out var parsedCurrent))
                {
                    number = parsedCurrent;
                    return true;
                }

                var swappedSeparators = normalized.Replace(',', '.');
                if (double.TryParse(swappedSeparators, NumberStyles.Any, CultureInfo.InvariantCulture, out var parsedSwapped))
                {
                    number = parsedSwapped;
                    return true;
                }

                number = 0;
                return false;
            case IConvertible convertible:
                try
                {
                    number = convertible.ToDouble(CultureInfo.InvariantCulture);
                    return true;
                }
                catch
                {
                    number = 0;
                    return false;
                }
            default:
                number = 0;
                return false;
        }
    }

    private static bool IsDiscountAccount(string? name, string? code)
    {
        var normalized = $"{name} {code}".Trim().ToLowerInvariant();
        if (normalized.Length == 0)
        {
            return false;
        }

        return normalized.Contains("حسم")
            || normalized.Contains("خصم")
            || normalized.Contains("discount");
    }

    private static bool IsAdditionAccount(string? name, string? code)
    {
        var normalized = $"{name} {code}".Trim().ToLowerInvariant();
        if (normalized.Length == 0)
        {
            return false;
        }

        return normalized.Contains("إضافة")
            || normalized.Contains("اضافة")
            || normalized.Contains("زيادة")
            || normalized.Contains("اضافات")
            || normalized.Contains("addition")
            || normalized.Contains("extra");
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
    private sealed record CurrencyReference(Guid Guid, string? Name, string? Code, string? Symbol);
    private sealed record AccountReference(Guid Guid, int? Number, string? Code, string? Name);
    private sealed record DocumentLinkInfo(
        CustomerRecord? Customer,
        AccountRecord? Account,
        AccountReference? DiscountAccount,
        AccountReference? AdditionAccount);

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
