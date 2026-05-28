namespace ExistingDb.Api.Data.MainDb;

public sealed class MaterialRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public string? Name { get; set; }
    public string? Code { get; set; }
    public string? LatinName { get; set; }
    public string? BarCode { get; set; }
    public string? BarCode2 { get; set; }
    public string? BarCode3 { get; set; }
    public string? Unity { get; set; }
    public string? Unit2 { get; set; }
    public double? Unit2Fact { get; set; }
    public bool? Unit2FactFlag { get; set; }
    public double? Qty { get; set; }
    public double? Whole { get; set; }
    public double? Half { get; set; }
    public double? EndUser { get; set; }
    public double? AvgPrice { get; set; }
    public double? LastPrice { get; set; }
    public double? CurrencyVal { get; set; }
    public string? Origin { get; set; }
    public string? Company { get; set; }
    public string? Dim { get; set; }
    public string? Color { get; set; }
    public string? Provenance { get; set; }
    public Guid? GroupGuid { get; set; }
    public Guid? PictureGuid { get; set; }
    public Guid? CurrencyGuid { get; set; }
    public int? Type { get; set; }
    public int? Security { get; set; }
    public double? UseFlag { get; set; }
    public bool? IsHidden { get; set; }
}

