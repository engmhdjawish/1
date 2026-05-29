namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public DateTime? Date { get; set; }
    public double? Debit { get; set; }
    public double? Credit { get; set; }
    public double? CurrencyVal { get; set; }
    public string? Notes { get; set; }
    public Guid? ParentGuid { get; set; }
    public Guid? AccountGuid { get; set; }
    public Guid? ContraAccountGuid { get; set; }
    public Guid? CustomerGuid { get; set; }
}
