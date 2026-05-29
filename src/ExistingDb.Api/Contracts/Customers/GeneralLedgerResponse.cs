namespace ExistingDb.Api.Contracts.Customers;

public sealed record GeneralLedgerResponse(
    Guid? CustomerGuid,
    string? CustomerName,
    Guid AccountGuid,
    Guid SourceGuid,
    bool IsCalledByWeb,
    Guid CurrencyGuid,
    DateTime FromDate,
    DateTime ToDate,
    int ResultSetCount,
    int RowCount,
    IReadOnlyCollection<IReadOnlyDictionary<string, object?>> Rows);
