namespace ExistingDb.Api.Data.MainDb;

public sealed class NoteTypeRecord
{
    public Guid Guid { get; set; }
    public int? NoteGroup { get; set; }
    public int? NoteType { get; set; }
    public string? Name { get; set; }
    public string? LatinName { get; set; }
}
