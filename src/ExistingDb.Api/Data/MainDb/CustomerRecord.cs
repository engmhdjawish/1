namespace ExistingDb.Api.Data.MainDb;

public sealed class CustomerRecord
{
    public Guid Guid { get; set; }
    public double? Number { get; set; }
    public string? CustomerName { get; set; }
    public string? LatinName { get; set; }
    public string? Phone1 { get; set; }
    public string? Phone2 { get; set; }
    public string? Mobile { get; set; }
    public string? Email { get; set; }
    public Guid? AccountGuid { get; set; }
    public string? BarCode { get; set; }
    public int? Type { get; set; }
    public int? State { get; set; }
    public double? UseFlag { get; set; }
    public int? Security { get; set; }
    public string? Notes { get; set; }
}

