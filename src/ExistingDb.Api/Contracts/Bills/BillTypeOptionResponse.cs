namespace ExistingDb.Api.Contracts.Bills;

public sealed record BillTypeOptionResponse(
    Guid TypeGuid,
    string? TypeCode,
    string? TypeName,
    int DocumentsCount);
