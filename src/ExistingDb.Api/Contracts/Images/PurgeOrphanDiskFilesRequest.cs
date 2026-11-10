namespace ExistingDb.Api.Contracts.Images;

public sealed class PurgeOrphanDiskFilesRequest
{
    public List<string>? FileNames { get; init; }

    public bool DryRun { get; init; }
}
