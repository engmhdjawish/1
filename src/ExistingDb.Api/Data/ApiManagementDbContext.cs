using ExistingDb.Api.Authorization;
using ExistingDb.Api.Data.Entities;
using ExistingDb.Api.Images;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Data;

public sealed class ApiManagementDbContext(DbContextOptions<ApiManagementDbContext> options) : DbContext(options)
{
    public DbSet<ApiUser> Users => Set<ApiUser>();
    public DbSet<ApiRole> Roles => Set<ApiRole>();
    public DbSet<ApiPermission> Permissions => Set<ApiPermission>();
    public DbSet<ApiResource> Resources => Set<ApiResource>();
    public DbSet<ApiResourceField> ResourceFields => Set<ApiResourceField>();
    public DbSet<ApiUserRole> UserRoles => Set<ApiUserRole>();
    public DbSet<ApiRolePermission> RolePermissions => Set<ApiRolePermission>();
    public DbSet<ApiFieldPermission> FieldPermissions => Set<ApiFieldPermission>();
    public DbSet<ApiRefreshToken> RefreshTokens => Set<ApiRefreshToken>();
    public DbSet<ApiAuditLog> AuditLogs => Set<ApiAuditLog>();
    public DbSet<ApiSetting> Settings => Set<ApiSetting>();
    public DbSet<ApiMaterialImage> MaterialImages => Set<ApiMaterialImage>();
    public DbSet<ApiMaterialImageLink> MaterialImageLinks => Set<ApiMaterialImageLink>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.Entity<ApiUser>(entity =>
        {
            entity.ToTable("ApiUsers");
            entity.HasKey(user => user.Id);
            entity.Property(user => user.UserName).HasMaxLength(100).IsRequired();
            entity.Property(user => user.NormalizedUserName).HasMaxLength(100).IsRequired();
            entity.Property(user => user.Email).HasMaxLength(255).IsRequired();
            entity.Property(user => user.NormalizedEmail).HasMaxLength(255).IsRequired();
            entity.Property(user => user.DisplayName).HasMaxLength(200).IsRequired();
            entity.Property(user => user.PasswordHash).IsRequired();
            entity.HasIndex(user => user.NormalizedUserName).IsUnique();
            entity.HasIndex(user => user.NormalizedEmail);
            entity.HasIndex(user => user.LegacyUserGuid);
        });

        modelBuilder.Entity<ApiRole>(entity =>
        {
            entity.ToTable("ApiRoles");
            entity.HasKey(role => role.Id);
            entity.Property(role => role.Name).HasMaxLength(100).IsRequired();
            entity.Property(role => role.NormalizedName).HasMaxLength(100).IsRequired();
            entity.Property(role => role.Description).HasMaxLength(255);
            entity.HasIndex(role => role.NormalizedName).IsUnique();
        });

        modelBuilder.Entity<ApiPermission>(entity =>
        {
            entity.ToTable("ApiPermissions");
            entity.HasKey(permission => permission.Id);
            entity.Property(permission => permission.Code).HasMaxLength(150).IsRequired();
            entity.Property(permission => permission.Name).HasMaxLength(150).IsRequired();
            entity.Property(permission => permission.Description).HasMaxLength(500);
            entity.Property(permission => permission.Category).HasMaxLength(100).IsRequired();
            entity.HasIndex(permission => permission.Code).IsUnique();
        });

        modelBuilder.Entity<ApiResource>(entity =>
        {
            entity.ToTable("ApiResources");
            entity.HasKey(resource => resource.Id);
            entity.Property(resource => resource.Code).HasMaxLength(100).IsRequired();
            entity.Property(resource => resource.Name).HasMaxLength(150).IsRequired();
            entity.Property(resource => resource.Description).HasMaxLength(500);
            entity.HasIndex(resource => resource.Code).IsUnique();
        });

        modelBuilder.Entity<ApiResourceField>(entity =>
        {
            entity.ToTable("ApiResourceFields");
            entity.HasKey(field => field.Id);
            entity.Property(field => field.FieldName).HasMaxLength(100).IsRequired();
            entity.Property(field => field.DisplayName).HasMaxLength(150).IsRequired();
            entity.Property(field => field.DefaultReadMode).HasConversion<string>().HasMaxLength(20);
            entity.Property(field => field.MaskingStrategy).HasConversion<string>().HasMaxLength(20);
            entity.HasIndex(field => new { field.ResourceId, field.FieldName }).IsUnique();
            entity.HasOne(field => field.Resource)
                .WithMany(resource => resource.Fields)
                .HasForeignKey(field => field.ResourceId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<ApiUserRole>(entity =>
        {
            entity.ToTable("ApiUserRoles");
            entity.HasKey(userRole => new { userRole.UserId, userRole.RoleId });
            entity.HasOne(userRole => userRole.User)
                .WithMany(user => user.UserRoles)
                .HasForeignKey(userRole => userRole.UserId)
                .OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(userRole => userRole.Role)
                .WithMany(role => role.UserRoles)
                .HasForeignKey(userRole => userRole.RoleId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<ApiRolePermission>(entity =>
        {
            entity.ToTable("ApiRolePermissions");
            entity.HasKey(rolePermission => new { rolePermission.RoleId, rolePermission.PermissionId });
            entity.HasOne(rolePermission => rolePermission.Role)
                .WithMany(role => role.RolePermissions)
                .HasForeignKey(rolePermission => rolePermission.RoleId)
                .OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(rolePermission => rolePermission.Permission)
                .WithMany(permission => permission.RolePermissions)
                .HasForeignKey(rolePermission => rolePermission.PermissionId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<ApiFieldPermission>(entity =>
        {
            entity.ToTable("ApiFieldPermissions");
            entity.HasKey(fieldPermission => new { fieldPermission.RoleId, fieldPermission.ResourceFieldId });
            entity.Property(fieldPermission => fieldPermission.ReadMode).HasConversion<string>().HasMaxLength(20);
            entity.HasOne(fieldPermission => fieldPermission.Role)
                .WithMany(role => role.FieldPermissions)
                .HasForeignKey(fieldPermission => fieldPermission.RoleId)
                .OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(fieldPermission => fieldPermission.ResourceField)
                .WithMany(field => field.FieldPermissions)
                .HasForeignKey(fieldPermission => fieldPermission.ResourceFieldId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<ApiRefreshToken>(entity =>
        {
            entity.ToTable("ApiRefreshTokens");
            entity.HasKey(token => token.Id);
            entity.Property(token => token.TokenHash).HasMaxLength(512).IsRequired();
            entity.Property(token => token.CreatedByIp).HasMaxLength(50);
            entity.Property(token => token.ReplacedByTokenHash).HasMaxLength(512);
            entity.HasIndex(token => token.TokenHash).IsUnique();
            entity.HasIndex(token => new { token.UserId, token.ExpiresAt });
            entity.HasOne(token => token.User)
                .WithMany(user => user.RefreshTokens)
                .HasForeignKey(token => token.UserId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<ApiAuditLog>(entity =>
        {
            entity.ToTable("ApiAuditLogs");
            entity.HasKey(audit => audit.Id);
            entity.Property(audit => audit.Action).HasMaxLength(100).IsRequired();
            entity.Property(audit => audit.EntityName).HasMaxLength(100);
            entity.Property(audit => audit.RecordId).HasMaxLength(100);
            entity.Property(audit => audit.HttpMethod).HasMaxLength(10).IsRequired();
            entity.Property(audit => audit.Path).HasMaxLength(500).IsRequired();
            entity.Property(audit => audit.IpAddress).HasMaxLength(50);
            entity.Property(audit => audit.UserAgent).HasMaxLength(500);
            entity.Property(audit => audit.UserName).HasMaxLength(100);
            entity.HasIndex(audit => audit.CreatedAt);
            entity.HasIndex(audit => audit.UserId);
            entity.HasIndex(audit => audit.Action);
        });

        modelBuilder.Entity<ApiSetting>(entity =>
        {
            entity.ToTable("ApiSettings");
            entity.HasKey(setting => setting.Key);
            entity.Property(setting => setting.Key).HasMaxLength(150).IsRequired();
            entity.Property(setting => setting.Value).HasMaxLength(1000);
            entity.Property(setting => setting.Description).HasMaxLength(500);
        });

        modelBuilder.Entity<ApiMaterialImage>(entity =>
        {
            entity.ToTable("ApiMaterialImages");
            entity.HasKey(image => image.Id);
            entity.Property(image => image.Name).HasMaxLength(1000).IsRequired();
            entity.Property(image => image.ThumbnailName).HasMaxLength(1000);
            entity.Property(image => image.OriginalFileName).HasMaxLength(255).IsRequired();
            entity.Property(image => image.StoredFileName).HasMaxLength(255).IsRequired();
            entity.Property(image => image.ContentType).HasMaxLength(100).IsRequired();
            entity.HasIndex(image => image.Name).IsUnique();
            entity.HasIndex(image => image.CreatedAt);
        });

        modelBuilder.Entity<ApiMaterialImageLink>(entity =>
        {
            entity.ToTable("ApiMaterialImageLinks");
            entity.HasKey(link => new { link.ImageId, link.MaterialGuid });
            entity.HasIndex(link => link.MaterialGuid);
            entity.HasOne(link => link.Image)
                .WithMany(image => image.MaterialLinks)
                .HasForeignKey(link => link.ImageId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        SeedAuthorizationData(modelBuilder);
        SeedImageSettings(modelBuilder);
    }

    private static void SeedAuthorizationData(ModelBuilder modelBuilder)
    {
        var seedDate = new DateTimeOffset(2026, 1, 1, 0, 0, 0, TimeSpan.Zero);

        modelBuilder.Entity<ApiRole>().HasData(
            new ApiRole { Id = 1, Name = "Admin", NormalizedName = "ADMIN", Description = "Full API administration access.", IsSystemRole = true, CreatedAt = seedDate },
            new ApiRole { Id = 2, Name = "Manager", NormalizedName = "MANAGER", Description = "Operational management access.", IsSystemRole = true, CreatedAt = seedDate },
            new ApiRole { Id = 3, Name = "User", NormalizedName = "USER", Description = "Standard API user access.", IsSystemRole = true, CreatedAt = seedDate },
            new ApiRole { Id = 4, Name = "Viewer", NormalizedName = "VIEWER", Description = "Read-only API access.", IsSystemRole = true, CreatedAt = seedDate });

        var permissions = new[]
        {
            Permission(1, "admin.users.manage", "Manage API users", "admin"),
            Permission(2, "admin.roles.manage", "Manage API roles", "admin"),
            Permission(3, "admin.permissions.read", "Read permissions", "admin"),
            Permission(4, "audit.read", "Read audit logs", "audit"),
            Permission(5, "customers.read", "Read customers", "customers"),
            Permission(6, "customers.create", "Create customers", "customers"),
            Permission(7, "customers.update", "Update customers", "customers"),
            Permission(8, "materials.read", "Read materials", "materials"),
            Permission(9, "materials.update", "Update materials", "materials"),
            Permission(10, "bills.read", "Read bills", "bills"),
            Permission(11, "bills.create", "Create bills", "bills"),
            Permission(12, "bills.post", "Post bills", "bills"),
            Permission(13, "accounts.read", "Read accounts", "accounts"),
            Permission(14, "entries.read", "Read entries", "accounts"),
            Permission(15, "inventory.read", "Read inventory balances", "inventory")
        };

        modelBuilder.Entity<ApiPermission>().HasData(permissions);

        modelBuilder.Entity<ApiRolePermission>().HasData(
            permissions.Select(permission => new ApiRolePermission { RoleId = 1, PermissionId = permission.Id }).ToArray());

        modelBuilder.Entity<ApiRolePermission>().HasData(
            new ApiRolePermission { RoleId = 2, PermissionId = 3 },
            new ApiRolePermission { RoleId = 2, PermissionId = 4 },
            new ApiRolePermission { RoleId = 2, PermissionId = 5 },
            new ApiRolePermission { RoleId = 2, PermissionId = 6 },
            new ApiRolePermission { RoleId = 2, PermissionId = 7 },
            new ApiRolePermission { RoleId = 2, PermissionId = 8 },
            new ApiRolePermission { RoleId = 2, PermissionId = 9 },
            new ApiRolePermission { RoleId = 2, PermissionId = 10 },
            new ApiRolePermission { RoleId = 2, PermissionId = 13 },
            new ApiRolePermission { RoleId = 2, PermissionId = 14 },
            new ApiRolePermission { RoleId = 2, PermissionId = 15 },
            new ApiRolePermission { RoleId = 3, PermissionId = 5 },
            new ApiRolePermission { RoleId = 3, PermissionId = 8 },
            new ApiRolePermission { RoleId = 3, PermissionId = 10 },
            new ApiRolePermission { RoleId = 3, PermissionId = 13 },
            new ApiRolePermission { RoleId = 3, PermissionId = 15 },
            new ApiRolePermission { RoleId = 4, PermissionId = 5 },
            new ApiRolePermission { RoleId = 4, PermissionId = 8 },
            new ApiRolePermission { RoleId = 4, PermissionId = 10 },
            new ApiRolePermission { RoleId = 4, PermissionId = 13 },
            new ApiRolePermission { RoleId = 4, PermissionId = 15 });

        modelBuilder.Entity<ApiResource>().HasData(
            Resource(1, "customers", "Customers"),
            Resource(2, "materials", "Materials"),
            Resource(3, "bills", "Bills"),
            Resource(4, "billItems", "Bill items"),
            Resource(5, "accounts", "Accounts"),
            Resource(6, "inventory", "Inventory"));

        modelBuilder.Entity<ApiResourceField>().HasData(
            Field(1, 1, "Phone1", "Primary phone", true, FieldAccessMode.Mask, MaskingStrategy.Phone),
            Field(2, 1, "Phone2", "Secondary phone", true, FieldAccessMode.Mask, MaskingStrategy.Phone),
            Field(3, 1, "Mobile", "Mobile phone", true, FieldAccessMode.Mask, MaskingStrategy.Phone),
            Field(4, 1, "EMail", "Email", true, FieldAccessMode.Mask, MaskingStrategy.Email),
            Field(5, 1, "AccountGUID", "Linked account", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(6, 2, "AvgPrice", "Average price", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(7, 2, "LastPrice", "Last price", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(8, 2, "Whole", "Wholesale SYP price", true, FieldAccessMode.Mask, MaskingStrategy.Full),
            Field(9, 2, "EndUser", "Purchase USD price", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(10, 3, "Total", "Bill total", true, FieldAccessMode.Allow, MaskingStrategy.Full),
            Field(11, 3, "TotalDisc", "Total discount", true, FieldAccessMode.Mask, MaskingStrategy.Full),
            Field(12, 3, "Profits", "Profits", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(13, 4, "Price", "Item price", true, FieldAccessMode.Allow, MaskingStrategy.Full),
            Field(14, 4, "Discount", "Item discount", true, FieldAccessMode.Mask, MaskingStrategy.Full),
            Field(15, 4, "Profits", "Item profits", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(16, 5, "Debit", "Debit", true, FieldAccessMode.Mask, MaskingStrategy.Full),
            Field(17, 5, "Credit", "Credit", true, FieldAccessMode.Mask, MaskingStrategy.Full),
            Field(18, 5, "InitDebit", "Opening debit", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(19, 5, "InitCredit", "Opening credit", true, FieldAccessMode.Deny, MaskingStrategy.Full),
            Field(20, 6, "Qty", "Quantity", true, FieldAccessMode.Allow, MaskingStrategy.Full),
            Field(21, 6, "Book", "Book quantity", true, FieldAccessMode.Mask, MaskingStrategy.Full),
            Field(22, 2, "Half", "Wholesale USD price", true, FieldAccessMode.Mask, MaskingStrategy.Full));
    }

    private static ApiPermission Permission(int id, string code, string name, string category) =>
        new() { Id = id, Code = code, Name = name, Category = category };

    private static ApiResource Resource(int id, string code, string name) =>
        new() { Id = id, Code = code, Name = name };

    private static ApiResourceField Field(
        int id,
        int resourceId,
        string fieldName,
        string displayName,
        bool isSensitive,
        FieldAccessMode defaultReadMode,
        MaskingStrategy maskingStrategy) =>
        new()
        {
            Id = id,
            ResourceId = resourceId,
            FieldName = fieldName,
            DisplayName = displayName,
            IsSensitive = isSensitive,
            DefaultReadMode = defaultReadMode,
            DefaultCanCreate = defaultReadMode == FieldAccessMode.Allow,
            DefaultCanUpdate = defaultReadMode == FieldAccessMode.Allow,
            MaskingStrategy = maskingStrategy
        };

    private static void SeedImageSettings(ModelBuilder modelBuilder)
    {
        var seedDate = new DateTimeOffset(2026, 1, 1, 0, 0, 0, TimeSpan.Zero);
        modelBuilder.Entity<ApiSetting>().HasData(
            new ApiSetting
            {
                Key = ImageSettingsKeys.ImagesDirectory,
                Value = @"C:\images",
                Description = "Directory where original material image files are uploaded.",
                CreatedAt = seedDate
            },
            new ApiSetting
            {
                Key = ImageSettingsKeys.ThumbnailsDirectory,
                Value = @"C:\images\thumbnails",
                Description = "Directory where generated material image thumbnails are saved.",
                CreatedAt = seedDate
            });
    }
}

