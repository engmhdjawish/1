using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

#pragma warning disable CA1814 // Prefer jagged arrays over multidimensional

namespace ExistingDb.Api.Data.Migrations
{
    /// <inheritdoc />
    public partial class InitialApiManagementSchema : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "ApiAuditLogs",
                columns: table => new
                {
                    Id = table.Column<long>(type: "bigint", nullable: false)
                        .Annotation("SqlServer:Identity", "1, 1"),
                    UserId = table.Column<Guid>(type: "uniqueidentifier", nullable: true),
                    UserName = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: true),
                    LegacyUserGuid = table.Column<Guid>(type: "uniqueidentifier", nullable: true),
                    Action = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    EntityName = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: true),
                    RecordId = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: true),
                    RecordGuid = table.Column<Guid>(type: "uniqueidentifier", nullable: true),
                    HttpMethod = table.Column<string>(type: "nvarchar(10)", maxLength: 10, nullable: false),
                    Path = table.Column<string>(type: "nvarchar(500)", maxLength: 500, nullable: false),
                    IpAddress = table.Column<string>(type: "nvarchar(50)", maxLength: 50, nullable: true),
                    UserAgent = table.Column<string>(type: "nvarchar(500)", maxLength: 500, nullable: true),
                    StatusCode = table.Column<int>(type: "int", nullable: false),
                    RequestBody = table.Column<string>(type: "nvarchar(max)", nullable: true),
                    OldValues = table.Column<string>(type: "nvarchar(max)", nullable: true),
                    NewValues = table.Column<string>(type: "nvarchar(max)", nullable: true),
                    ErrorMessage = table.Column<string>(type: "nvarchar(max)", nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiAuditLogs", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ApiPermissions",
                columns: table => new
                {
                    Id = table.Column<int>(type: "int", nullable: false)
                        .Annotation("SqlServer:Identity", "1, 1"),
                    Code = table.Column<string>(type: "nvarchar(150)", maxLength: 150, nullable: false),
                    Name = table.Column<string>(type: "nvarchar(150)", maxLength: 150, nullable: false),
                    Description = table.Column<string>(type: "nvarchar(500)", maxLength: 500, nullable: true),
                    Category = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiPermissions", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ApiResources",
                columns: table => new
                {
                    Id = table.Column<int>(type: "int", nullable: false)
                        .Annotation("SqlServer:Identity", "1, 1"),
                    Code = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    Name = table.Column<string>(type: "nvarchar(150)", maxLength: 150, nullable: false),
                    Description = table.Column<string>(type: "nvarchar(500)", maxLength: 500, nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiResources", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ApiRoles",
                columns: table => new
                {
                    Id = table.Column<int>(type: "int", nullable: false)
                        .Annotation("SqlServer:Identity", "1, 1"),
                    Name = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    NormalizedName = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    Description = table.Column<string>(type: "nvarchar(255)", maxLength: 255, nullable: true),
                    IsSystemRole = table.Column<bool>(type: "bit", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiRoles", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ApiUsers",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                    LegacyUserGuid = table.Column<Guid>(type: "uniqueidentifier", nullable: true),
                    UserName = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    NormalizedUserName = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    Email = table.Column<string>(type: "nvarchar(255)", maxLength: 255, nullable: false),
                    NormalizedEmail = table.Column<string>(type: "nvarchar(255)", maxLength: 255, nullable: false),
                    PasswordHash = table.Column<string>(type: "nvarchar(max)", nullable: false),
                    DisplayName = table.Column<string>(type: "nvarchar(200)", maxLength: 200, nullable: false),
                    IsActive = table.Column<bool>(type: "bit", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: true),
                    LastLoginAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiUsers", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ApiResourceFields",
                columns: table => new
                {
                    Id = table.Column<int>(type: "int", nullable: false)
                        .Annotation("SqlServer:Identity", "1, 1"),
                    ResourceId = table.Column<int>(type: "int", nullable: false),
                    FieldName = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                    DisplayName = table.Column<string>(type: "nvarchar(150)", maxLength: 150, nullable: false),
                    IsSensitive = table.Column<bool>(type: "bit", nullable: false),
                    DefaultReadMode = table.Column<string>(type: "nvarchar(20)", maxLength: 20, nullable: false),
                    DefaultCanCreate = table.Column<bool>(type: "bit", nullable: false),
                    DefaultCanUpdate = table.Column<bool>(type: "bit", nullable: false),
                    MaskingStrategy = table.Column<string>(type: "nvarchar(20)", maxLength: 20, nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiResourceFields", x => x.Id);
                    table.ForeignKey(
                        name: "FK_ApiResourceFields_ApiResources_ResourceId",
                        column: x => x.ResourceId,
                        principalTable: "ApiResources",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "ApiRolePermissions",
                columns: table => new
                {
                    RoleId = table.Column<int>(type: "int", nullable: false),
                    PermissionId = table.Column<int>(type: "int", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiRolePermissions", x => new { x.RoleId, x.PermissionId });
                    table.ForeignKey(
                        name: "FK_ApiRolePermissions_ApiPermissions_PermissionId",
                        column: x => x.PermissionId,
                        principalTable: "ApiPermissions",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_ApiRolePermissions_ApiRoles_RoleId",
                        column: x => x.RoleId,
                        principalTable: "ApiRoles",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "ApiRefreshTokens",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                    UserId = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                    TokenHash = table.Column<string>(type: "nvarchar(512)", maxLength: 512, nullable: false),
                    ExpiresAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false),
                    RevokedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false),
                    CreatedByIp = table.Column<string>(type: "nvarchar(50)", maxLength: 50, nullable: true),
                    ReplacedByTokenHash = table.Column<string>(type: "nvarchar(512)", maxLength: 512, nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiRefreshTokens", x => x.Id);
                    table.ForeignKey(
                        name: "FK_ApiRefreshTokens_ApiUsers_UserId",
                        column: x => x.UserId,
                        principalTable: "ApiUsers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "ApiUserRoles",
                columns: table => new
                {
                    UserId = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                    RoleId = table.Column<int>(type: "int", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiUserRoles", x => new { x.UserId, x.RoleId });
                    table.ForeignKey(
                        name: "FK_ApiUserRoles_ApiRoles_RoleId",
                        column: x => x.RoleId,
                        principalTable: "ApiRoles",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_ApiUserRoles_ApiUsers_UserId",
                        column: x => x.UserId,
                        principalTable: "ApiUsers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "ApiFieldPermissions",
                columns: table => new
                {
                    RoleId = table.Column<int>(type: "int", nullable: false),
                    ResourceFieldId = table.Column<int>(type: "int", nullable: false),
                    ReadMode = table.Column<string>(type: "nvarchar(20)", maxLength: 20, nullable: false),
                    CanCreate = table.Column<bool>(type: "bit", nullable: false),
                    CanUpdate = table.Column<bool>(type: "bit", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiFieldPermissions", x => new { x.RoleId, x.ResourceFieldId });
                    table.ForeignKey(
                        name: "FK_ApiFieldPermissions_ApiResourceFields_ResourceFieldId",
                        column: x => x.ResourceFieldId,
                        principalTable: "ApiResourceFields",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_ApiFieldPermissions_ApiRoles_RoleId",
                        column: x => x.RoleId,
                        principalTable: "ApiRoles",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.InsertData(
                table: "ApiPermissions",
                columns: new[] { "Id", "Category", "Code", "Description", "Name" },
                values: new object[,]
                {
                    { 1, "admin", "admin.users.manage", null, "Manage API users" },
                    { 2, "admin", "admin.roles.manage", null, "Manage API roles" },
                    { 3, "admin", "admin.permissions.read", null, "Read permissions" },
                    { 4, "audit", "audit.read", null, "Read audit logs" },
                    { 5, "customers", "customers.read", null, "Read customers" },
                    { 6, "customers", "customers.create", null, "Create customers" },
                    { 7, "customers", "customers.update", null, "Update customers" },
                    { 8, "materials", "materials.read", null, "Read materials" },
                    { 9, "materials", "materials.update", null, "Update materials" },
                    { 10, "bills", "bills.read", null, "Read bills" },
                    { 11, "bills", "bills.create", null, "Create bills" },
                    { 12, "bills", "bills.post", null, "Post bills" },
                    { 13, "accounts", "accounts.read", null, "Read accounts" },
                    { 14, "accounts", "entries.read", null, "Read entries" },
                    { 15, "inventory", "inventory.read", null, "Read inventory balances" }
                });

            migrationBuilder.InsertData(
                table: "ApiResources",
                columns: new[] { "Id", "Code", "Description", "Name" },
                values: new object[,]
                {
                    { 1, "customers", null, "Customers" },
                    { 2, "materials", null, "Materials" },
                    { 3, "bills", null, "Bills" },
                    { 4, "billItems", null, "Bill items" },
                    { 5, "accounts", null, "Accounts" },
                    { 6, "inventory", null, "Inventory" }
                });

            migrationBuilder.InsertData(
                table: "ApiRoles",
                columns: new[] { "Id", "CreatedAt", "Description", "IsSystemRole", "Name", "NormalizedName" },
                values: new object[,]
                {
                    { 1, new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)), "Full API administration access.", true, "Admin", "ADMIN" },
                    { 2, new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)), "Operational management access.", true, "Manager", "MANAGER" },
                    { 3, new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)), "Standard API user access.", true, "User", "USER" },
                    { 4, new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)), "Read-only API access.", true, "Viewer", "VIEWER" }
                });

            migrationBuilder.InsertData(
                table: "ApiResourceFields",
                columns: new[] { "Id", "DefaultCanCreate", "DefaultCanUpdate", "DefaultReadMode", "DisplayName", "FieldName", "IsSensitive", "MaskingStrategy", "ResourceId" },
                values: new object[,]
                {
                    { 1, false, false, "Mask", "Primary phone", "Phone1", true, "Phone", 1 },
                    { 2, false, false, "Mask", "Secondary phone", "Phone2", true, "Phone", 1 },
                    { 3, false, false, "Mask", "Mobile phone", "Mobile", true, "Phone", 1 },
                    { 4, false, false, "Mask", "Email", "EMail", true, "Email", 1 },
                    { 5, false, false, "Deny", "Linked account", "AccountGUID", true, "Full", 1 },
                    { 6, false, false, "Deny", "Average price", "AvgPrice", true, "Full", 2 },
                    { 7, false, false, "Deny", "Last price", "LastPrice", true, "Full", 2 },
                    { 8, false, false, "Mask", "Wholesale price", "Whole", true, "Full", 2 },
                    { 9, false, false, "Mask", "Retail price", "Retail", true, "Full", 2 },
                    { 10, true, true, "Allow", "Bill total", "Total", true, "Full", 3 },
                    { 11, false, false, "Mask", "Total discount", "TotalDisc", true, "Full", 3 },
                    { 12, false, false, "Deny", "Profits", "Profits", true, "Full", 3 },
                    { 13, true, true, "Allow", "Item price", "Price", true, "Full", 4 },
                    { 14, false, false, "Mask", "Item discount", "Discount", true, "Full", 4 },
                    { 15, false, false, "Deny", "Item profits", "Profits", true, "Full", 4 },
                    { 16, false, false, "Mask", "Debit", "Debit", true, "Full", 5 },
                    { 17, false, false, "Mask", "Credit", "Credit", true, "Full", 5 },
                    { 18, false, false, "Deny", "Opening debit", "InitDebit", true, "Full", 5 },
                    { 19, false, false, "Deny", "Opening credit", "InitCredit", true, "Full", 5 },
                    { 20, true, true, "Allow", "Quantity", "Qty", true, "Full", 6 },
                    { 21, false, false, "Mask", "Book quantity", "Book", true, "Full", 6 }
                });

            migrationBuilder.InsertData(
                table: "ApiRolePermissions",
                columns: new[] { "PermissionId", "RoleId" },
                values: new object[,]
                {
                    { 1, 1 },
                    { 2, 1 },
                    { 3, 1 },
                    { 4, 1 },
                    { 5, 1 },
                    { 6, 1 },
                    { 7, 1 },
                    { 8, 1 },
                    { 9, 1 },
                    { 10, 1 },
                    { 11, 1 },
                    { 12, 1 },
                    { 13, 1 },
                    { 14, 1 },
                    { 15, 1 },
                    { 3, 2 },
                    { 4, 2 },
                    { 5, 2 },
                    { 6, 2 },
                    { 7, 2 },
                    { 8, 2 },
                    { 9, 2 },
                    { 10, 2 },
                    { 13, 2 },
                    { 14, 2 },
                    { 15, 2 },
                    { 5, 3 },
                    { 8, 3 },
                    { 10, 3 },
                    { 13, 3 },
                    { 15, 3 },
                    { 5, 4 },
                    { 8, 4 },
                    { 10, 4 },
                    { 13, 4 },
                    { 15, 4 }
                });

            migrationBuilder.CreateIndex(
                name: "IX_ApiAuditLogs_Action",
                table: "ApiAuditLogs",
                column: "Action");

            migrationBuilder.CreateIndex(
                name: "IX_ApiAuditLogs_CreatedAt",
                table: "ApiAuditLogs",
                column: "CreatedAt");

            migrationBuilder.CreateIndex(
                name: "IX_ApiAuditLogs_UserId",
                table: "ApiAuditLogs",
                column: "UserId");

            migrationBuilder.CreateIndex(
                name: "IX_ApiFieldPermissions_ResourceFieldId",
                table: "ApiFieldPermissions",
                column: "ResourceFieldId");

            migrationBuilder.CreateIndex(
                name: "IX_ApiPermissions_Code",
                table: "ApiPermissions",
                column: "Code",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ApiRefreshTokens_TokenHash",
                table: "ApiRefreshTokens",
                column: "TokenHash",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ApiRefreshTokens_UserId_ExpiresAt",
                table: "ApiRefreshTokens",
                columns: new[] { "UserId", "ExpiresAt" });

            migrationBuilder.CreateIndex(
                name: "IX_ApiResourceFields_ResourceId_FieldName",
                table: "ApiResourceFields",
                columns: new[] { "ResourceId", "FieldName" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ApiResources_Code",
                table: "ApiResources",
                column: "Code",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ApiRolePermissions_PermissionId",
                table: "ApiRolePermissions",
                column: "PermissionId");

            migrationBuilder.CreateIndex(
                name: "IX_ApiRoles_NormalizedName",
                table: "ApiRoles",
                column: "NormalizedName",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ApiUserRoles_RoleId",
                table: "ApiUserRoles",
                column: "RoleId");

            migrationBuilder.CreateIndex(
                name: "IX_ApiUsers_LegacyUserGuid",
                table: "ApiUsers",
                column: "LegacyUserGuid");

            migrationBuilder.CreateIndex(
                name: "IX_ApiUsers_NormalizedEmail",
                table: "ApiUsers",
                column: "NormalizedEmail");

            migrationBuilder.CreateIndex(
                name: "IX_ApiUsers_NormalizedUserName",
                table: "ApiUsers",
                column: "NormalizedUserName",
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "ApiAuditLogs");

            migrationBuilder.DropTable(
                name: "ApiFieldPermissions");

            migrationBuilder.DropTable(
                name: "ApiRefreshTokens");

            migrationBuilder.DropTable(
                name: "ApiRolePermissions");

            migrationBuilder.DropTable(
                name: "ApiUserRoles");

            migrationBuilder.DropTable(
                name: "ApiResourceFields");

            migrationBuilder.DropTable(
                name: "ApiPermissions");

            migrationBuilder.DropTable(
                name: "ApiRoles");

            migrationBuilder.DropTable(
                name: "ApiUsers");

            migrationBuilder.DropTable(
                name: "ApiResources");
        }
    }
}
