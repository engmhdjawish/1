namespace ExistingDb.Api.Services.Materials;

public sealed class MaterialListFilters
{
    public string? Search { get; init; }
    public IReadOnlyCollection<Guid> StoreGuids { get; init; } = [];
    public IReadOnlyCollection<string> CountryOfOrigins { get; init; } = [];
    public IReadOnlyCollection<string> Manufacturers { get; init; } = [];
    public IReadOnlyCollection<string> SizeRanges { get; init; } = [];
    public IReadOnlyCollection<string> MaterialTypes { get; init; } = [];
    public IReadOnlyCollection<string> AgeCategories { get; init; } = [];
    public IReadOnlyCollection<Guid> GroupGuids { get; init; } = [];
    public double? MinWarehouseQuantity { get; init; }
    public double? MaxWarehouseQuantity { get; init; }
    public bool? IsAvailable { get; init; }
    public double? MinUnitSalePriceSyp { get; init; }
    public double? MaxUnitSalePriceSyp { get; init; }
    public double? MinUnitSalePriceUsd { get; init; }
    public double? MaxUnitSalePriceUsd { get; init; }
    public double? MinUnitPurchasePriceUsd { get; init; }
    public double? MaxUnitPurchasePriceUsd { get; init; }

    public static MaterialListFilters FromQuery(
        string? search,
        Guid? storeGuid,
        string? storeGuids,
        string? countryOfOrigin,
        string? countryOfOrigins,
        string? manufacturer,
        string? manufacturers,
        string? sizeRange,
        string? sizeRanges,
        string? materialType,
        string? materialTypes,
        string? ageCategory,
        string? ageCategories,
        Guid? groupGuid,
        string? groupGuids,
        double? minWarehouseQuantity,
        double? maxWarehouseQuantity,
        bool? isAvailable,
        double? minUnitSalePriceSyp,
        double? maxUnitSalePriceSyp,
        double? minUnitSalePriceUsd,
        double? maxUnitSalePriceUsd,
        double? minUnitPurchasePriceUsd,
        double? maxUnitPurchasePriceUsd) =>
        new()
        {
            Search = string.IsNullOrWhiteSpace(search) ? null : search.Trim(),
            StoreGuids = ParseGuids(storeGuid, storeGuids),
            CountryOfOrigins = ParseTextValues(countryOfOrigin, countryOfOrigins),
            Manufacturers = ParseTextValues(manufacturer, manufacturers),
            SizeRanges = ParseTextValues(sizeRange, sizeRanges),
            MaterialTypes = ParseTextValues(materialType, materialTypes),
            AgeCategories = ParseTextValues(ageCategory, ageCategories),
            GroupGuids = ParseGuids(groupGuid, groupGuids),
            MinWarehouseQuantity = minWarehouseQuantity,
            MaxWarehouseQuantity = maxWarehouseQuantity,
            IsAvailable = isAvailable,
            MinUnitSalePriceSyp = minUnitSalePriceSyp,
            MaxUnitSalePriceSyp = maxUnitSalePriceSyp,
            MinUnitSalePriceUsd = minUnitSalePriceUsd,
            MaxUnitSalePriceUsd = maxUnitSalePriceUsd,
            MinUnitPurchasePriceUsd = minUnitPurchasePriceUsd,
            MaxUnitPurchasePriceUsd = maxUnitPurchasePriceUsd
        };

    private static IReadOnlyCollection<Guid> ParseGuids(Guid? singleGuid, string? commaSeparatedGuids)
    {
        var parsed = new HashSet<Guid>();
        if (singleGuid is not null)
        {
            parsed.Add(singleGuid.Value);
        }

        if (!string.IsNullOrWhiteSpace(commaSeparatedGuids))
        {
            foreach (var value in commaSeparatedGuids.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
            {
                if (Guid.TryParse(value, out var parsedGuid))
                {
                    parsed.Add(parsedGuid);
                }
            }
        }

        return parsed.ToArray();
    }

    private static IReadOnlyCollection<string> ParseTextValues(params string?[] inputs) =>
        inputs
            .Where(input => !string.IsNullOrWhiteSpace(input))
            .SelectMany(input => input!.Split(',', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries))
            .Where(value => !string.IsNullOrWhiteSpace(value))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
}
