namespace ExistingDb.Api.Contracts.Materials;

public sealed record MaterialAgeResponse(
    string AsOf,
    string AgeMode,
    string StoreScope,
    bool SkipEmpty,
    bool SkipZeroBalance,
    IReadOnlyList<Guid> StoreGuids,
    IReadOnlyList<Guid> BillTypeGuids,
    int Page,
    int PageSize,
    int TotalCount,
    IReadOnlyList<MaterialAgeRowResponse> Items);

public sealed record MaterialAgeRowResponse(
    Guid MaterialGuid,
    int? Number,
    string? Code,
    string? Name,
    string? LatinName,
    string? Company,
    Guid? GroupGuid,
    string? GroupCode,
    string? GroupName,
    string? MaterialType,
    string? Unit,
    double RemainingQuantity,
    DateOnly? OldestDate,
    int? OldestAgeDays,
    double? WeightedAgeDays,
    IReadOnlyList<MaterialAgeStoreBalanceResponse> StoreBalances,
    IReadOnlyList<MaterialAgeLayerResponse>? Layers,
    Guid? PictureGuid = null,
    double? EndUser = null,
    double? Half = null,
    double? Whole = null,
    double? LastPrice = null,
    double? AvgPrice = null,
    double? CurrencyVal = null);

public sealed record MaterialAgeStoreBalanceResponse(
    Guid StoreGuid,
    string? StoreCode,
    string? StoreName,
    double RemainingQuantity,
    DateOnly? OldestDate,
    int? OldestAgeDays,
    double? WeightedAgeDays,
    double? CurrentQuantity);

public sealed record MaterialAgeLayerResponse(
    Guid StoreGuid,
    Guid LineGuid,
    Guid BillGuid,
    int? BillNumber,
    DateOnly BillDate,
    int? LineNumber,
    Guid BillTypeGuid,
    string? BillTypeName,
    double InboundQuantity,
    double RemainingQuantity,
    int AgeDays);

public sealed record MaterialAgeBillTypeListResponse(
    IReadOnlyList<MaterialAgeBillTypeResponse> BillTypes);

public sealed record MaterialAgeBillTypeResponse(
    Guid BillTypeGuid,
    string? Name,
    string? LatinName,
    bool IsInput,
    bool MovesStock,
    int PostedBillCount);

public sealed record MaterialLastSaleBatchRequest(IReadOnlyList<Guid> MaterialGuids);

public sealed record MaterialLastSaleListResponse(IReadOnlyList<MaterialLastSaleRowResponse> Items);

public sealed record MaterialLastSaleRowResponse(Guid MaterialGuid, DateOnly LastSaleDate);
