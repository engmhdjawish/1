using ExistingDb.Api.Data;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace ExistingDb.Api.Data.Migrations;

[DbContext(typeof(ApiManagementDbContext))]
[Migration("20261017120000_AddReservationBillPermission")]
public partial class AddReservationBillPermission : Migration
{
    /// <inheritdoc />
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("""
            IF NOT EXISTS (SELECT 1 FROM [ApiPermissions] WHERE [Code] = N'bills.reserve')
            BEGIN
                SET IDENTITY_INSERT [ApiPermissions] ON;
                INSERT INTO [ApiPermissions] ([Id], [Code], [Name], [Description], [Category])
                VALUES (
                    16,
                    N'bills.reserve',
                    N'Create and maintain reservation bills',
                    N'Create, update, and delete reservation invoices without customer accounting entries.',
                    N'bills');
                SET IDENTITY_INSERT [ApiPermissions] OFF;
            END

            IF NOT EXISTS (
                SELECT 1
                FROM [ApiRolePermissions] AS rolePermission
                INNER JOIN [ApiPermissions] AS permission ON permission.[Id] = rolePermission.[PermissionId]
                WHERE rolePermission.[RoleId] = 1
                  AND permission.[Code] = N'bills.reserve')
            BEGIN
                INSERT INTO [ApiRolePermissions] ([RoleId], [PermissionId])
                SELECT 1, [Id]
                FROM [ApiPermissions]
                WHERE [Code] = N'bills.reserve';
            END
            """);
    }

    /// <inheritdoc />
    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("""
            DELETE rolePermission
            FROM [ApiRolePermissions] AS rolePermission
            INNER JOIN [ApiPermissions] AS permission ON permission.[Id] = rolePermission.[PermissionId]
            WHERE permission.[Code] = N'bills.reserve';

            DELETE FROM [ApiPermissions] WHERE [Code] = N'bills.reserve';
            """);
    }
}
