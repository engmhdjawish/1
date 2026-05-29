namespace ExistingDb.Api.Contracts.Customers;

public sealed record GeneralLedgerResponse(
    Guid? CustomerGuid,
    string? CustomerName,
    Guid AccountGuid,
    Guid CurrencyGuid,
    DateTime FromDate,
    DateTime ToDate,
    int ResultSetCount,
    int RowCount,
    IReadOnlyCollection<IReadOnlyDictionary<string, object?>> Rows);
