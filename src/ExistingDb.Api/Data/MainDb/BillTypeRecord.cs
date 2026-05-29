namespace ExistingDb.Api.Data.MainDb;

public sealed class BillTypeRecord
{
    public Guid Guid { get; set; }
    public int? Type { get; set; }
    public int? BillGroup { get; set; }
    public int? BillType { get; set; }
    public string? Name { get; set; }
    public string? LatinName { get; set; }
}
