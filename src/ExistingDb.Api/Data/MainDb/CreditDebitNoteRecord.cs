namespace ExistingDb.Api.Data.MainDb;

public sealed class CreditDebitNoteRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public DateTime? Date { get; set; }
    public int? NoteType { get; set; }
    public string? Statement { get; set; }
}
