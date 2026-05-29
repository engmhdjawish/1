namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryNoteRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? NoteGuid { get; set; }
}
