using System.Data;
using System.Globalization;
using Microsoft.Data.SqlClient;

namespace ExistingDb.Api.Services.Bills;

public interface IReservationBillPatternReader
{
    Task<ReservationBillPattern?> ReadAsync(Guid billTypeGuid, CancellationToken cancellationToken);
}

public sealed class ReservationBillPatternReader(IConfiguration configuration) : IReservationBillPatternReader
{
    private readonly string _connectionString = configuration.GetConnectionString("MainDb")
        ?? throw new InvalidOperationException("ConnectionStrings:MainDb is required.");

    public async Task<ReservationBillPattern?> ReadAsync(Guid billTypeGuid, CancellationToken cancellationToken)
    {
        await using var connection = new SqlConnection(_connectionString);
        await connection.OpenAsync(cancellationToken);
        var row = await ReadRowAsync(connection, billTypeGuid, cancellationToken);
        if (row is null)
        {
            return null;
        }

        var pattern = ReservationBillPatternInterpreter.Interpret(billTypeGuid, row);
        var storeName = await LookupNameAsync(connection, "st000", pattern.StoreGuid, ["Name", "LatinName"], cancellationToken);
        var billCurrencyName = await LookupNameAsync(connection, "my000", pattern.BillCurrencyGuid, ["Name", "AName", "ArabicName", "LatinName"], cancellationToken);
        return pattern with
        {
            StoreName = storeName,
            BillCurrencyName = billCurrencyName
        };
    }

    private static async Task<Dictionary<string, object?>?> ReadRowAsync(
        SqlConnection connection,
        Guid billTypeGuid,
        CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();
        command.CommandText = "SELECT * FROM [bt000] WHERE [GUID] = @id";
        command.Parameters.Add(new SqlParameter("@id", SqlDbType.UniqueIdentifier) { Value = billTypeGuid });
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            return null;
        }

        var row = new Dictionary<string, object?>(reader.FieldCount, StringComparer.OrdinalIgnoreCase);
        for (var index = 0; index < reader.FieldCount; index++)
        {
            row[reader.GetName(index)] = await reader.IsDBNullAsync(index, cancellationToken)
                ? null
                : reader.GetValue(index);
        }

        return row;
    }

    private static async Task<string?> LookupNameAsync(
        SqlConnection connection,
        string tableName,
        Guid? guid,
        IReadOnlyList<string> nameColumns,
        CancellationToken cancellationToken)
    {
        if (guid is null || guid == Guid.Empty || tableName is not ("st000" or "my000"))
        {
            return null;
        }

        try
        {
            await using var command = connection.CreateCommand();
            command.CommandText = $"SELECT * FROM [{tableName}] WHERE [GUID] = @id";
            command.Parameters.Add(new SqlParameter("@id", SqlDbType.UniqueIdentifier) { Value = guid.Value });
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            if (!await reader.ReadAsync(cancellationToken))
            {
                return null;
            }

            foreach (var column in nameColumns)
            {
                var ordinal = TryOrdinal(reader, column);
                if (ordinal is null || await reader.IsDBNullAsync(ordinal.Value, cancellationToken))
                {
                    continue;
                }

                var text = Convert.ToString(reader.GetValue(ordinal.Value), CultureInfo.InvariantCulture)?.Trim();
                if (!string.IsNullOrWhiteSpace(text))
                {
                    return text;
                }
            }
        }
        catch (SqlException)
        {
            return null;
        }

        return null;
    }

    private static int? TryOrdinal(SqlDataReader reader, string column)
    {
        for (var index = 0; index < reader.FieldCount; index++)
        {
            if (string.Equals(reader.GetName(index), column, StringComparison.OrdinalIgnoreCase))
            {
                return index;
            }
        }

        return null;
    }
}
