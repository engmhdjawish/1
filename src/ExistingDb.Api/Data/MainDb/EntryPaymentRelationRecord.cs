namespace ExistingDb.Api.Data.MainDb;

public sealed class EntryPaymentRelationRecord
{
    public Guid EntryGuid { get; set; }
    public Guid? PaymentGuid { get; set; }
}
