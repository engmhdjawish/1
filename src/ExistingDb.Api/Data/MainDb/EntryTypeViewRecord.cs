namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryTypeViewRecord
{
    public Guid Guid { get; set; }
    public string? Name { get; set; }
    public string? LatinName { get; set; }
    public string? Abbrev { get; set; }
    public string? LatinAbbrev { get; set; }
}
