using ExistingDb.Api.Authorization;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Services.Materials;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Authorize]
[Route("api/materials")]
[RequirePermission("materials.read")]
public sealed class MaterialAgesController(MaterialAgeQuery materialAgeQuery) : ControllerBase
{
    [HttpGet("ages/bill-types")]
    public async Task<ActionResult<MaterialAgeBillTypeListResponse>> GetBillTypes(
        CancellationToken cancellationToken = default)
    {
        var result = await materialAgeQuery.ListBillTypesAsync(cancellationToken);
        return Ok(result);
    }

    [HttpGet("ages")]
    public async Task<ActionResult<MaterialAgeResponse>> GetAges(
        [FromQuery] string? asOf = null,
        [FromQuery] Guid? storeGuid = null,
        [FromQuery] string? storeGuids = null,
        [FromQuery] string? billTypeGuids = null,
        [FromQuery] string? ageMode = null,
        [FromQuery] bool skipEmpty = true,
        [FromQuery] bool skipZeroBalance = false,
        [FromQuery] string? materialType = null,
        [FromQuery] string? materialTypes = null,
        [FromQuery] Guid? groupGuid = null,
        [FromQuery] string? groupGuids = null,
        [FromQuery] string? company = null,
        [FromQuery] string? companies = null,
        [FromQuery] string? keyword = null,
        [FromQuery] string? search = null,
        [FromQuery] int? minAgeDays = null,
        [FromQuery] int? maxAgeDays = null,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 100,
        CancellationToken cancellationToken = default)
    {
        DateOnly? asOfDate = null;
        if (!string.IsNullOrWhiteSpace(asOf))
        {
            if (!DateOnly.TryParseExact(asOf.Trim(), "yyyy-MM-dd", out var parsedAsOf))
            {
                return BadRequest(new { message = "asOf must be yyyy-MM-dd." });
            }

            asOfDate = parsedAsOf;
        }

        if (!TryParseGuidList(storeGuids, allowSemicolon: true, out var storeList, out var storeParseError, "storeGuids"))
        {
            return BadRequest(new { message = storeParseError });
        }

        if (storeGuid is Guid oneStore)
        {
            storeList = storeList.Append(oneStore).Distinct().ToArray();
        }

        if (!TryParseGuidList(billTypeGuids, allowSemicolon: true, out var billTypeList, out var billParseError, "billTypeGuids"))
        {
            return BadRequest(new { message = billParseError });
        }

        if (!TryParseGuidList(groupGuids, allowSemicolon: true, out var groupList, out var groupParseError, "groupGuids"))
        {
            return BadRequest(new { message = groupParseError });
        }

        if (groupGuid is Guid oneGroup)
        {
            groupList = groupList.Append(oneGroup).Distinct().ToArray();
        }

        if (string.IsNullOrWhiteSpace(ageMode))
        {
            return BadRequest(new { message = "ageMode must be oldest, weightedAverage, or layers." });
        }

        var request = new MaterialAgeQuery.Request
        {
            AsOf = asOfDate, // null → SQL CAST(GETDATE() AS date) inside the query service
            AgeMode = ageMode,
            StoreGuids = storeList,
            BillTypeGuids = billTypeList,
            SkipEmpty = skipEmpty,
            SkipZeroBalance = skipZeroBalance,
            MaterialTypes = ParseTextValues(materialType, materialTypes),
            GroupGuids = groupList,
            Companies = ParseTextValues(company, companies),
            Keyword = keyword,
            Search = search,
            MinAgeDays = minAgeDays,
            MaxAgeDays = maxAgeDays,
            Page = page,
            PageSize = pageSize
        };

        var (response, error) = await materialAgeQuery.ExecuteAsync(request, cancellationToken);
        if (error is not null)
        {
            return BadRequest(new
            {
                message = error.Message,
                storeGuid = error.StoreGuid,
                billTypeGuid = error.BillTypeGuid
            });
        }

        return Ok(response);
    }

    private static bool TryParseGuidList(
        string? csv,
        bool allowSemicolon,
        out Guid[] guids,
        out string? error,
        string paramName)
    {
        guids = [];
        error = null;
        if (string.IsNullOrWhiteSpace(csv))
        {
            return true;
        }

        var normalized = csv
            .Replace("%2C", ",", StringComparison.OrdinalIgnoreCase)
            .Replace("%2c", ",", StringComparison.OrdinalIgnoreCase);
        var separators = allowSemicolon ? new[] { ',', ';' } : new[] { ',' };
        var parsed = new List<Guid>();
        foreach (var token in normalized.Split(separators, StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
        {
            if (!Guid.TryParse(token, out var guid))
            {
                error = $"{paramName} contains a value that is not a GUID.";
                return false;
            }

            parsed.Add(guid);
        }

        guids = parsed.Distinct().ToArray();
        return true;
    }

    private static IReadOnlyList<string> ParseTextValues(params string?[] inputs) =>
        inputs
            .Where(input => !string.IsNullOrWhiteSpace(input))
            .SelectMany(input => input!.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
            .Where(value => !string.IsNullOrWhiteSpace(value))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
}
