namespace ExistingDb.Api.Contracts.Inventory;

public sealed record InventoryAgePagedResponse(
    int Page,
    int PageSize,
    int TotalCount,
    int MinAgeDays,
    DateTime UntilDate,
    DateTime? YearStartDate,
    IReadOnlyList<InventoryAgeItemResponse> Items);
