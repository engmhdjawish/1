namespace ExistingDb.Api.Services.Materials;

public static partial class MaterialAgeCalculator
{
    /// <summary>
    /// Fixed-line cases from the material-age plan. Throws if any case fails.
    /// </summary>
    public static void RunSelfChecks()
    {
        var asOf = new DateOnly(2026, 6, 6);
        var mat = Guid.Parse("11111111-1111-1111-1111-111111111111");
        var storeA = Guid.Parse("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa");
        var storeB = Guid.Parse("bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb");
        var typeIn = Guid.Parse("cccccccc-cccc-cccc-cccc-cccccccccccc");
        var typeOut = Guid.Parse("dddddddd-dddd-dddd-dddd-dddddddddddd");

        MovementLine In(Guid store, DateOnly date, int billNumber, int lineNumber, double qty, double bonus = 0) =>
            new(
                Guid.NewGuid(), lineNumber, mat, store, qty, bonus, Guid.NewGuid(), billNumber, date,
                typeIn, "in", null, +1);

        MovementLine Out(Guid store, DateOnly date, int billNumber, int lineNumber, double qty) =>
            new(
                Guid.NewGuid(), lineNumber, mat, store, qty, 0, Guid.NewGuid(), billNumber, date,
                typeOut, "out", null, -1);

        // Inbound 10 day1, inbound 5 day10, outbound 8 → oldest remaining 2, second 5
        {
            var day1 = new DateOnly(2026, 1, 1);
            var day10 = new DateOnly(2026, 1, 10);
            var result = ComputeStore(mat, storeA, [
                In(storeA, day1, 1, 1, 10),
                In(storeA, day10, 2, 1, 5),
                Out(storeA, day10, 3, 1, 8)
            ], asOf);
            Expect(result.Layers.Count == 2, "case1 layer count");
            Expect(Near(result.Layers[0].RemainingQuantity, 2), "case1 oldest remaining");
            Expect(Near(result.Layers[1].RemainingQuantity, 5), "case1 second remaining");
        }

        // Outbound larger than every inbound → no layers, negative net, ages null path
        {
            var result = ComputeStore(mat, storeA, [
                In(storeA, new DateOnly(2026, 1, 1), 1, 1, 5),
                Out(storeA, new DateOnly(2026, 2, 1), 2, 1, 9)
            ], asOf);
            Expect(result.Layers.Count == 0, "case2 no layers");
            Expect(result.NetQuantity < -QuantityEpsilon, "case2 negative net");
        }

        // Sale then return → return is new layer; sale stays consumed
        {
            var saleDate = new DateOnly(2026, 2, 1);
            var returnDate = new DateOnly(2026, 3, 1);
            var result = ComputeStore(mat, storeA, [
                In(storeA, new DateOnly(2026, 1, 1), 1, 1, 10),
                Out(storeA, saleDate, 2, 1, 10),
                In(storeA, returnDate, 3, 1, 4)
            ], asOf);
            Expect(result.Layers.Count == 1, "case3 one return layer");
            Expect(result.Layers[0].BillDate == returnDate, "case3 return date");
            Expect(Near(result.Layers[0].RemainingQuantity, 4), "case3 return qty");
        }

        // Transfer out A / in B
        {
            var transferDate = new DateOnly(2026, 4, 1);
            var results = Compute([
                In(storeA, new DateOnly(2026, 1, 1), 1, 1, 10),
                Out(storeA, transferDate, 2, 1, 10),
                In(storeB, transferDate, 3, 1, 10)
            ], asOf);
            var a = results.Single(r => r.StoreGuid == storeA);
            var b = results.Single(r => r.StoreGuid == storeB);
            Expect(a.Layers.Count == 0, "case4 A consumed");
            Expect(b.Layers.Count == 1 && b.Layers[0].BillDate == transferDate, "case4 B new layer");
        }

        // bIsInput = 0 BillType = 0 style → Direction -1 outbound
        {
            var line = new MovementLine(
                Guid.NewGuid(), 1, mat, storeA, 3, 0, Guid.NewGuid(), 1, new DateOnly(2026, 1, 1),
                typeOut, "transfer-out", null, -1);
            Expect(SignedQuantity(line) < 0, "case5 outbound sign");
        }

        // Qty=0 BonusQnt=4 inbound → layer 4
        {
            var result = ComputeStore(mat, storeA, [
                In(storeA, new DateOnly(2026, 1, 1), 1, 1, 0, 4)
            ], asOf);
            Expect(result.Layers.Count == 1 && Near(result.Layers[0].InboundQuantity, 4), "case6 bonus inbound");
        }

        // Same date/bill number → lower line number consumed first
        {
            var date = new DateOnly(2026, 1, 1);
            var bill = 10;
            var result = ComputeStore(mat, storeA, [
                In(storeA, date, bill, 2, 5),
                In(storeA, date, bill, 1, 5),
                Out(storeA, date, bill + 1, 1, 5)
            ], asOf);
            Expect(result.Layers.Count == 1, "case7 one layer left");
            Expect(result.Layers[0].LineNumber == 2, "case7 higher line survives");
        }

        // Opening line keeps its date and qty
        {
            var openDate = new DateOnly(2026, 1, 1);
            var result = ComputeStore(mat, storeA, [
                In(storeA, openDate, 1, 1, 48)
            ], asOf);
            Expect(result.Layers[0].BillDate == openDate, "case8 opening date");
            Expect(Near(result.Layers[0].RemainingQuantity, 48), "case8 opening qty");
        }
    }

    private static bool Near(double actual, double expected) =>
        Math.Abs(actual - expected) <= QuantityEpsilon;

    private static void Expect(bool condition, string name)
    {
        if (!condition)
        {
            throw new InvalidOperationException("MaterialAgeCalculator self-check failed: " + name);
        }
    }
}
