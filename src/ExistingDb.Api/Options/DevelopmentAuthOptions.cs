namespace ExistingDb.Api.Options;

public sealed class DevelopmentAuthOptions
{
    public const string SectionName = "DevelopmentAuth";

    public bool BypassSwaggerAuth { get; set; }

    public string UserId { get; set; } = "11111111-1111-1111-1111-111111111111";

    public string UserName { get; set; } = "dev-admin";

    public string Role { get; set; } = "Admin";
}
