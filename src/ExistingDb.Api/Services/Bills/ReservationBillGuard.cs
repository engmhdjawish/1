using ExistingDb.Api.Contracts.Bills;

namespace ExistingDb.Api.Services.Bills;

public enum ReservationBillFailure
{
    None = 0,
    Validation = 1,
    NotFound = 2,
    NotReservation = 3,
    HasLedgerRows = 4,
    AssertFailed = 5,
    Database = 6
}

public sealed record ReservationBillDecision(bool Succeeded, ReservationBillFailure Failure, string? Message)
{
    public static ReservationBillDecision Success { get; } = new(true, ReservationBillFailure.None, null);

    public static ReservationBillDecision Fail(ReservationBillFailure failure, string message) =>
        new(false, failure, message);
}

public sealed record ReservationBillLine(
    Guid MaterialGuid,
    double Quantity,
    double UnitPrice,
    double PackageQuantity = 0d,
    string? Notes = null);

public sealed record ReservationBillOwnership(
    bool Exists,
    Guid? TypeGuid,
    Guid? StoreGuid,
    bool IsPosted,
    int? Number,
    int EntryCount,
    int PaymentCount,
    int NullMaterialLineCount,
    IReadOnlyList<ReservationBillLine> Lines);

/// <summary>
/// One material/store balance movement measured inside the open transaction.
/// </summary>
public sealed record MaterialStoreQuantityChange(
    Guid MaterialGuid,
    Guid? StoreGuid,
    double QuantityBefore,
    double QuantityAfter,
    bool ExistsAfter)
{
    public double Delta => QuantityAfter - QuantityBefore;
}

public sealed record ReservationBillState(
    bool Exists,
    Guid? TypeGuid,
    Guid? StoreGuid,
    bool IsPosted,
    int? Number,
    int EntryCount,
    int PaymentCount);

/// <summary>
/// Safety decisions for reservation invoices. These run before commit and do not need a live SQL Server.
/// </summary>
public static class ReservationBillGuard
{
    public const double QuantityTolerance = 0.0001d;
    public const int MaxOwnerNameLength = 1000;
    public const int MaxLineNoteLength = 250;
    public const string WebsiteSyncMark = "مزامنة من الموقع";
    public const int MaxLines = 100;

    public static ReservationBillDecision ValidateWrite(
        string? ownerName,
        IReadOnlyList<ReservationBillLineRequest>? lines,
        out ReservationBillDraft? draft)
    {
        draft = null;
        var name = ownerName?.Trim() ?? string.Empty;
        if (name.Length == 0)
        {
            return ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Owner name is required.");
        }

        if (!name.Contains(WebsiteSyncMark, StringComparison.Ordinal))
        {
            name = string.IsNullOrEmpty(name) ? WebsiteSyncMark : name + " — " + WebsiteSyncMark;
        }

        if (name.Length > MaxOwnerNameLength)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                $"Owner name must be at most {MaxOwnerNameLength} characters.");
        }

        if (lines is null || lines.Count == 0)
        {
            return ReservationBillDecision.Fail(ReservationBillFailure.Validation, "At least one reservation line is required.");
        }

        if (lines.Count > MaxLines)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                $"A reservation bill can contain at most {MaxLines} lines.");
        }

        var normalized = new List<ReservationBillLine>(lines.Count);
        var seen = new HashSet<Guid>();
        foreach (var line in lines)
        {
            if (line.MaterialGuid == Guid.Empty)
            {
                return ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Each line needs a material.");
            }

            if (!seen.Add(line.MaterialGuid))
            {
                return ReservationBillDecision.Fail(
                    ReservationBillFailure.Validation,
                    "Each material can appear on only one reservation line.");
            }

            if (!double.IsFinite(line.Quantity) || line.Quantity <= 0d)
            {
                return ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Line quantity must be a positive finite number.");
            }

            if (!double.IsFinite(line.UnitPrice) || line.UnitPrice < 0d)
            {
                return ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Line unit price must be zero or a positive finite number.");
            }

            var packages = line.PackageQuantity;
            if (!double.IsFinite(packages) || packages < 0d)
            {
                return ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Package quantity must be zero or a positive finite number.");
            }

            if (packages == 0d)
            {
                packages = line.Quantity;
            }

            var note = string.IsNullOrWhiteSpace(line.Notes) ? null : line.Notes.Trim();
            if (note is not null && note.Length > MaxLineNoteLength)
            {
                return ReservationBillDecision.Fail(
                    ReservationBillFailure.Validation,
                    $"Line note must be at most {MaxLineNoteLength} characters.");
            }

            normalized.Add(new ReservationBillLine(line.MaterialGuid, line.Quantity, line.UnitPrice, packages, note));
        }

        draft = new ReservationBillDraft(name, normalized, SumAmount(normalized));
        return ReservationBillDecision.Success;
    }

    public static ReservationBillDecision EvaluatePatternForSync(ReservationBillPattern pattern)
    {
        if (!pattern.NoEntryColumnFound)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                "bt000.bNoEntry was not found, so the order was not synced.");
        }

        if (pattern.NoEntry != true)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.NotReservation,
                "The selected bill pattern generates accounting entries (bNoEntry = 0), so the order was not synced.");
        }

        if (!pattern.FixStoreColumnFound || pattern.FixStore != true
            || !pattern.StoreColumnFound || pattern.StoreGuid is null || pattern.StoreGuid == Guid.Empty)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                "The selected bill pattern does not lock the warehouse to DefStoreGUID.");
        }

        if (!pattern.BillCurrencyColumnFound || pattern.BillCurrencyGuid is null || pattern.BillCurrencyGuid == Guid.Empty)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                "The selected bill pattern has no DefCurrencyGUID.");
        }

        return ReservationBillDecision.Success;
    }

    public static ReservationBillDecision EvaluateOwnership(
        ReservationBillOwnership bill,
        Guid billTypeGuid,
        Guid storeGuid)
    {
        if (!bill.Exists)
        {
            return ReservationBillDecision.Fail(ReservationBillFailure.NotFound, "Reservation bill was not found.");
        }

        if (bill.TypeGuid != billTypeGuid || bill.StoreGuid != storeGuid)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.NotReservation,
                "Only a posted bill of the selected type in that pattern's store can be changed.");
        }

        if (bill.EntryCount > 0 || bill.PaymentCount > 0)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.HasLedgerRows,
                "Reservation bill has accounting entries or payment rows and was left unchanged.");
        }

        if (!bill.IsPosted)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.NotReservation,
                "Reservation bill is not posted and was left unchanged.");
        }

        if (bill.Number is null)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.NotReservation,
                "Reservation bill has no number and was left unchanged.");
        }

        if (bill.NullMaterialLineCount > 0
            || bill.Lines.Count == 0
            || bill.Lines.Any(line => line.MaterialGuid == Guid.Empty || line.Quantity <= 0d))
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.NotReservation,
                "Reservation bill lines are missing a material or quantity and were left unchanged.");
        }

        return ReservationBillDecision.Success;
    }

    public static ReservationBillDecision EvaluatePostedBill(ReservationBillState state, Guid billTypeGuid, Guid storeGuid)
    {
        if (!state.Exists || !state.IsPosted)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill was not left posted.");
        }

        if (state.TypeGuid != billTypeGuid || state.StoreGuid != storeGuid)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill type or store changed.");
        }

        if (state.EntryCount != 0)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill created accounting entries.");
        }

        if (state.PaymentCount != 0)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill created payment rows.");
        }

        if (state.Number is null)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill number was not assigned.");
        }

        return ReservationBillDecision.Success;
    }

    public static ReservationBillDecision EvaluateDeletedBill(ReservationBillState state)
    {
        if (state.Exists)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill was not deleted.");
        }

        if (state.EntryCount != 0 || state.PaymentCount != 0)
        {
            return ReservationBillDecision.Fail(
                ReservationBillFailure.AssertFailed,
                "Reservation bill delete left accounting entries or payment rows.");
        }

        return ReservationBillDecision.Success;
    }

    /// <summary>
    /// The reservation store must move by the expected signed quantity for every scoped material,
    /// and no other store may move. A non-zero reservation change must leave an <c>ms000</c> row.
    /// </summary>
    public static ReservationBillDecision EvaluateInventory(
        Guid reservationStoreGuid,
        IReadOnlyDictionary<Guid, double> expectedReservationDeltaByMaterial,
        IReadOnlyList<MaterialStoreQuantityChange> changes)
    {
        foreach (var (materialGuid, expectedDelta) in expectedReservationDeltaByMaterial)
        {
            var materialChanges = changes.Where(change => change.MaterialGuid == materialGuid).ToArray();
            var reservationChanges = materialChanges
                .Where(change => change.StoreGuid == reservationStoreGuid)
                .ToArray();
            var actualDelta = reservationChanges.Sum(change => change.Delta);
            if (Math.Abs(actualDelta - expectedDelta) > QuantityTolerance)
            {
                return ReservationBillDecision.Fail(
                    ReservationBillFailure.AssertFailed,
                    "Reservation store quantity did not change by the reserved quantity.");
            }

            var existsAfter = reservationChanges.Any(change => change.ExistsAfter);
            if (Math.Abs(expectedDelta) > QuantityTolerance && !existsAfter)
            {
                return ReservationBillDecision.Fail(
                    ReservationBillFailure.AssertFailed,
                    "Reservation store balance row is missing after the quantity change.");
            }

            if (materialChanges.Any(change =>
                    change.StoreGuid != reservationStoreGuid
                    && Math.Abs(change.Delta) > QuantityTolerance))
            {
                return ReservationBillDecision.Fail(
                    ReservationBillFailure.AssertFailed,
                    "A non-reservation store quantity changed.");
            }
        }

        foreach (var change in changes)
        {
            if (!expectedReservationDeltaByMaterial.ContainsKey(change.MaterialGuid)
                && Math.Abs(change.Delta) > QuantityTolerance)
            {
                return ReservationBillDecision.Fail(
                    ReservationBillFailure.AssertFailed,
                    "An unexpected material quantity changed.");
            }
        }

        return ReservationBillDecision.Success;
    }

    public static IReadOnlyDictionary<Guid, double> ReserveDeltas(IReadOnlyList<ReservationBillLine> lines) =>
        SignedDeltas(lines, -1d);

    public static IReadOnlyDictionary<Guid, double> RestoreDeltas(IReadOnlyList<ReservationBillLine> lines) =>
        SignedDeltas(lines, 1d);

    /// <summary>
    /// One update movement: restore the previous reservation (+old) and reserve the replacement (-new).
    /// Net is old quantity minus new quantity for each material.
    /// </summary>
    public static IReadOnlyDictionary<Guid, double> NetDeltas(
        IReadOnlyList<ReservationBillLine> previousLines,
        IReadOnlyList<ReservationBillLine> nextLines)
    {
        var net = new Dictionary<Guid, double>(RestoreDeltas(previousLines));
        foreach (var (materialGuid, delta) in ReserveDeltas(nextLines))
        {
            net.TryGetValue(materialGuid, out var current);
            net[materialGuid] = current + delta;
        }

        return net;
    }

    public static IReadOnlyDictionary<Guid, double> WithZeroFill(
        IReadOnlyDictionary<Guid, double> deltas,
        IEnumerable<Guid> materialGuids)
    {
        var filled = new Dictionary<Guid, double>(deltas);
        foreach (var materialGuid in materialGuids)
        {
            filled.TryAdd(materialGuid, 0d);
        }

        return filled;
    }

    public static double SumAmount(IReadOnlyList<ReservationBillLine> lines)
    {
        double total = 0d;
        foreach (var line in lines)
        {
            total += line.Quantity * line.UnitPrice;
        }

        return total;
    }

    private static Dictionary<Guid, double> SignedDeltas(IReadOnlyList<ReservationBillLine> lines, double sign)
    {
        var deltas = new Dictionary<Guid, double>();
        foreach (var line in lines)
        {
            deltas.TryGetValue(line.MaterialGuid, out var current);
            deltas[line.MaterialGuid] = current + (sign * line.Quantity);
        }

        return deltas;
    }
}

public sealed record ReservationBillDraft(
    string OwnerName,
    IReadOnlyList<ReservationBillLine> Lines,
    double Total);
