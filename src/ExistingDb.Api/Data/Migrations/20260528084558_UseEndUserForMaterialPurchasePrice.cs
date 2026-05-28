using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace ExistingDb.Api.Data.Migrations
{
    /// <inheritdoc />
    public partial class UseEndUserForMaterialPurchasePrice : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.UpdateData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 9,
                column: "FieldName",
                value: "EndUser");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.UpdateData(
                table: "ApiResourceFields",
                keyColumn: "Id",
                keyValue: 9,
                column: "FieldName",
                value: "Retail");
        }
    }
}
