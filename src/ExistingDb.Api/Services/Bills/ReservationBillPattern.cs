namespace ExistingDb.Api.Services.Bills;

/// <summary>
/// Values read from the selected Ameen bill type at execution time.
/// bt000 in this repo only maps GUID, Type, BillGroup, BillType, Name, and LatinName.
/// Store, currencies, the generate-entries flag, and the store-locked flag are not mapped,
/// so they are taken from the first existing candidate column and left unresolved when absent.
/// Candidate columns, in order:
/// store: StoreGUID, DefStoreGUID, DefaultStoreGUID, FixedStoreGUID, StorePtr
/// bill currency: CurrencyGUID, BillCurrencyGUID, DefCurrencyGUID, CurGUID
/// price currency: MatCurrencyGUID, ItemCurrencyGUID, PriceCurrencyGUID, MatCurGUID, PriceCurGUID
/// generate entries: bGenEntry, GenEntry, IsGenEntry, GenerateEntry, bGenerateEntry, GenEntries
/// store locked: bLockStore, LockStore, StoreLocked, bFixedStore, IsStoreFixed, FixedStore
/// bill currency value: CurrencyVal, CurVal, BillCurrencyVal
/// price currency value: MatCurrencyVal, PriceCurrencyVal, ItemCurrencyVal
/// </summary>
public sealed record ReservationBillPattern(
    Guid BillTypeGuid,
    string? Name,
    Guid? StoreGuid,
    string? StoreName,
    bool StoreColumnFound,
    string? StoreColumn,
    bool? StoreLocked,
    bool StoreLockedColumnFound,
    string? StoreLockedColumn,
    Guid? BillCurrencyGuid,
    string? BillCurrencyName,
    bool BillCurrencyColumnFound,
    string? BillCurrencyColumn,
    Guid? PriceCurrencyGuid,
    string? PriceCurrencyName,
    bool PriceCurrencyColumnFound,
    string? PriceCurrencyColumn,
    double CurrencyVal,
    bool CurrencyValColumnFound,
    string? CurrencyValColumn,
    double PriceCurrencyVal,
    bool PriceCurrencyValColumnFound,
    string? PriceCurrencyValColumn,
    bool? GeneratesEntries,
    bool GeneratesEntriesColumnFound,
    string? GeneratesEntriesColumn,
    IReadOnlyList<string> Unresolved)
{
    public bool SyncAllowed =>
        GeneratesEntries != true
        && StoreGuid is { } store && store != Guid.Empty
        && BillCurrencyGuid is { } billCurrency && billCurrency != Guid.Empty
        && PriceCurrencyGuid is { } priceCurrency && priceCurrency != Guid.Empty;
}

public static class ReservationBillPatternInterpreter
{
    public static readonly string[] StoreGuidColumns =
        ["StoreGUID", "DefStoreGUID", "DefaultStoreGUID", "FixedStoreGUID", "StorePtr"];

    public static readonly string[] BillCurrencyColumns =
        ["CurrencyGUID", "BillCurrencyGUID", "DefCurrencyGUID", "CurGUID"];

    public static readonly string[] PriceCurrencyColumns =
        ["MatCurrencyGUID", "ItemCurrencyGUID", "PriceCurrencyGUID", "MatCurGUID", "PriceCurGUID"];

    public static readonly string[] GeneratesEntriesColumns =
        ["bGenEntry", "GenEntry", "IsGenEntry", "GenerateEntry", "bGenerateEntry", "GenEntries"];

    public static readonly string[] StoreLockedColumns =
        ["bLockStore", "LockStore", "StoreLocked", "bFixedStore", "IsStoreFixed", "FixedStore"];

    public static readonly string[] CurrencyValColumns =
        ["CurrencyVal", "CurVal", "BillCurrencyVal"];

    public static readonly string[] PriceCurrencyValColumns =
        ["MatCurrencyVal", "PriceCurrencyVal", "ItemCurrencyVal"];

    public static ReservationBillPattern Interpret(Guid billTypeGuid, IReadOnlyDictionary<string, object?> row)
    {
        var name = FirstString(row, "Name", "LatinName", "btName", "btLatinName");
        var store = ReadGuid(row, StoreGuidColumns);
        var billCurrency = ReadGuid(row, BillCurrencyColumns);
        var priceCurrency = ReadGuid(row, PriceCurrencyColumns);
        var generates = ReadBool(row, GeneratesEntriesColumns);
        var storeLocked = ReadBool(row, StoreLockedColumns);
        var currencyVal = ReadDouble(row, CurrencyValColumns);
        var priceCurrencyVal = ReadDouble(row, PriceCurrencyValColumns);

        var unresolved = new List<string>();
        if (!store.Exists)
        {
            unresolved.Add("store guid");
        }

        if (!billCurrency.Exists)
        {
            unresolved.Add("bill currency");
        }

        if (!priceCurrency.Exists)
        {
            unresolved.Add("price currency");
        }

        if (!generates.Exists)
        {
            unresolved.Add("generate-entries flag");
        }

        if (!storeLocked.Exists)
        {
            unresolved.Add("store-locked flag");
        }

        if (!currencyVal.Exists)
        {
            unresolved.Add("bill currency value");
        }

        if (!priceCurrencyVal.Exists)
        {
            unresolved.Add("price currency value");
        }

        return new ReservationBillPattern(
            billTypeGuid,
            name,
            store.Value,
            StoreName: null,
            store.Exists,
            store.Column,
            storeLocked.Value,
            storeLocked.Exists,
            storeLocked.Column,
            billCurrency.Value,
            BillCurrencyName: null,
            billCurrency.Exists,
            billCurrency.Column,
            priceCurrency.Value,
            PriceCurrencyName: null,
            priceCurrency.Exists,
            priceCurrency.Column,
            currencyVal.Value ?? 1d,
            currencyVal.Exists,
            currencyVal.Column,
            priceCurrencyVal.Value ?? 1d,
            priceCurrencyVal.Exists,
            priceCurrencyVal.Column,
            generates.Value,
            generates.Exists,
            generates.Column,
            unresolved);
    }

    private static string? FirstString(IReadOnlyDictionary<string, object?> row, params string[] names)
    {
        foreach (var name in names)
        {
            if (!row.TryGetValue(name, out var value) || value is null)
            {
                continue;
            }

            var text = Convert.ToString(value)?.Trim();
            if (!string.IsNullOrWhiteSpace(text))
            {
                return text;
            }
        }

        return null;
    }

    private static ColumnValue<Guid?> ReadGuid(IReadOnlyDictionary<string, object?> row, IReadOnlyList<string> columns)
    {
        if (!TryFind(row, columns, out var column, out var raw))
        {
            return new ColumnValue<Guid?>(false, null, null);
        }

            return raw switch
        {
            Guid guid when guid != Guid.Empty => new ColumnValue<Guid?>(true, column, guid),
            string text when Guid.TryParse(text, out var parsed) && parsed != Guid.Empty => new ColumnValue<Guid?>(true, column, parsed),
            _ => new ColumnValue<Guid?>(true, column, null)
        };
    }

    private static ColumnValue<bool?> ReadBool(IReadOnlyDictionary<string, object?> row, IReadOnlyList<string> columns)
    {
        if (!TryFind(row, columns, out var column, out var raw) || raw is null)
        {
            return new ColumnValue<bool?>(column is not null, column, null);
        }

        return raw switch
        {
            bool bit => new ColumnValue<bool?>(true, column, bit),
            byte or short or int or long => new ColumnValue<bool?>(true, column, Convert.ToInt64(raw) != 0),
            string text => new ColumnValue<bool?>(true, column, text.Trim() is "1" or "true" or "True" or "yes"),
            _ => new ColumnValue<bool?>(true, column, null)
        };
    }

    private static ColumnValue<double?> ReadDouble(IReadOnlyDictionary<string, object?> row, IReadOnlyList<string> columns)
    {
        if (!TryFind(row, columns, out var column, out var raw) || raw is null)
        {
            return new ColumnValue<double?>(column is not null, column, null);
        }

        try
        {
            var number = Convert.ToDouble(raw);
            return double.IsFinite(number) && number > 0d
                ? new ColumnValue<double?>(true, column, number)
                : new ColumnValue<double?>(true, column, null);
        }
        catch (Exception exception) when (exception is FormatException or InvalidCastException or OverflowException)
        {
            return new ColumnValue<double?>(true, column, null);
        }
    }

    private static bool TryFind(
        IReadOnlyDictionary<string, object?> row,
        IReadOnlyList<string> columns,
        out string? column,
        out object? value)
    {
        foreach (var candidate in columns)
        {
            if (row.TryGetValue(candidate, out value))
            {
                column = candidate;
                return true;
            }
        }

        column = null;
        value = null;
        return false;
    }

    private readonly record struct ColumnValue<T>(bool Exists, string? Column, T? Value);
}
