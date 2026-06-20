namespace ExistingDb.Api.Images;

public interface IServiceSettingsService
{
    Task<ServiceRuntimeSettings> GetAsync(CancellationToken cancellationToken = default);
    Task UpdateAsync(bool enabled, CancellationToken cancellationToken = default);
}

public sealed record ServiceRuntimeSettings(bool Enabled);
