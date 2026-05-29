namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerAccountMovementResponse(
    Guid EntryGuid,
    DateTime? EntryDate,
    int? EntryNumber,
    double? Debit,
    double? Credit,
    double SignedAmount,
    string ReasonType,
    Guid? ReferenceGuid,
    int? ReferenceNumber,
    DateTime? ReferenceDate,
    string? ReferenceNotes,
    string? Notes);
