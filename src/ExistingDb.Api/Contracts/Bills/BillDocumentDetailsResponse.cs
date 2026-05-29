namespace ExistingDb.Api.Contracts.Bills;

public sealed record BillDocumentDetailsResponse(
    BillDocumentResponse Document,
    IReadOnlyCollection<BillDocumentItemResponse> Items);
