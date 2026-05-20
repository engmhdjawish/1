namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerResponse(
    Guid Guid,
    int? Number,
    string? CustomerName,
    string? LatinName,
    string? Phone1,
    string? Phone2,
    string? Mobile,
    string? Email,
    string? AccountGuid,
    string? BarCode,
    int? Type,
    int? State,
    int? UseFlag,
    int? Security,
    string? Notes);

