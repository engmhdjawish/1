using ExistingDb.Api.Contracts.Bills;

namespace ExistingDb.Api.Services.Bills;

public static class ReservationBillPatternPresenter
{
    public static ReservationBillPatternResponse Present(ReservationBillPattern pattern, string ownerNameSource)
    {
        var decision = ReservationBillGuard.EvaluatePatternForSync(pattern);
        var locked = new List<ReservationPatternPropertyResponse>
        {
            Property("store", "المستودع", pattern.StoreColumnFound, pattern.StoreColumn, FormatGuid(pattern.StoreGuid), pattern.StoreName ?? FormatGuid(pattern.StoreGuid)),
            Property("storeLocked", "المستودع مقفل في النمط", pattern.StoreLockedColumnFound, pattern.StoreLockedColumn, FormatBool(pattern.StoreLocked), FormatBoolLabel(pattern.StoreLocked, pattern.StoreLockedColumnFound)),
            Property("billCurrency", "عملة الفاتورة", pattern.BillCurrencyColumnFound, pattern.BillCurrencyColumn, FormatGuid(pattern.BillCurrencyGuid), pattern.BillCurrencyName ?? FormatGuid(pattern.BillCurrencyGuid)),
            Property("priceCurrency", "عملة سعر المادة", pattern.PriceCurrencyColumnFound, pattern.PriceCurrencyColumn, FormatGuid(pattern.PriceCurrencyGuid), pattern.PriceCurrencyName ?? FormatGuid(pattern.PriceCurrencyGuid)),
            Property("currencyVal", "معامل عملة الفاتورة", pattern.CurrencyValColumnFound, pattern.CurrencyValColumn, pattern.CurrencyVal.ToString(System.Globalization.CultureInfo.InvariantCulture), pattern.CurrencyValColumnFound ? pattern.CurrencyVal.ToString(System.Globalization.CultureInfo.InvariantCulture) : "1 (العمود غير موجود)"),
            Property("generatesEntries", "توليد القيود المحاسبية", pattern.GeneratesEntriesColumnFound, pattern.GeneratesEntriesColumn, FormatBool(pattern.GeneratesEntries), FormatBoolLabel(pattern.GeneratesEntries, pattern.GeneratesEntriesColumnFound))
        };

        return new ReservationBillPatternResponse(
            pattern.BillTypeGuid,
            pattern.Name,
            decision.Succeeded,
            decision.Succeeded ? null : decision.Message,
            locked,
            [OwnerNameChoice(ownerNameSource)],
            pattern.Unresolved);
    }

    public static ReservationSettingChoiceResponse OwnerNameChoice(string ownerNameSource) =>
        new(
            "ownerNameSource",
            "مصدر اسم صاحب الحجز",
            ReservationOwnerNameSources.IsAllowed(ownerNameSource)
                ? ReservationOwnerNameSources.OrderCustomerName
                : ReservationOwnerNameSources.OrderCustomerName,
            [new ReservationSettingOptionResponse(ReservationOwnerNameSources.OrderCustomerName, "اسم عميل الطلب في ملاحظات الفاتورة")]);

    private static ReservationPatternPropertyResponse Property(
        string key,
        string label,
        bool found,
        string? column,
        string? value,
        string? display) =>
        new(key, label, Locked: true, found, column, value, display);

    private static string? FormatGuid(Guid? value) =>
        value is null || value == Guid.Empty ? null : value.Value.ToString("D");

    private static string? FormatBool(bool? value) =>
        value switch
        {
            true => "true",
            false => "false",
            _ => null
        };

    private static string FormatBoolLabel(bool? value, bool found)
    {
        if (!found)
        {
            return "العمود غير موجود في نمط الفاتورة";
        }

        return value switch
        {
            true => "نعم",
            false => "لا",
            _ => "غير محدد"
        };
    }
}
