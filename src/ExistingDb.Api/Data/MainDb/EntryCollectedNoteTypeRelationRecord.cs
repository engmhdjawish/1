namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryCollectedNoteTypeRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? NoteGuid { get; set; }
    public Guid? TypeGuid { get; set; }
}
