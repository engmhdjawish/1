namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryPaymentTypeRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? PaymentGuid { get; set; }
    public Guid? TypeGuid { get; set; }
}
