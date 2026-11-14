namespace ExistingDb.Api.Services.Materials;

/// <summary>
/// Pure FIFO remaining-age calculator. No database access.
/// Group key is (MaterialGuid, StoreGuid). Outbound never crosses stores.
/// </summary>
public static partial class MaterialAgeCalculator
{
    public const double QuantityEpsilon = 1e-6;

    public sealed record MovementLine(
        Guid LineGuid,
        int? LineNumber,
        Guid MaterialGuid,
        Guid StoreGuid,
        double Qty,
        double BonusQty,
        Guid BillGuid,
        int? BillNumber,
        DateOnly BillDate,
        Guid BillTypeGuid,
        string? BillTypeName,
        string? BillTypeLatinName,
        int Direction);

    public sealed record SurvivingLayer(
        Guid MaterialGuid,
        Guid StoreGuid,
        Guid LineGuid,
        Guid BillGuid,
        int? BillNumber,
        DateOnly BillDate,
        int? LineNumber,
        Guid BillTypeGuid,
        string? BillTypeName,
        double InboundQuantity,
        double RemainingQuantity,
        int AgeDays);

    public sealed record StoreFifoResult(
        Guid MaterialGuid,
        Guid StoreGuid,
        double NetQuantity,
        IReadOnlyList<SurvivingLayer> Layers,
        double UncoveredOutbound);

    public static bool IsZero(double value) => Math.Abs(value) <= QuantityEpsilon;

    public static double SignedQuantity(MovementLine line)
    {
        var direction = line.Direction >= 0 ? 1 : -1;
        return direction * (line.Qty + line.BonusQty);
    }

    /// <summary>
    /// Run FIFO per (material, store). Lines must already be filtered to posted, in-scope types/stores/asOf.
    /// </summary>
    public static IReadOnlyList<StoreFifoResult> Compute(
        IEnumerable<MovementLine> lines,
        DateOnly asOf)
    {
        var groups = lines
            .GroupBy(line => (line.MaterialGuid, line.StoreGuid))
            .OrderBy(group => group.Key.MaterialGuid)
            .ThenBy(group => group.Key.StoreGuid);

        var results = new List<StoreFifoResult>();
        foreach (var group in groups)
        {
            results.Add(ComputeStore(group.Key.MaterialGuid, group.Key.StoreGuid, group, asOf));
        }

        return results;
    }

    public static StoreFifoResult ComputeStore(
        Guid materialGuid,
        Guid storeGuid,
        IEnumerable<MovementLine> lines,
        DateOnly asOf)
    {
        var ordered = lines
            .OrderBy(line => line.BillDate)
            .ThenBy(line => line.BillNumber ?? int.MaxValue)
            .ThenBy(line => line.LineNumber ?? int.MaxValue)
            .ThenBy(line => line.LineGuid)
            .ToList();

        var inbound = new List<(MovementLine Line, double Original)>();
        var outboundTotal = 0d;

        foreach (var line in ordered)
        {
            var signed = SignedQuantity(line);
            if (IsZero(signed))
            {
                continue;
            }

            if (signed > QuantityEpsilon)
            {
                inbound.Add((line, signed));
            }
            else
            {
                outboundTotal += -signed;
            }
        }

        var surviving = new List<SurvivingLayer>();
        foreach (var (line, original) in inbound)
        {
            double remaining;
            if (outboundTotal <= QuantityEpsilon)
            {
                remaining = original;
            }
            else if (outboundTotal >= original - QuantityEpsilon)
            {
                remaining = 0;
                outboundTotal -= original;
                if (outboundTotal < 0 && IsZero(outboundTotal))
                {
                    outboundTotal = 0;
                }
            }
            else
            {
                remaining = original - outboundTotal;
                outboundTotal = 0;
            }

            if (remaining <= QuantityEpsilon)
            {
                continue;
            }

            var ageDays = asOf.DayNumber - line.BillDate.DayNumber;
            surviving.Add(new SurvivingLayer(
                materialGuid,
                storeGuid,
                line.LineGuid,
                line.BillGuid,
                line.BillNumber,
                line.BillDate,
                line.LineNumber,
                line.BillTypeGuid,
                line.BillTypeName,
                original,
                remaining,
                ageDays));
        }

        if (outboundTotal < 0 && IsZero(outboundTotal))
        {
            outboundTotal = 0;
        }

        var layerSum = surviving.Sum(layer => layer.RemainingQuantity);
        var net = layerSum - outboundTotal;

        return new StoreFifoResult(materialGuid, storeGuid, net, surviving, outboundTotal);
    }

    public static DateOnly? OldestDate(IEnumerable<SurvivingLayer> layers)
    {
        DateOnly? oldest = null;
        foreach (var layer in layers)
        {
            if (oldest is null || layer.BillDate < oldest)
            {
                oldest = layer.BillDate;
            }
        }

        return oldest;
    }

    public static int? OldestAgeDays(IEnumerable<SurvivingLayer> layers, DateOnly asOf)
    {
        var oldest = OldestDate(layers);
        return oldest is null ? null : asOf.DayNumber - oldest.Value.DayNumber;
    }

    public static double? WeightedAgeDays(IEnumerable<SurvivingLayer> layers)
    {
        decimal weightSum = 0m;
        decimal weighted = 0m;
        foreach (var layer in layers)
        {
            var remaining = (decimal)layer.RemainingQuantity;
            if (remaining <= (decimal)QuantityEpsilon)
            {
                continue;
            }

            weightSum += remaining;
            weighted += remaining * layer.AgeDays;
        }

        if (weightSum <= (decimal)QuantityEpsilon)
        {
            return null;
        }

        return (double)(weighted / weightSum);
    }
}
