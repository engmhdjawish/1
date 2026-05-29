namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerAccountStatementResponse(
    Guid CustomerGuid,
    string? CustomerName,
    Guid AccountGuid,
    DateTime? FromDate,
    DateTime? ToDate,
    double OpeningBalance,
    IReadOnlyCollection<CustomerAccountStatementEntryResponse> Entries,
    int Page,
    int PageSize,
    int TotalCount);
