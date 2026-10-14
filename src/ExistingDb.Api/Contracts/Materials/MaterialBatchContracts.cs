namespace ExistingDb.Api.Contracts.Materials;

public sealed class MaterialBatchQuery : MaterialListQuery
{
    public string Key { get; set; } = "";
}

public sealed class MaterialBatchRequest
{
    public List<MaterialBatchQuery> Queries { get; set; } = [];
}

public sealed record MaterialBatchResponse(
    IReadOnlyDictionary<string, MaterialPagedResponse> Results);

public sealed record MaterialLookupResponse(MaterialResponse? Item);

public sealed record MaterialSuggestResponse(IReadOnlyList<MaterialResponse> Items);
