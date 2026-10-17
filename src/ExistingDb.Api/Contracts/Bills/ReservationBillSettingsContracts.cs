namespace ExistingDb.Api.Contracts.Bills;

public sealed class SaveReservationBillSettingsRequest
{
    public Guid BillTypeGuid { get; set; }

    public string? OwnerNameSource { get; set; }
}

public sealed record ReservationBillTypeListItemResponse(
    Guid BillTypeGuid,
    string? Name,
    string? LatinName);

public sealed record ReservationPatternPropertyResponse(
    string Key,
    string Label,
    bool Locked,
    bool Found,
    string? SourceColumn,
    string? Value,
    string? Display);

public sealed record ReservationSettingOptionResponse(string Value, string Label);

public sealed record ReservationSettingChoiceResponse(
    string Key,
    string Label,
    string? Value,
    IReadOnlyList<ReservationSettingOptionResponse> Options);

public sealed record ReservationBillPatternResponse(
    Guid BillTypeGuid,
    string? Name,
    bool SyncAllowed,
    string? SyncBlockedReason,
    IReadOnlyList<ReservationPatternPropertyResponse> Locked,
    IReadOnlyList<ReservationSettingChoiceResponse> Changeable,
    IReadOnlyList<string> Unresolved);

public sealed record ReservationBillSettingsResponse(
    Guid? BillTypeGuid,
    string OwnerNameSource,
    ReservationBillPatternResponse? Pattern);
