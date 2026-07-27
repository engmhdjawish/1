namespace ExistingDb.Api.Data.MainDb;

/// <summary>
/// Amine per-store material balance table <c>ms000</c>.
/// This is the live warehouse quantity source (not the bill-aggregate view).
/// </summary>
public sealed class MaterialInventoryRecord
{
    public Guid Guid { get; set; }
    public Guid? MaterialGuid { get; set; }
    public Guid? StoreGuid { get; set; }
    public double? Qty { get; set; }
    public double? Book { get; set; }
}
