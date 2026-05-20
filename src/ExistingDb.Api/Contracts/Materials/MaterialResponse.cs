namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialResponse(
    Guid Guid,
    int? Number,
    string? Name,
    string? Code,
    string? LatinName,
    string? BarCode,
    string? PrimaryUnit,
    double? SecondUnitConversionFactor,
    bool? IsSecondUnitConversionFixed,
    double? Qty,
    object? WholesaleSypPrice,
    object? WholesaleUsdPrice,
    object? PurchaseUsdPrice,
    object? AvgPrice,
    object? LastPrice,
    double? CurrencyVal,
    Guid? GroupGuid,
    Guid? CurrencyGuid,
    int? Type,
    int? Security,
    double? UseFlag,
    bool? IsHidden);

