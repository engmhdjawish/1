using ExistingDb.Api.Data;
using ExistingDb.Api.Data.Entities;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Images;

public sealed class ServiceSettingsService(ApiManagementDbContext dbContext) : IServiceSettingsService
{
    public async Task<ServiceRuntimeSettings> GetAsync(CancellationToken cancellationToken = default)
    {
        var value = await dbContext.Settings
            .AsNoTracking()
            .Where(setting => setting.Key == ServiceSettingsKeys.Enabled)
            .Select(setting => setting.Value)
            .SingleOrDefaultAsync(cancellationToken);

        return new ServiceRuntimeSettings(ParseEnabled(value));
    }

    public async Task UpdateAsync(bool enabled, CancellationToken cancellationToken = default)
    {
        var setting = await dbContext.Settings
            .SingleOrDefaultAsync(item => item.Key == ServiceSettingsKeys.Enabled, cancellationToken);

        if (setting is null)
        {
            dbContext.Settings.Add(new ApiSetting
            {
                Key = ServiceSettingsKeys.Enabled,
                Value = enabled ? "true" : "false",
                Description = "When false, the API returns 503 for operational endpoints.",
                CreatedAt = DateTimeOffset.UtcNow,
            });
        }
        else
        {
            setting.Value = enabled ? "true" : "false";
            setting.UpdatedAt = DateTimeOffset.UtcNow;
        }

        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private static bool ParseEnabled(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return true;
        }

        return value.Trim() switch
        {
            "0" or "false" or "False" or "FALSE" or "no" or "No" => false,
            _ => true,
        };
    }
}
