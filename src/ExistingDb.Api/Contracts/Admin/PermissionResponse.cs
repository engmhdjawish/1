namespace ExistingDb.Api.Contracts.Admin;

public sealed record PermissionResponse(int Id, string Code, string Name, string Category, string? Description);

