using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

#pragma warning disable CA1814 // Prefer jagged arrays over multidimensional

namespace ExistingDb.Api.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddMaterialImageManagement : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
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
                name: "ApiSettings",
                columns: table => new
                {
                    Key = table.Column<string>(type: "nvarchar(150)", maxLength: 150, nullable: false),
                    Value = table.Column<string>(type: "nvarchar(1000)", maxLength: 1000, nullable: true),
                    Description = table.Column<string>(type: "nvarchar(500)", maxLength: 500, nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "datetimeoffset", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ApiSettings", x => x.Key);
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

            migrationBuilder.InsertData(
                table: "ApiSettings",
                columns: new[] { "Key", "CreatedAt", "Description", "UpdatedAt", "Value" },
                values: new object[,]
                {
                    { "Images:Directory", new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)), "Directory where original material image files are uploaded.", null, "C:\\images" },
                    { "Images:ThumbnailsDirectory", new DateTimeOffset(new DateTime(2026, 1, 1, 0, 0, 0, 0, DateTimeKind.Unspecified), new TimeSpan(0, 0, 0, 0, 0)), "Directory where generated material image thumbnails are saved.", null, "C:\\images\\thumbnails" }
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

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "ApiMaterialImageLinks");

            migrationBuilder.DropTable(
                name: "ApiSettings");

            migrationBuilder.DropTable(
                name: "ApiMaterialImages");
        }
    }
}
