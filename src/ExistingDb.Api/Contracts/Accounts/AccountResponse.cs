namespace ExistingDb.Api.Contracts.Accounts;

public sealed record AccountResponse(
    Guid Guid,
    int? Number,
    string? Code,
    string? Name,
    Guid? CurrencyGuid,
    double? CurrencyRate,
    double? Debit,
    double? Credit,
    double? InitDebit,
    double? InitCredit,
    double? CurrentBalance);
