namespace ExistingDb.Api.Data.Entities;

public sealed class ApiUserRole
{
    public Guid UserId { get; set; }
    public int RoleId { get; set; }

    public ApiUser? User { get; set; }
    public ApiRole? Role { get; set; }
}

