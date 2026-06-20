using System;
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
        migrationBuilder.DeleteData(
            table: "ApiSettings",
            keyColumn: "Key",
            keyValue: "Images:ThumbnailsDirectory");

        migrationBuilder.InsertData(
            table: "ApiSettings",
            columns: new[] { "Key", "CreatedAt", "Description", "UpdatedAt", "Value" },
            values: new object[]
            {
                "Service:Enabled",
                new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)),
                "When false, the API returns 503 for operational endpoints.",
                null,
                "true",
            });
    }

    /// <inheritdoc />
    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.DeleteData(
            table: "ApiSettings",
            keyColumn: "Key",
            keyValue: "Service:Enabled");

        migrationBuilder.InsertData(
            table: "ApiSettings",
            columns: new[] { "Key", "CreatedAt", "Description", "UpdatedAt", "Value" },
            values: new object[]
            {
                "Images:ThumbnailsDirectory",
                new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)),
                "Directory where generated material image thumbnails are saved.",
                null,
                "C:\\images\\thumbnails",
            });
    }
}
