namespace ExistingDb.Api.Contracts.Inventory;

public sealed record InventoryAgeItemResponse(
    Guid MaterialGuid,
    string? Name,
    string? MaterialCode,
    Guid? GroupGuid,
    Guid? ProductImageGuid,
    double StagnantRemainingQty,
    double? TotalRemainingQty,
    double MaxAgeDays,
    DateTime? OldestPurchaseDate,
    object? UnitSalePriceUsd,
    object? UnitPurchasePriceUsd,
    object? UnitSalePriceSyp);
