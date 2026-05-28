using ExistingDb.Api.Data;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace ExistingDb.Api.Data.Migrations;

[DbContext(typeof(ApiManagementDbContext))]
[Migration("20260528223000_RemoveApiManagementMaterialImageTables")]
public partial class RemoveApiManagementMaterialImageTables : Migration
{
    /// <inheritdoc />
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("""
            IF OBJECT_ID(N'[ApiMaterialImageLinks]', N'U') IS NOT NULL
            BEGIN
                DROP TABLE [ApiMaterialImageLinks];
            END
            """);

        migrationBuilder.Sql("""
            IF OBJECT_ID(N'[ApiMaterialImages]', N'U') IS NOT NULL
            BEGIN
                DROP TABLE [ApiMaterialImages];
            END
            """);
    }

    /// <inheritdoc />
    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "ApiMaterialImages",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                Name = table.Column<string>(type: "nvarchar(1000)", maxLength: 1000, nullable: false),
                ThumbnailName = table.Column<string>(type: "nvarchar(1000)", maxLength: 1000, nullable: true),
                OriginalFileName = table.Column<string>(type: "nvarchar(255)", maxLength: 255, nullable: false),
                StoredFileName = table.Column<string>(type: "nvarchar(255)", maxLength: 255, nullable: false),
                ContentType = table.Column<string>(type: "nvarchar(100)", maxLength: 100, nullable: false),
                SizeBytes = table.Column<long>(type: "bigint", nullable: false),
                Width = table.Column<int>(type: "int", nullable: true),
                Height = table.Column<int>(type: "int", nullable: true),
                ThumbnailWidth = table.Column<int>(type: "int", nullable: true),
                ThumbnailHeight = table.Column<int>(type: "int", nullable: true),
                CreatedByUserId = table.Column<Guid>(type: "uniqueidentifier", nullable: true),
                CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false),
                UpdatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: true)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_ApiMaterialImages", x => x.Id);
            });

        migrationBuilder.CreateTable(
            name: "ApiMaterialImageLinks",
            columns: table => new
            {
                ImageId = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                MaterialGuid = table.Column<Guid>(type: "uniqueidentifier", nullable: false),
                IsPrimary = table.Column<bool>(type: "bit", nullable: false),
                CreatedByUserId = table.Column<Guid>(type: "uniqueidentifier", nullable: true),
                CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_ApiMaterialImageLinks", x => new { x.ImageId, x.MaterialGuid });
                table.ForeignKey(
                    name: "FK_ApiMaterialImageLinks_ApiMaterialImages_ImageId",
                    column: x => x.ImageId,
                    principalTable: "ApiMaterialImages",
                    principalColumn: "Id",
                    onDelete: ReferentialAction.Cascade);
            });

        migrationBuilder.CreateIndex(
            name: "IX_ApiMaterialImageLinks_MaterialGuid",
            table: "ApiMaterialImageLinks",
            column: "MaterialGuid");

        migrationBuilder.CreateIndex(
            name: "IX_ApiMaterialImages_CreatedAt",
            table: "ApiMaterialImages",
            column: "CreatedAt");

        migrationBuilder.CreateIndex(
            name: "IX_ApiMaterialImages_Name",
            table: "ApiMaterialImages",
            column: "Name",
            unique: true);
    }
}
