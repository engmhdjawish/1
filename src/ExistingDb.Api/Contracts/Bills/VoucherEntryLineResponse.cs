namespace ExistingDb.Api.Contracts.Bills;

public sealed record VoucherEntryLineResponse(
    Guid Guid,
    int? Number,
    DateTime? Date,
    Guid? AccountGuid,
    int? AccountNumber,
    string? AccountCode,
    string? AccountName,
    Guid? ContraAccountGuid,
    int? ContraAccountNumber,
    string? ContraAccountCode,
    string? ContraAccountName,
    Guid? CustomerGuid,
    string? CustomerName,
    double? Debit,
    double? Credit,
    string? Notes,
    double? EquivalentValue = null,
    Guid? EquivalentCurrencyGuid = null,
    string? EquivalentCurrencyCode = null,
    string? EquivalentCurrencySymbol = null);
