namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? ParentGuid { get; set; }
    public int? ParentType { get; set; }
    public int? ParentNumber { get; set; }
}
