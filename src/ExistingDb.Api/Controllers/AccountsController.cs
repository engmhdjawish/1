using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Accounts;
using ExistingDb.Api.Contracts.Common;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using ExistingDb.Api.Services.Search;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/accounts")]
[RequirePermission("accounts.read")]
public sealed class AccountsController(MainDbContext mainDbContext) : ControllerBase
{
    [HttpGet]
    public async Task<ActionResult<PagedResponse<AccountResponse>>> GetAccounts(
        [FromQuery] string? keyword = null,
        [FromQuery(Name = "search")] string? legacySearch = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 1, 200);

        var query = mainDbContext.Accounts.AsNoTracking();
        foreach (var term in KeywordSearchTerms.Parse(keyword, legacySearch))
        {
            query = ApplyAccountKeywordFilter(query, term);
        }

        var totalCount = await query.CountAsync(cancellationToken);
        var accounts = await query
            .OrderBy(account => account.Number)
            .ThenBy(account => account.Name)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        return Ok(new PagedResponse<AccountResponse>(
            accounts.Select(ToResponse).ToArray(),
            page,
            pageSize,
            totalCount));
    }

    [HttpGet("{guid:guid}")]
    public async Task<ActionResult<AccountResponse>> GetAccount(Guid guid, CancellationToken cancellationToken = default)
    {
        var account = await mainDbContext.Accounts
            .AsNoTracking()
            .SingleOrDefaultAsync(record => record.Guid == guid, cancellationToken);
        if (account is null)
        {
            return NotFound();
        }

        return Ok(ToResponse(account));
    }

    private static IQueryable<AccountRecord> ApplyAccountKeywordFilter(IQueryable<AccountRecord> query, string term)
    {
        if (int.TryParse(term, out var numberTerm))
        {
            return query.Where(account =>
                (account.Name != null && account.Name.Contains(term)) ||
                (account.Code != null && account.Code.Contains(term)) ||
                (account.Number.HasValue && account.Number.Value == numberTerm));
        }

        return query.Where(account =>
            (account.Name != null && account.Name.Contains(term)) ||
            (account.Code != null && account.Code.Contains(term)));
    }

    private static AccountResponse ToResponse(AccountRecord account)
    {
        var currentBalance = (account.InitDebit ?? 0d)
            + (account.Debit ?? 0d)
            - (account.InitCredit ?? 0d)
            - (account.Credit ?? 0d);

        return new AccountResponse(
            account.Guid,
            account.Number,
            account.Code,
            account.Name,
            account.CurrencyGuid,
            account.CurrencyVal,
            account.Debit,
            account.Credit,
            account.InitDebit,
            account.InitCredit,
            currentBalance);
    }
}
