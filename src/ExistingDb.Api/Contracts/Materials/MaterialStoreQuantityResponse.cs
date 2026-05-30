namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialStoreQuantityResponse(
    Guid StoreGuid,
    string? StoreName,
    double Quantity);
