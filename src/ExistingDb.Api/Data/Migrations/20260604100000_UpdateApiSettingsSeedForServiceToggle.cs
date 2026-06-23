using ExistingDb.Api.Data;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace ExistingDb.Api.Data.Migrations;

[DbContext(typeof(ApiManagementDbContext))]
[Migration("20260604100000_UpdateApiSettingsSeedForServiceToggle")]
public partial class UpdateApiSettingsSeedForServiceToggle : Migration
{
    /// <inheritdoc />
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("""
            DELETE FROM [ApiSettings] WHERE [Key] = N'Images:ThumbnailsDirectory';
            """);

        migrationBuilder.Sql("""
            IF NOT EXISTS (SELECT 1 FROM [ApiSettings] WHERE [Key] = N'Service:Enabled')
            BEGIN
                INSERT INTO [ApiSettings] ([Key], [CreatedAt], [Description], [UpdatedAt], [Value])
                VALUES (
                    N'Service:Enabled',
                    '2026-01-01T00:00:00.0000000+00:00',
                    N'When false, the API returns 503 for operational endpoints.',
                    NULL,
                    N'true'
                );
            END
            """);
    }

    /// <inheritdoc />
    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("""
            DELETE FROM [ApiSettings] WHERE [Key] = N'Service:Enabled';
            """);

        migrationBuilder.Sql("""
            IF NOT EXISTS (SELECT 1 FROM [ApiSettings] WHERE [Key] = N'Images:ThumbnailsDirectory')
            BEGIN
                INSERT INTO [ApiSettings] ([Key], [CreatedAt], [Description], [UpdatedAt], [Value])
                VALUES (
                    N'Images:ThumbnailsDirectory',
                    '2026-01-01T00:00:00.0000000+00:00',
                    N'Directory where generated material image thumbnails are saved.',
                    NULL,
                    N'C:\images\thumbnails'
                );
            END
            """);
    }
}
