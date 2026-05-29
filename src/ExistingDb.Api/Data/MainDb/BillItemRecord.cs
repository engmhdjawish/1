namespace ExistingDb.Api.Data.MainDb;

public sealed class BillItemRecord
{
    public Guid Guid { get; set; }
    public Guid? ParentGuid { get; set; }
    public Guid? MaterialGuid { get; set; }
}
