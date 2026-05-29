namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerAccountStatementEntryResponse(
    Guid EntryGuid,
    DateTime? EntryDate,
    int? EntryNumber,
    double Debit,
    double Credit,
    double SignedAmount,
    double RunningBalance,
    string ReasonType,
    Guid? ReferenceGuid,
    int? ReferenceNumber,
    DateTime? ReferenceDate,
    string? ReferenceNotes,
    string? Notes);
