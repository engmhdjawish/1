namespace ExistingDb.Api.Data.MainDb;

public sealed class StoreRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public string? Code { get; set; }
    public string? Name { get; set; }
    public string? LatinName { get; set; }
    public bool? IsActive { get; set; }
}

