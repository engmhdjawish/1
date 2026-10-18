using System.Globalization;
using System.Text.RegularExpressions;

namespace ExistingDb.Api.Services.Bills;

/// <summary>
/// Ameen shows أزواج in Qty, طرود in Qty2, and السعر الإفرادي in Price.
/// Unity stays 1 so the line is the primary unit (زوج) and the grid does not
/// divide the pair count back into cartons. The line amount is Qty * Price.
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

        // Unit2Fact is التعبئة. A stored 1 on the order is not a real carton size.
        // A missing or 1 factor on the material keeps the pairs the order already sent.
        var packing = double.IsFinite(materialPacking) && materialPacking > 1d
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
