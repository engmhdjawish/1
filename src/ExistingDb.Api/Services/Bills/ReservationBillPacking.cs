using System.Globalization;
using System.Text.RegularExpressions;

namespace ExistingDb.Api.Services.Bills;

/// <summary>
/// Ameen grid: طرود = Qty2, أزواج = Qty. Unity stays 1.
/// A manual line that totaled as Qty * Price used Unity = 1, and stock (ms000)
/// moves by Qty. Sending Unity = التعبئة made أزواج show Qty / Unity, which is
/// the carton count, so both columns showed 1.
/// Pairs = cartons × تعبئة. تعبئة is the order packing (quantity / cartons)
/// when that factor is above 1. When the order packing is missing or 1 and
/// mt000.Unit2Fact is above 1, Unit2Fact is the carton size. The two factors
/// are never multiplied together. Pair price is the carton price / تعبئة,
/// so the line amount stays carton price × cartons.
/// </summary>
public static partial class ReservationBillPacking
{
    public static ReservationBillLine Apply(ReservationBillLine line, double materialPacking)
    {
        var cartons = line.PackageQuantity > 0d ? line.PackageQuantity : line.Quantity;
        var sentPacking = cartons > 0d ? line.Quantity / cartons : 1d;
        if (!double.IsFinite(sentPacking) || sentPacking <= 0d)
        {
            sentPacking = 1d;
        }

        // A stored order packing above 1 is pcs_per_box. Keep it, including when
        // it differs from Unit2Fact. Only a missing or 1 factor is replaced.
        var orderPackingMissing = sentPacking <= 1d + ReservationBillGuard.QuantityTolerance;
        var packing = orderPackingMissing
            && double.IsFinite(materialPacking)
            && materialPacking > 1d
            ? materialPacking
            : sentPacking;
        var pairs = cartons * packing;
        var cartonPrice = line.UnitPrice * sentPacking;
        var pairPrice = packing > 0d ? cartonPrice / packing : line.UnitPrice;
        var ratio = sentPacking > 0d ? packing / sentPacking : 1d;
        return line with
        {
            Quantity = pairs,
            UnitPrice = pairPrice,
            PackageQuantity = cartons,
            Notes = RescalePairPrices(line.Notes, ratio)
        };
    }

    private static string? RescalePairPrices(string? notes, double ratio)
    {
        if (string.IsNullOrEmpty(notes) || !double.IsFinite(ratio) || ratio <= 0d
            || Math.Abs(ratio - 1d) <= ReservationBillGuard.QuantityTolerance)
        {
            return notes;
        }

        return PairPrice().Replace(notes, match =>
        {
            if (!double.TryParse(match.Value, NumberStyles.Float, CultureInfo.InvariantCulture, out var value))
            {
                return match.Value;
            }

            return (value / ratio).ToString("0.##", CultureInfo.InvariantCulture);
        });
    }

    [GeneratedRegex(@"\d+(?:\.\d+)?", RegexOptions.CultureInvariant)]
    private static partial Regex PairPrice();
}
