namespace ExistingDb.Api.Contracts.Bills;

public sealed record BillDocumentResponse(
    Guid Guid,
    int? Number,
    DateTime? Date,
    Guid? TypeGuid,
    string? TypeCode,
    string? TypeName,
    string? SettlementTypeCode,
    string? SettlementTypeName,
    Guid? CustomerGuid,
    string? CustomerName,
    Guid? AccountGuid,
    int? AccountNumber,
    string? AccountCode,
    string? AccountName,
    double? TotalAmount,
    double? TotalDiscount,
    double? TotalAdditions,
    double? NetAmount,
    string? Notes);
