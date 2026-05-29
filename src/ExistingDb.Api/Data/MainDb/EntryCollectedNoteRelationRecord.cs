namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryCollectedNoteRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? NoteGuid { get; set; }
}
