namespace ExistingDb.Api.Contracts.Bills;

public sealed record BillDocumentDetailsResponse(
    BillDocumentResponse Document,
    IReadOnlyCollection<BillDocumentItemResponse> Items,
    int LinesCount,
    double? TotalQuantity,
    double? TotalPairs,
    double? TotalPens,
    IReadOnlyCollection<VoucherEntryLineResponse>? EntryLines = null);
