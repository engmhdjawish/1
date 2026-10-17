using System.Globalization;
using ExistingDb.Api.Contracts.Bills;

namespace ExistingDb.Api.Services.Bills;

public static class ReservationBillPatternPresenter
{
    public static ReservationBillPatternResponse Present(ReservationBillPattern pattern, string ownerNameSource)
    {
        var decision = ReservationBillGuard.EvaluatePatternForSync(pattern);
        var locked = new List<ReservationPatternPropertyResponse>
        {
            BoolProperty("bNoEntry", "لا يولّد قيوداً محاسبية", pattern.NoEntryColumnFound, pattern.NoEntry),
            BoolProperty("bAutoEntry", "قيد آلي", pattern.AutoEntryColumnFound, pattern.AutoEntry),
            BoolProperty("bAutoEntryPost", "ترحيل القيد الآلي", pattern.AutoEntryPostColumnFound, pattern.AutoEntryPost),
            BoolProperty("bFixStore", "تثبيت المستودع", pattern.FixStoreColumnFound, pattern.FixStore),
            GuidProperty("DefStoreGUID", "المستودع", pattern.StoreColumnFound, pattern.StoreGuid, pattern.StoreName),
            // Read-only fact: whether Ameen forbids changing the currency on the invoice. Sync does not require it to be on.
            BoolProperty("bFixCurrency", "تثبيت عملة الفاتورة", pattern.FixCurrencyColumnFound, pattern.FixCurrency),
            GuidProperty("DefCurrencyGUID", "عملة الفاتورة", pattern.BillCurrencyColumnFound, pattern.BillCurrencyGuid, pattern.BillCurrencyName),
            Property("DefPrice", "مصدر السعر", pattern.DefPriceColumnFound, ReservationBillPatternInterpreter.DefPriceColumn, pattern.DefPrice?.ToString(CultureInfo.InvariantCulture), pattern.DefPriceColumnFound ? pattern.DefPrice?.ToString(CultureInfo.InvariantCulture) : null),
            GuidProperty("MaterialPriceListGUID", "قائمة أسعار المواد", pattern.MaterialPriceListColumnFound, pattern.MaterialPriceListGuid, null)
        };

        if (pattern.FixBranchColumnFound)
        {
            locked.Add(BoolProperty("bFixBranch", "تثبيت الفرع", true, pattern.FixBranch));
        }

        if (pattern.BranchColumnFound)
        {
            locked.Add(GuidProperty("DefBranchGUID", "الفرع", true, pattern.BranchGuid, null));
        }

        return new ReservationBillPatternResponse(
            pattern.BillTypeGuid,
            pattern.Name,
            decision.Succeeded,
            decision.Succeeded ? null : decision.Message,
            locked,
            [OwnerNameChoice(ownerNameSource)],
            pattern.MissingColumns);
    }

    public static ReservationSettingChoiceResponse OwnerNameChoice(string ownerNameSource) =>
        new(
            "ownerNameSource",
            "مصدر اسم صاحب الحجز",
            ReservationOwnerNameSources.OrderCustomerName,
            [new ReservationSettingOptionResponse(ReservationOwnerNameSources.OrderCustomerName, "اسم عميل الطلب في ملاحظات الفاتورة")]);

    private static ReservationPatternPropertyResponse BoolProperty(string key, string label, bool found, bool? value) =>
        Property(key, label, found, key, FormatBool(value), FormatBoolLabel(value, found));

    private static ReservationPatternPropertyResponse GuidProperty(string key, string label, bool found, Guid? value, string? name) =>
        Property(key, label, found, key, FormatGuid(value), name ?? FormatGuid(value));

    private static ReservationPatternPropertyResponse Property(
        string key,
        string label,
        bool found,
        string? column,
        string? value,
        string? display) =>
        new(key, label, Locked: true, found, column, value, found ? display : "العمود غير موجود في bt000");

    private static string? FormatGuid(Guid? value) =>
        value is null || value == Guid.Empty ? null : value.Value.ToString("D");

    private static string? FormatBool(bool? value) =>
        value switch
        {
            true => "true",
            false => "false",
            _ => null
        };

    private static string? FormatBoolLabel(bool? value, bool found)
    {
        if (!found)
        {
            return null;
        }

        return value switch
        {
            true => "نعم",
            false => "لا",
            _ => "غير محدد"
        };
    }
}
