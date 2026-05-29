namespace ExistingDb.Api.Contracts.Bills;

public sealed record BillDocumentResponse(
    Guid Guid,
    int? Number,
    DateTime? Date,
    Guid? TypeGuid,
    string? TypeCode,
    string? TypeName,
    string? Notes);
