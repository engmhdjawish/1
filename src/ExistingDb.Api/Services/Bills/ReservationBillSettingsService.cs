using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Bills;

public static class ReservationBillSettingKeys
{
    public const string BillTypeGuid = "ReservationBills:BillTypeGuid";
    public const string OwnerNameSource = "ReservationBills:OwnerNameSource";
}

public static class ReservationOwnerNameSources
{
    public const string OrderCustomerName = "orderCustomerName";

    public static bool IsAllowed(string? value) =>
        string.Equals(value?.Trim(), OrderCustomerName, StringComparison.Ordinal);
}

public sealed record ReservationBillSettings(Guid? BillTypeGuid, string OwnerNameSource);

public interface IReservationBillSettingsService
{
    Task<ReservationBillSettings> GetAsync(CancellationToken cancellationToken);

    Task SaveAsync(Guid billTypeGuid, string ownerNameSource, CancellationToken cancellationToken);
}

public sealed class ReservationBillSettingsService(ApiManagementDbContext dbContext) : IReservationBillSettingsService
{
    public async Task<ReservationBillSettings> GetAsync(CancellationToken cancellationToken)
    {
        var rows = await dbContext.Settings
            .AsNoTracking()
            .Where(setting => setting.Key == ReservationBillSettingKeys.BillTypeGuid
                || setting.Key == ReservationBillSettingKeys.OwnerNameSource)
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

        return new ReservationBillSettings(billTypeGuid, ownerNameSource!.Trim());
    }

    public async Task SaveAsync(Guid billTypeGuid, string ownerNameSource, CancellationToken cancellationToken)
    {
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
