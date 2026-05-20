namespace ExistingDb.Api.Data.Entities;

public sealed class ApiResource
{
    public int Id { get; set; }
    public string Code { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
    public string? Description { get; set; }

    public ICollection<ApiResourceField> Fields { get; set; } = [];
}

