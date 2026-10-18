namespace ExistingDb.Api.Contracts.Bills;

public sealed class ReservationBillWriteRequest
{
    public string? OwnerName { get; set; }

    public List<ReservationBillLineRequest>? Lines { get; set; }
}

public sealed class ReservationBillLineRequest
{
    public Guid MaterialGuid { get; set; }

    public double Quantity { get; set; }

    public double PackageQuantity { get; set; }

    public double UnitPrice { get; set; }

    public string? Notes { get; set; }
}

public sealed record ReservationBillResponse(
    Guid BillGuid,
    int BillNumber,
    IReadOnlyList<ReservationStoreQuantityResponse> StoreQuantities);

public sealed record ReservationStoreQuantityResponse(
    Guid MaterialGuid,
    double ReservationStoreQuantity);
