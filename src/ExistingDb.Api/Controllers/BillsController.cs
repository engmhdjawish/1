using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Bills;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/bills")]
[RequirePermission("bills.read")]
public sealed class BillsController(MainDbContext mainDbContext) : ControllerBase
{
    [HttpGet("invoices")]
    public async Task<ActionResult<PagedResponse<BillDocumentResponse>>> GetInvoices(
        [FromQuery] string? search = null,
        [FromQuery] Guid? typeGuid = null,
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
        var types = await ResolveTypeLookupAsync(
            records.Select(record => record.TypeGuid),
            preferredSource: TypeLookupSource.Bill,
            cancellationToken);
        var items = records
            .Select(record =>
            {
                var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
                    ? resolvedType
                    : null;
                return new BillDocumentResponse(
                    record.Guid,
                    record.Number,
                    record.Date,
                    record.TypeGuid,
                    type?.Code,
                    type?.Name,
                    record.Notes);
            })
            .ToArray();

        return Ok(new PagedResponse<BillDocumentResponse>(items, page, pageSize, totalCount));
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
        var types = await ResolveTypeLookupAsync(
            records.Select(record => record.TypeGuid),
            preferredSource: TypeLookupSource.Entry,
            cancellationToken);
        var items = records
            .Select(record =>
            {
                var type = record.TypeGuid.HasValue && types.TryGetValue(record.TypeGuid.Value, out var resolvedType)
                    ? resolvedType
                    : null;
                return new BillDocumentResponse(
                    record.Guid,
                    record.Number,
                    record.Date,
                    record.TypeGuid,
                    type?.Code,
                    type?.Name,
                    record.Notes);
            })
            .ToArray();

        return Ok(new PagedResponse<BillDocumentResponse>(items, page, pageSize, totalCount));
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

    private enum TypeLookupSource
    {
        Bill = 0,
        Entry = 1
    }
}
