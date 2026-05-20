namespace ExistingDb.Api.Contracts.Admin;

public sealed record RoleResponse(int Id, string Name, string? Description, bool IsSystemRole);

