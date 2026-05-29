namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerAccountStatementEntryResponse(
    Guid EntryGuid,
    DateTime? EntryDate,
    int? EntryNumber,
    double DebitMainCurrency,
    double CreditMainCurrency,
    double Debit,
    double Credit,
    double SignedAmount,
    double RunningBalance,
    string ReasonType,
    string? ReasonDocumentType,
    double CurrencyRateUsed,
    Guid? ReferenceGuid,
    int? ReferenceNumber,
    DateTime? ReferenceDate,
    string? ReferenceNotes,
    Guid? ContraAccountGuid,
    int? ContraAccountNumber,
    string? ContraAccountCode,
    string? ContraAccountName,
    string? Notes);
