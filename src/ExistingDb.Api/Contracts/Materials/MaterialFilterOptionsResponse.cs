namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialFilterOptionsResponse(
    IReadOnlyCollection<string> CountryOfOrigins,
    IReadOnlyCollection<string> Manufacturers,
    IReadOnlyCollection<string> SizeRanges,
    IReadOnlyCollection<string> MaterialTypes,
    IReadOnlyCollection<string> AgeCategories,
    IReadOnlyCollection<LookupOptionResponse> Groups,
    IReadOnlyCollection<LookupOptionResponse> Stores,
    MaterialPriceRangesResponse PriceRanges);

public sealed record LookupOptionResponse(
    Guid Guid,
    string? Code,
    string? Name,
    string? LatinName);

public sealed record MaterialPriceRangesResponse(
    PriceRangeResponse? UnitSalePriceSyp,
    PriceRangeResponse? UnitSalePriceUsd,
    PriceRangeResponse? UnitPurchasePriceUsd);

public sealed record PriceRangeResponse(double? Min, double? Max);

