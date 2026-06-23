namespace ExistingDb.Api.Contracts.Admin;

public sealed class UpdateServiceStatusRequest
{
    public bool Enabled { get; init; } = true;
}
