using ExistingDb.Api.Authorization;

namespace ExistingDb.Api.Contracts.Admin;

public sealed record ResourceResponse(
    int Id,
    string Code,
    string Name,
    string? Description,
    IReadOnlyCollection<ResourceFieldResponse> Fields);

public sealed record ResourceFieldResponse(
    int Id,
    string FieldName,
    string DisplayName,
    bool IsSensitive,
    FieldAccessMode DefaultReadMode,
    bool DefaultCanCreate,
    bool DefaultCanUpdate,
    MaskingStrategy MaskingStrategy);

