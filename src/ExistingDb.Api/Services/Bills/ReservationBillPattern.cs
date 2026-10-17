namespace ExistingDb.Api.Services.Bills;

/// <summary>
/// Locked bt000 columns from AmnDb2026. These names are exact; there is no fallback list.
/// bNoEntry = 1 means the pattern does not generate accounting entries.
/// bFixStore locks the warehouse to DefStoreGUID. bFixCurrency locks the bill currency to DefCurrencyGUID.
/// DefPrice and MaterialPriceListGUID are the price source. bt000 has no item-currency guid.
/// </summary>
public sealed record ReservationBillPattern(
    Guid BillTypeGuid,
    string? Name,
    bool? NoEntry,
    bool NoEntryColumnFound,
    bool? AutoEntry,
    bool AutoEntryColumnFound,
    bool? AutoEntryPost,
    bool AutoEntryPostColumnFound,
    bool? FixStore,
    bool FixStoreColumnFound,
    Guid? StoreGuid,
    string? StoreName,
    bool StoreColumnFound,
    bool? FixCurrency,
    bool FixCurrencyColumnFound,
    Guid? BillCurrencyGuid,
    string? BillCurrencyName,
    bool BillCurrencyColumnFound,
    int? DefPrice,
    bool DefPriceColumnFound,
    Guid? MaterialPriceListGuid,
    bool MaterialPriceListColumnFound,
    bool? FixBranch,
    bool FixBranchColumnFound,
    Guid? BranchGuid,
    bool BranchColumnFound,
    IReadOnlyList<string> MissingColumns)
{
    public bool SyncAllowed =>
        NoEntry == true
        && FixStore == true
        && StoreGuid is { } store && store != Guid.Empty
        && FixCurrency == true
        && BillCurrencyGuid is { } currency && currency != Guid.Empty;
}

public static class ReservationBillPatternInterpreter
{
    public const string NoEntryColumn = "bNoEntry";
    public const string AutoEntryColumn = "bAutoEntry";
    public const string AutoEntryPostColumn = "bAutoEntryPost";
    public const string FixStoreColumn = "bFixStore";
    public const string StoreColumn = "DefStoreGUID";
    public const string FixCurrencyColumn = "bFixCurrency";
    public const string CurrencyColumn = "DefCurrencyGUID";
    public const string DefPriceColumn = "DefPrice";
    public const string MaterialPriceListColumn = "MaterialPriceListGUID";
    public const string FixBranchColumn = "bFixBranch";
    public const string BranchColumn = "DefBranchGUID";

    public static ReservationBillPattern Interpret(Guid billTypeGuid, IReadOnlyDictionary<string, object?> row)
    {
        var noEntry = ReadBool(row, NoEntryColumn);
        var autoEntry = ReadBool(row, AutoEntryColumn);
        var autoEntryPost = ReadBool(row, AutoEntryPostColumn);
        var fixStore = ReadBool(row, FixStoreColumn);
        var store = ReadGuid(row, StoreColumn);
        var fixCurrency = ReadBool(row, FixCurrencyColumn);
        var currency = ReadGuid(row, CurrencyColumn);
        var defPrice = ReadInt(row, DefPriceColumn);
        var priceList = ReadGuid(row, MaterialPriceListColumn);
        var fixBranch = ReadBool(row, FixBranchColumn);
        var branch = ReadGuid(row, BranchColumn);

        var missing = new List<string>();
        if (!noEntry.Exists)
        {
            missing.Add(NoEntryColumn);
        }

        if (!autoEntry.Exists)
        {
            missing.Add(AutoEntryColumn);
        }

        if (!autoEntryPost.Exists)
        {
            missing.Add(AutoEntryPostColumn);
        }

        if (!fixStore.Exists)
        {
            missing.Add(FixStoreColumn);
        }

        if (!store.Exists)
        {
            missing.Add(StoreColumn);
        }

        if (!fixCurrency.Exists)
        {
            missing.Add(FixCurrencyColumn);
        }

        if (!currency.Exists)
        {
            missing.Add(CurrencyColumn);
        }

        if (!defPrice.Exists)
        {
            missing.Add(DefPriceColumn);
        }

        if (!priceList.Exists)
        {
            missing.Add(MaterialPriceListColumn);
        }

        return new ReservationBillPattern(
            billTypeGuid,
            FirstString(row, "Name", "LatinName"),
            noEntry.Value,
            noEntry.Exists,
            autoEntry.Value,
            autoEntry.Exists,
            autoEntryPost.Value,
            autoEntryPost.Exists,
            fixStore.Value,
            fixStore.Exists,
            store.Value,
            StoreName: null,
            store.Exists,
            fixCurrency.Value,
            fixCurrency.Exists,
            currency.Value,
            BillCurrencyName: null,
            currency.Exists,
            defPrice.Value,
            defPrice.Exists,
            priceList.Value,
            priceList.Exists,
            fixBranch.Value,
            fixBranch.Exists,
            branch.Value,
            branch.Exists,
            missing);
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

    private static ColumnValue<Guid?> ReadGuid(IReadOnlyDictionary<string, object?> row, string column)
    {
        if (!row.TryGetValue(column, out var raw))
        {
            return new ColumnValue<Guid?>(false, null);
        }

        return raw switch
        {
            Guid guid when guid != Guid.Empty => new ColumnValue<Guid?>(true, guid),
            string text when Guid.TryParse(text, out var parsed) && parsed != Guid.Empty => new ColumnValue<Guid?>(true, parsed),
            _ => new ColumnValue<Guid?>(true, null)
        };
    }

    private static ColumnValue<bool?> ReadBool(IReadOnlyDictionary<string, object?> row, string column)
    {
        if (!row.TryGetValue(column, out var raw))
        {
            return new ColumnValue<bool?>(false, null);
        }

        if (raw is null)
        {
            return new ColumnValue<bool?>(true, null);
        }

        return raw switch
        {
            bool bit => new ColumnValue<bool?>(true, bit),
            byte or short or int or long => new ColumnValue<bool?>(true, Convert.ToInt64(raw) != 0),
            string text => new ColumnValue<bool?>(true, text.Trim() is "1" or "true" or "True" or "yes"),
            _ => new ColumnValue<bool?>(true, null)
        };
    }

    private static ColumnValue<int?> ReadInt(IReadOnlyDictionary<string, object?> row, string column)
    {
        if (!row.TryGetValue(column, out var raw))
        {
            return new ColumnValue<int?>(false, null);
        }

        if (raw is null)
        {
            return new ColumnValue<int?>(true, null);
        }

        try
        {
            var number = Convert.ToInt32(raw);
            return new ColumnValue<int?>(true, number);
        }
        catch (Exception exception) when (exception is FormatException or InvalidCastException or OverflowException)
        {
            return new ColumnValue<int?>(true, null);
        }
    }

    private readonly record struct ColumnValue<T>(bool Exists, T? Value);
}
