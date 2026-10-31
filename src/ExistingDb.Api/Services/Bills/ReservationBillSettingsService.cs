using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Bills;

public static class ReservationBillSettingKeys
{
    public const string BillTypeGuid = "ReservationBills:BillTypeGuid";
    public const string OwnerNameSource = "ReservationBills:OwnerNameSource";
    public const string SyncMode = "ReservationBills:SyncMode";
}

public static class ReservationOwnerNameSources
{
    public const string OrderCustomerName = "orderCustomerName";

    public static bool IsAllowed(string? value) =>
        string.Equals(value?.Trim(), OrderCustomerName, StringComparison.Ordinal);
}

public static class ReservationSyncModes
{
    public const string Automatic = "automatic";
    public const string Manual = "manual";

    public static bool IsAllowed(string? value)
    {
        var trimmed = value?.Trim();
        return string.Equals(trimmed, Automatic, StringComparison.Ordinal)
            || string.Equals(trimmed, Manual, StringComparison.Ordinal);
    }

    /// <summary>
    /// Empty or unknown values stay on <paramref name="fallback"/>, which itself
    /// falls back to manual so a deploy does not start creating live bills.
    /// </summary>
    public static string Normalize(string? value, string fallback = Manual)
    {
        var trimmed = value?.Trim();
        if (IsAllowed(trimmed))
        {
            return trimmed!;
        }

        var fallbackTrimmed = fallback?.Trim();
        return IsAllowed(fallbackTrimmed) ? fallbackTrimmed! : Manual;
    }
}

public sealed record ReservationBillSettings(Guid? BillTypeGuid, string OwnerNameSource, string SyncMode);

public interface IReservationBillSettingsService
{
    Task<ReservationBillSettings> GetAsync(CancellationToken cancellationToken);

    Task SaveAsync(Guid billTypeGuid, string ownerNameSource, string? syncMode, CancellationToken cancellationToken);
}

public sealed class ReservationBillSettingsService(ApiManagementDbContext dbContext) : IReservationBillSettingsService
{
    public async Task<ReservationBillSettings> GetAsync(CancellationToken cancellationToken)
    {
        var rows = await dbContext.Settings
            .AsNoTracking()
            .Where(setting => setting.Key == ReservationBillSettingKeys.BillTypeGuid
                || setting.Key == ReservationBillSettingKeys.OwnerNameSource
                || setting.Key == ReservationBillSettingKeys.SyncMode)
            .ToListAsync(cancellationToken);

        Guid? billTypeGuid = null;
        var typeText = rows.FirstOrDefault(row => row.Key == ReservationBillSettingKeys.BillTypeGuid)?.Value;
        if (Guid.TryParse(typeText, out var parsed) && parsed != Guid.Empty)
        {
            billTypeGuid = parsed;
        }

        var ownerNameSource = rows.FirstOrDefault(row => row.Key == ReservationBillSettingKeys.OwnerNameSource)?.Value;
        if (!ReservationOwnerNameSources.IsAllowed(ownerNameSource))
        {
            ownerNameSource = ReservationOwnerNameSources.OrderCustomerName;
        }

        var syncMode = ReservationSyncModes.Normalize(
            rows.FirstOrDefault(row => row.Key == ReservationBillSettingKeys.SyncMode)?.Value);

        return new ReservationBillSettings(billTypeGuid, ownerNameSource!.Trim(), syncMode);
    }

    public async Task SaveAsync(
        Guid billTypeGuid,
        string ownerNameSource,
        string? syncMode,
        CancellationToken cancellationToken)
    {
        var mode = ReservationSyncModes.IsAllowed(syncMode)
            ? syncMode!.Trim()
            : (await GetAsync(cancellationToken)).SyncMode;

        await UpsertAsync(
            ReservationBillSettingKeys.BillTypeGuid,
            billTypeGuid.ToString("D"),
            "Bill type selected for reservation invoices. Store and currencies are read from that type.",
            cancellationToken);
        await UpsertAsync(
            ReservationBillSettingKeys.OwnerNameSource,
            ownerNameSource.Trim(),
            "Where the reservation bill stores the owner name.",
            cancellationToken);
        await UpsertAsync(
            ReservationBillSettingKeys.SyncMode,
            mode,
            "Portal reservation sync: automatic calls the API after order changes; manual waits for a staff sync. Default is manual.",
            cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task UpsertAsync(string key, string value, string description, CancellationToken cancellationToken)
    {
        var setting = await dbContext.Settings
            .SingleOrDefaultAsync(item => item.Key == key, cancellationToken);
        if (setting is null)
        {
            dbContext.Settings.Add(new ApiSetting
            {
                Key = key,
                Value = value,
                Description = description,
                CreatedAt = DateTimeOffset.UtcNow
            });
            return;
        }

        setting.Value = value;
        setting.UpdatedAt = DateTimeOffset.UtcNow;
    }
}
