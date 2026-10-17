namespace ExistingDb.Api.Options;

/// <summary>
/// Fixed Al-Ameen identities for فاتورة حجز. Store and bill type are never taken from the client.
/// </summary>
public sealed class ReservationBillOptions
{
    public const string SectionName = "ReservationBills";

    /// <summary>فاتورة حجز.</summary>
    public Guid BillTypeGuid { get; init; } = Guid.Parse("09A285A8-21AD-480E-914F-C53E0FEE5251");

    /// <summary>مستودع حجوزات.</summary>
    public Guid StoreGuid { get; init; } = Guid.Parse("2543E24D-3213-46D6-8B9C-4A2F641A65E9");

    /// <summary>Currency used by existing reservation bills.</summary>
    public Guid CurrencyGuid { get; init; } = Guid.Parse("98B47880-98D0-4B96-80B3-36C52BC8C709");

    public double CurrencyVal { get; init; } = 1d;

    public void EnsureConfigured()
    {
        if (BillTypeGuid == Guid.Empty || StoreGuid == Guid.Empty || CurrencyGuid == Guid.Empty)
        {
            throw new InvalidOperationException("ReservationBills bill type, store, and currency GUIDs must be configured.");
        }

        if (!double.IsFinite(CurrencyVal) || CurrencyVal <= 0d)
        {
            throw new InvalidOperationException("ReservationBills:CurrencyVal must be a positive finite number.");
        }
    }
}
