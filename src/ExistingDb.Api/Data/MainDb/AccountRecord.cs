namespace ExistingDb.Api.Data.MainDb;

public sealed class AccountRecord
{
    public Guid Guid { get; set; }
    public int? Number { get; set; }
    public string? Name { get; set; }
    public string? Code { get; set; }
    public Guid? CurrencyGuid { get; set; }
    public double? CurrencyVal { get; set; }
    public double? Debit { get; set; }
    public double? Credit { get; set; }
    public double? InitDebit { get; set; }
    public double? InitCredit { get; set; }
}
