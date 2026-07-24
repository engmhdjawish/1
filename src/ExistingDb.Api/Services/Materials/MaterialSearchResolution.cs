namespace ExistingDb.Api.Services.Materials;

public sealed record MaterialSearchResolution(
    IReadOnlyList<string> Keywords,
    string? ExactMaterialCode)
{
    public static MaterialSearchResolution Empty { get; } = new([], null);

    public bool IsEmpty => Keywords.Count == 0 && ExactMaterialCode is null;
}
