namespace ExistingDb.Api.Contracts.Bills;

public sealed record BillDocumentItemResponse(
    Guid Guid,
    Guid? MaterialGuid,
    int? MaterialNumber,
    string? MaterialCode,
    string? MaterialName,
    double? Quantity,
    double? Price,
    double? Discount,
    double? Additions,
    double? LineTotal);
