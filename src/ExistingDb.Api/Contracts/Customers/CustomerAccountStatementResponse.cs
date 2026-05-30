namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerAccountStatementResponse(
    Guid? CustomerGuid,
    string? CustomerName,
    Guid AccountGuid,
    Guid? AccountCurrencyGuid,
    double AccountCurrencyRate,
    string? AccountCurrencyName,
    string? AccountCurrencyCode,
    string? AccountCurrencySymbol,
    DateTime? FromDate,
    DateTime? ToDate,
    double OpeningBalance,
    IReadOnlyCollection<CustomerAccountStatementEntryResponse> Entries,
    int Page,
    int PageSize,
    int TotalCount);
