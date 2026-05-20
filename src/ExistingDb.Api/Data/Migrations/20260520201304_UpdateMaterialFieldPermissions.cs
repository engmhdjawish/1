using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace ExistingDb.Api.Data.Migrations
{
    /// <inheritdoc />
    public partial class UpdateMaterialFieldPermissions : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.UpdateData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 8,
                column: "DisplayName",
                value: "Wholesale SYP price");

            migrationBuilder.UpdateData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 9,
                columns: new[] { "DefaultReadMode", "DisplayName" },
                values: new object[] { "Deny", "Purchase USD price" });

            migrationBuilder.InsertData(
                table: "ApiResourceFields",
                columns: new[] { "Id", "DefaultCanCreate", "DefaultCanUpdate", "DefaultReadMode", "DisplayName", "FieldName", "IsSensitive", "MaskingStrategy", "ResourceId" },
                values: new object[] { 22, false, false, "Mask", "Wholesale USD price", "Half", true, "Full", 2 });
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DeleteData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 22);

            migrationBuilder.UpdateData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 8,
                column: "DisplayName",
                value: "Wholesale price");

            migrationBuilder.UpdateData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 9,
                columns: new[] { "DefaultReadMode", "DisplayName" },
                values: new object[] { "Mask", "Retail price" });
        }
    }
}
