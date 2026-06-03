namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialPagedResponse(
    IReadOnlyCollection<MaterialResponse> Items,
    int Page,
    int PageSize,
    int TotalCount,
    MaterialAppliedFiltersResponse? AppliedFilters = null,
    MaterialResultFiltersResponse? ResultFilters = null);

public sealed record MaterialAppliedFiltersResponse(
    string? Search,
    IReadOnlyCollection<Guid> StoreGuids,
    IReadOnlyCollection<string> CountryOfOrigins,
    IReadOnlyCollection<string> Manufacturers,
    IReadOnlyCollection<string> SizeRanges,
    IReadOnlyCollection<string> MaterialTypes,
    IReadOnlyCollection<string> AgeCategories,
    IReadOnlyCollection<Guid> GroupGuids,
    double? MinWarehouseQuantity,
    double? MaxWarehouseQuantity,
    bool? IsAvailable,
    double? MinUnitSalePriceSyp,
    double? MaxUnitSalePriceSyp,
    double? MinUnitSalePriceUsd,
    double? MaxUnitSalePriceUsd,
    double? MinUnitPurchasePriceUsd,
    double? MaxUnitPurchasePriceUsd);

public sealed record MaterialResultFiltersResponse(
    IReadOnlyCollection<FacetValueResponse> AgeCategories,
    IReadOnlyCollection<FacetValueResponse> SizeRanges,
    IReadOnlyCollection<FacetValueResponse> MaterialTypes,
    IReadOnlyCollection<FacetValueResponse> Manufacturers,
    IReadOnlyCollection<FacetValueResponse> CountryOfOrigins,
    IReadOnlyCollection<GroupFacetValueResponse> Groups);

public sealed record FacetValueResponse(string Value, int Count);

public sealed record GroupFacetValueResponse(
    Guid Guid,
    string? Code,
    string? Name,
    int Count);
