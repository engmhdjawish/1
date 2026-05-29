namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryBillRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? BillGuid { get; set; }
}
