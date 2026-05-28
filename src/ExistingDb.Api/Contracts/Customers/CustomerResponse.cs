namespace ExistingDb.Api.Contracts.Customers;

public sealed record CustomerResponse(
    Guid Guid,
    double? Number,
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
    double? UseFlag,
    int? Security,
    string? Notes);

