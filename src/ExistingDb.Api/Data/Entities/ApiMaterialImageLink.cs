namespace ExistingDb.Api.Data.Entities;

public sealed class ApiMaterialImageLink
{
    public Guid ImageId { get; set; }
    public Guid MaterialGuid { get; set; }
    public bool IsPrimary { get; set; }
    public Guid? CreatedByUserId { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public ApiMaterialImage? Image { get; set; }
}

