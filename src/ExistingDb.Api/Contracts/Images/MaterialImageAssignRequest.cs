namespace ExistingDb.Api.Contracts.Images;

public sealed record MaterialImageAssignRequest(IReadOnlyList<Guid> MaterialGuids);
