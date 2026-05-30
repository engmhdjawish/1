namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialFilterOptionsResponse(
    IReadOnlyCollection<string> CountryOfOrigins,
    IReadOnlyCollection<string> Manufacturers,
    IReadOnlyCollection<string> SizeRanges,
    IReadOnlyCollection<string> MaterialTypes,
    IReadOnlyCollection<string> AgeCategories,
    IReadOnlyCollection<LookupOptionResponse> Groups,
    IReadOnlyCollection<LookupOptionResponse> Stores);

public sealed record LookupOptionResponse(
    Guid Guid,
    string? Code,
    string? Name,
    string? LatinName);

