namespace ExistingDb.Api.Data.MainDb;

public sealed class PaymentRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public DateTime? Date { get; set; }
    public Guid? TypeGuid { get; set; }
    public string? Notes { get; set; }
}
