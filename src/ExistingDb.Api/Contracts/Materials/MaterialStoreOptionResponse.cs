namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialStoreOptionResponse(
    Guid StoreGuid,
    int? StoreNumber,
    string? StoreCode,
    string? StoreName);
