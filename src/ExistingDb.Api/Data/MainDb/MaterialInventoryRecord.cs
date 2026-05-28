namespace ExistingDb.Api.Data.MainDb;

public sealed class MaterialInventoryRecord
{
    public Guid? MaterialGuid { get; set; }
    public Guid? StoreGuid { get; set; }
    public double? Qty { get; set; }
}

