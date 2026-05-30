namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialResponse(
    Guid MaterialGuid,
    string? Name,
    string? MaterialCode,
    string? PrimaryUnit,
    string? PackageUnit,
    double? PackageConversionFactor,
    double? WarehouseQuantity,
    IReadOnlyCollection<MaterialStoreQuantityResponse>? StoreQuantities,
    object? UnitSalePriceSyp,
    object? UnitSalePriceUsd,
    object? UnitPurchasePriceUsd,
    double? CurrencyRate,
    string? CountryOfOrigin,
    string? Manufacturer,
    string? SizeRange,
    string? MaterialType,
    string? AgeCategory,
    Guid? GroupGuid,
    string? GroupName,
    Guid? ProductImageGuid,
    string? ProductImageTitle,
    Guid? CurrencyGuid,
    bool? IsHidden);

