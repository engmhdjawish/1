using ExistingDb.Api.Services.Materials;
using Microsoft.AspNetCore.Mvc;

namespace ExistingDb.Api.Contracts.Materials;

/// <summary>
/// Shared query contract for material list, facets, lookup scope, and batch slices.
/// Singular and plural parameter names stay accepted so existing clients keep working.
/// </summary>
public class MaterialListQuery
{
    public string? Keyword { get; set; }

    [FromQuery(Name = "search")]
    public string? Search { get; set; }

    public Guid? StoreGuid { get; set; }

    public string? StoreGuids { get; set; }

    public string? CountryOfOrigin { get; set; }

    public string? CountryOfOrigins { get; set; }

    public string? Manufacturer { get; set; }

    public string? Manufacturers { get; set; }

    public string? SizeRange { get; set; }

    public string? SizeRanges { get; set; }

    public string? MaterialType { get; set; }

    public string? MaterialTypes { get; set; }

    public string? AgeCategory { get; set; }

    public string? AgeCategories { get; set; }

    public Guid? GroupGuid { get; set; }

    public string? GroupGuids { get; set; }

    public string? MaterialGuids { get; set; }

    public double? MinWarehouseQuantity { get; set; }

    public double? MaxWarehouseQuantity { get; set; }

    public bool? IsAvailable { get; set; }

    public bool? HasImage { get; set; }

    public double? MinUnitSalePriceSyp { get; set; }

    public double? MaxUnitSalePriceSyp { get; set; }

    public double? MinUnitSalePriceUsd { get; set; }

    public double? MaxUnitSalePriceUsd { get; set; }

    public double? MinUnitPurchasePriceUsd { get; set; }

    public double? MaxUnitPurchasePriceUsd { get; set; }

    public string? Sort { get; set; }

    public string? GroupBy { get; set; }

    public bool IncludeResultFilters { get; set; }

    public bool IncludeTotalCount { get; set; } = true;

    public int Page { get; set; } = 1;

    public int PageSize { get; set; } = 50;

    public MaterialListFilters ToFilters() =>
        MaterialListFilters.FromQuery(
            Keyword,
            Search,
            StoreGuid,
            StoreGuids,
            CountryOfOrigin,
            CountryOfOrigins,
            Manufacturer,
            Manufacturers,
            SizeRange,
            SizeRanges,
            MaterialType,
            MaterialTypes,
            AgeCategory,
            AgeCategories,
            GroupGuid,
            GroupGuids,
            MaterialGuids,
            MinWarehouseQuantity,
            MaxWarehouseQuantity,
            IsAvailable,
            HasImage,
            MinUnitSalePriceSyp,
            MaxUnitSalePriceSyp,
            MinUnitSalePriceUsd,
            MaxUnitSalePriceUsd,
            MinUnitPurchasePriceUsd,
            MaxUnitPurchasePriceUsd);
}
