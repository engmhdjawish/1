namespace ExistingDb.Api.Data.MainDb;

public sealed class MaterialGroupRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public string? Code { get; set; }
    public string? Name { get; set; }
    public string? LatinName { get; set; }
    public Guid? ParentGuid { get; set; }
}

