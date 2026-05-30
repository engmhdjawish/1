using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Contracts.Customers;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/customers")]
[RequirePermission("customers.read")]
public sealed class CustomersController(
    MainDbContext mainDbContext,
    IPermissionService permissionService,
    IFieldMasker fieldMasker) : ControllerBase
{
    private const string ResourceCode = "customers";

    [HttpGet]
    public async Task<ActionResult<PagedResponse<CustomerResponse>>> GetCustomers(
        [FromQuery] string? keyword = null,
        [FromQuery(Name = "search")] string? legacySearch = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var query = mainDbContext.Customers.AsNoTracking();
        var keywordTerms = ParseKeywordTerms(!string.IsNullOrWhiteSpace(keyword) ? keyword : legacySearch);
        foreach (var term in keywordTerms)
        {
            query = query.Where(customer =>
                (customer.CustomerName != null && customer.CustomerName.Contains(term)) ||
                (customer.LatinName != null && customer.LatinName.Contains(term)) ||
                (customer.Phone1 != null && customer.Phone1.Contains(term)) ||
                (customer.Phone2 != null && customer.Phone2.Contains(term)) ||
                (customer.Mobile != null && customer.Mobile.Contains(term)) ||
                (customer.Email != null && customer.Email.Contains(term)) ||
                (customer.BarCode != null && customer.BarCode.Contains(term)));
        }

        var totalCount = await query.CountAsync(cancellationToken);
        var customers = await query
            .OrderBy(customer => customer.Number)
            .ThenBy(customer => customer.CustomerName)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        var response = customers.Select(customer => ToResponse(customer, fieldAccess)).ToArray();

        return Ok(new PagedResponse<CustomerResponse>(response, page, pageSize, totalCount));
    }

    [HttpGet("{guid:guid}")]
    public async Task<ActionResult<CustomerResponse>> GetCustomer(Guid guid, CancellationToken cancellationToken)
    {
        var customer = await mainDbContext.Customers
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == guid, cancellationToken);

        if (customer is null)
        {
            return NotFound();
        }

        var fieldAccess = await permissionService.GetFieldAccessAsync(User, ResourceCode, cancellationToken);
        return Ok(ToResponse(customer, fieldAccess));
    }

    private CustomerResponse ToResponse(
        CustomerRecord customer,
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess) =>
        new(
            customer.Guid,
            customer.Number,
            customer.CustomerName,
            customer.LatinName,
            ResolveString(fieldAccess, nameof(customer.Phone1), customer.Phone1),
            ResolveString(fieldAccess, nameof(customer.Phone2), customer.Phone2),
            ResolveString(fieldAccess, nameof(customer.Mobile), customer.Mobile),
            ResolveString(fieldAccess, "EMail", customer.Email),
            ResolveGuid(fieldAccess, "AccountGUID", customer.AccountGuid),
            customer.BarCode,
            customer.Type,
            customer.State,
            customer.UseFlag,
            customer.Security,
            customer.Notes);

    private string? ResolveString(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        string fieldName,
        string? value)
    {
        if (value is null || !fieldAccess.TryGetValue(fieldName, out var decision))
        {
            return value;
        }

        return decision.ReadMode switch
        {
            FieldAccessMode.Deny => null,
            FieldAccessMode.Mask => Convert.ToString(fieldMasker.Mask(value, decision.MaskingStrategy)),
            _ => value
        };
    }

    private string? ResolveGuid(
        IReadOnlyDictionary<string, FieldAccessDecision> fieldAccess,
        string fieldName,
        Guid? value)
    {
        if (value is null)
        {
            return null;
        }

        if (!fieldAccess.TryGetValue(fieldName, out var decision))
        {
            return value.Value.ToString();
        }

        return decision.ReadMode switch
        {
            FieldAccessMode.Deny => null,
            FieldAccessMode.Mask => Convert.ToString(fieldMasker.Mask(value.Value, decision.MaskingStrategy)),
            _ => value.Value.ToString()
        };
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
}

