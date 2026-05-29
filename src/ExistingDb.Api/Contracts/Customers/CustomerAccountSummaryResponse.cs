namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerAccountSummaryResponse(
    Guid? CustomerGuid,
    string? CustomerName,
    Guid AccountGuid,
    int? AccountNumber,
    string? AccountCode,
    string? AccountName,
    Guid? AccountCurrencyGuid,
    double AccountCurrencyRate,
    double CurrentDebit,
    double CurrentCredit,
    double CurrentBalance,
    CustomerAccountMovementResponse? LastCreditorMovement,
    CustomerAccountMovementResponse? LastDebtorMovement);
