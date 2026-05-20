namespace ExistingDb.Api.Options;

public sealed class SeedAdminOptions
{
    public const string SectionName = "SeedAdmin";

    public bool Enabled { get; init; }
    public string UserName { get; init; } = "admin";
    public string Email { get; init; } = "admin@example.local";
    public string Password { get; init; } = string.Empty;
    public string DisplayName { get; init; } = "API Administrator";
}

