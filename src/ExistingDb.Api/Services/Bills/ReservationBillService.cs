using System.Data;
using System.Globalization;
using ExistingDb.Api.Contracts.Bills;
using Microsoft.Data.SqlClient;

namespace ExistingDb.Api.Services.Bills;

public interface IReservationBillService
{
    Task<ReservationBillExecution> CreateAsync(ReservationBillWriteRequest? request, CancellationToken cancellationToken);

    Task<ReservationBillExecution> UpdateAsync(Guid billGuid, ReservationBillWriteRequest? request, CancellationToken cancellationToken);

    Task<ReservationBillExecution> DeleteAsync(Guid billGuid, CancellationToken cancellationToken);
}

public sealed record ReservationBillExecution(ReservationBillDecision Decision, ReservationBillResponse? Response);

/// <summary>
/// Creates, replaces, and deletes فاتورة حجز documents.
/// IgnoreWarnings is set only on the open connection and cleared in a finally block
/// before that connection can return to the pool. The transaction commits only after
/// the guard accepts the measured inventory and ledger state.
/// Level-1 errors (severity 11 and above, including AmnE0001) abort because XACT_ABORT is on.
/// </summary>
public sealed record ReservationBillRuntime(
    Guid BillTypeGuid,
    Guid StoreGuid,
    Guid BillCurrencyGuid,
    Guid PriceCurrencyGuid,
    double CurrencyVal,
    double PriceCurrencyVal);

public sealed class ReservationBillService(
    IReservationBillSettingsService settingsService,
    IReservationBillPatternReader patternReader,
    IConfiguration configuration,
    ILogger<ReservationBillService> logger) : IReservationBillService
{
    private readonly string _connectionString = configuration.GetConnectionString("MainDb")
        ?? throw new InvalidOperationException("ConnectionStrings:MainDb is required.");

    public Task<ReservationBillExecution> CreateAsync(
        ReservationBillWriteRequest? request,
        CancellationToken cancellationToken)
    {
        var validation = ReservationBillGuard.ValidateWrite(request?.OwnerName, request?.Lines, out var draft);
        if (!validation.Succeeded || draft is null)
        {
            return Task.FromResult(new ReservationBillExecution(validation, null));
        }

        var billGuid = Guid.NewGuid();
        return ExecuteAsync(
            billGuid,
            draft,
            previousLines: [],
            knownNumber: null,
            mutateSql: ReservationBillSql.BuildCreateScript(draft.Lines.Count),
            observationSql: ReservationBillSql.BuildCreateObservationScript(),
            operation: ReservationOperation.Create,
            cancellationToken);
    }

    public async Task<ReservationBillExecution> UpdateAsync(
        Guid billGuid,
        ReservationBillWriteRequest? request,
        CancellationToken cancellationToken)
    {
        if (billGuid == Guid.Empty)
        {
            return new ReservationBillExecution(
                ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Reservation bill id is required."),
                null);
        }

        var validation = ReservationBillGuard.ValidateWrite(request?.OwnerName, request?.Lines, out var draft);
        if (!validation.Succeeded || draft is null)
        {
            return new ReservationBillExecution(validation, null);
        }

        return await ExecuteAsync(
            billGuid,
            draft,
            previousLines: null,
            knownNumber: null,
            mutateSql: ReservationBillSql.BuildUpdateScript(draft.Lines.Count),
            observationSql: ReservationBillSql.BuildUpdateObservationScript(),
            operation: ReservationOperation.Update,
            cancellationToken);
    }

    public Task<ReservationBillExecution> DeleteAsync(Guid billGuid, CancellationToken cancellationToken)
    {
        if (billGuid == Guid.Empty)
        {
            return Task.FromResult(new ReservationBillExecution(
                ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Reservation bill id is required."),
                null));
        }

        return ExecuteAsync(
            billGuid,
            draft: null,
            previousLines: null,
            knownNumber: null,
            mutateSql: ReservationBillSql.BuildDeleteScript(),
            observationSql: ReservationBillSql.BuildDeleteObservationScript(),
            operation: ReservationOperation.Delete,
            cancellationToken);
    }

    private async Task<ReservationBillExecution> ExecuteAsync(
        Guid billGuid,
        ReservationBillDraft? draft,
        IReadOnlyList<ReservationBillLine>? previousLines,
        int? knownNumber,
        string mutateSql,
        string observationSql,
        ReservationOperation operation,
        CancellationToken cancellationToken)
    {
        var runtimeResult = await ResolveRuntimeAsync(cancellationToken);
        if (runtimeResult.Decision is not null || runtimeResult.Runtime is null)
        {
            return new ReservationBillExecution(
                runtimeResult.Decision ?? ReservationBillDecision.Fail(ReservationBillFailure.Validation, "Reservation bill pattern is not configured."),
                null);
        }

        var runtime = runtimeResult.Runtime;
        ReservationBillSqlSafety.EnsureSafe(mutateSql);
        ReservationBillSqlSafety.EnsureSafe(observationSql);
        ReservationBillSqlSafety.EnsureSafe(ReservationBillSql.EnableAbortOnError);
        var resetSql = ReservationBillSql.BuildResetIgnoreWarnings();
        ReservationBillSqlSafety.EnsureSafe(resetSql);

        SqlConnection? connection = null;
        var ignoreWarningsArmed = false;
        try
        {
            connection = new SqlConnection(_connectionString);
            await connection.OpenAsync(cancellationToken);
            await ExecuteNonQueryAsync(connection, transaction: null, ReservationBillSql.EnableAbortOnError, bind: null, cancellationToken);

            await using var transaction = (SqlTransaction)await connection.BeginTransactionAsync(cancellationToken);
            try
            {
                if (operation != ReservationOperation.Create)
                {
                    var ownership = await ReadOwnershipAsync(connection, transaction, billGuid, cancellationToken);
                    var ownershipDecision = ReservationBillGuard.EvaluateOwnership(ownership, runtime.BillTypeGuid, runtime.StoreGuid);
                    if (!ownershipDecision.Succeeded)
                    {
                        await RollbackAsync(transaction, cancellationToken);
                        return new ReservationBillExecution(ownershipDecision, null);
                    }

                    previousLines = ownership.Lines;
                    knownNumber = ownership.Number;
                }

                await ExecuteNonQueryAsync(
                    connection,
                    transaction,
                    mutateSql,
                    command => BindMutation(command, billGuid, draft, runtime),
                    cancellationToken);
                ignoreWarningsArmed = true;

                var observation = await ReadObservationAsync(connection, transaction, observationSql, billGuid, cancellationToken);
                var decision = Decide(operation, draft, previousLines ?? [], observation, runtime);
                if (!decision.Succeeded)
                {
                    await RollbackAsync(transaction, cancellationToken);
                    logger.LogWarning(
                        "Rolled back reservation bill {BillGuid} because {Reason}",
                        billGuid,
                        decision.Message);
                    return new ReservationBillExecution(decision, null);
                }

                var response = BuildResponse(operation, billGuid, knownNumber, draft, previousLines ?? [], observation, runtime);
                await transaction.CommitAsync(cancellationToken);
                return new ReservationBillExecution(ReservationBillDecision.Success, response);
            }
            catch (Exception exception)
            {
                await RollbackAsync(transaction, cancellationToken);
                if (exception is SqlException sqlException)
                {
                    logger.LogWarning(sqlException, "Reservation bill {BillGuid} was rolled back after a SQL error.", billGuid);
                    return new ReservationBillExecution(
                        ReservationBillDecision.Fail(
                            ReservationBillFailure.Database,
                            $"The reservation bill was not saved. {sqlException.Message}"),
                        null);
                }

                throw;
            }
        }
        finally
        {
            if (connection is not null)
            {
                if (connection.State == ConnectionState.Open)
                {
                    try
                    {
                        await ExecuteNonQueryAsync(connection, transaction: null, resetSql, bind: null, cancellationToken);
                    }
                    catch (Exception exception)
                    {
                        logger.LogError(exception, "Failed to clear IgnoreWarnings before returning the SQL connection.");
                        SqlConnection.ClearPool(connection);
                    }
                }

                await connection.DisposeAsync();
            }

            if (ignoreWarningsArmed)
            {
                logger.LogDebug("Cleared IgnoreWarnings for reservation bill {BillGuid}.", billGuid);
            }
        }
    }

    private ReservationBillDecision Decide(
        ReservationOperation operation,
        ReservationBillDraft? draft,
        IReadOnlyList<ReservationBillLine> previousLines,
        ReservationObservation observation,
        ReservationBillRuntime runtime)
    {
        var nextLines = draft?.Lines ?? [];
        var scopedMaterials = previousLines.Select(line => line.MaterialGuid)
            .Concat(nextLines.Select(line => line.MaterialGuid))
            .Distinct()
            .ToArray();

        ReservationBillDecision inventoryDecision;
        if (operation == ReservationOperation.Update)
        {
            var unpostExpected = ReservationBillGuard.WithZeroFill(
                ReservationBillGuard.RestoreDeltas(previousLines),
                scopedMaterials);
            var postExpected = ReservationBillGuard.WithZeroFill(
                ReservationBillGuard.ReserveDeltas(nextLines),
                scopedMaterials);
            inventoryDecision = FirstFailure(
                ReservationBillGuard.EvaluateInventory(
                    runtime.StoreGuid,
                    unpostExpected,
                    observation.Phases.GetValueOrDefault("unpost") ?? []),
                ReservationBillGuard.EvaluateInventory(
                    runtime.StoreGuid,
                    postExpected,
                    observation.Phases.GetValueOrDefault("post") ?? []));
        }
        else
        {
            var expected = operation == ReservationOperation.Delete
                ? ReservationBillGuard.RestoreDeltas(previousLines)
                : ReservationBillGuard.ReserveDeltas(nextLines);
            inventoryDecision = ReservationBillGuard.EvaluateInventory(
                runtime.StoreGuid,
                expected,
                observation.Phases.GetValueOrDefault("change") ?? []);
        }

        var billDecision = operation == ReservationOperation.Delete
            ? ReservationBillGuard.EvaluateDeletedBill(observation.State)
            : ReservationBillGuard.EvaluatePostedBill(observation.State, runtime.BillTypeGuid, runtime.StoreGuid);

        return FirstFailure(billDecision, inventoryDecision);
    }

    private ReservationBillResponse BuildResponse(
        ReservationOperation operation,
        Guid billGuid,
        int? knownNumber,
        ReservationBillDraft? draft,
        IReadOnlyList<ReservationBillLine> previousLines,
        ReservationObservation observation,
        ReservationBillRuntime runtime)
    {
        var number = operation == ReservationOperation.Delete
            ? knownNumber
            : observation.State.Number;
        if (number is null)
        {
            throw new InvalidOperationException("Reservation bill number was missing after the guard passed.");
        }

        var finalPhase = operation switch
        {
            ReservationOperation.Update => "post",
            _ => "change"
        };
        var changes = observation.Phases.GetValueOrDefault(finalPhase) ?? [];
        var materials = (draft?.Lines ?? previousLines)
            .Select(line => line.MaterialGuid)
            .Concat(previousLines.Select(line => line.MaterialGuid))
            .Distinct();
        var quantities = materials
            .Select(materialGuid => new ReservationStoreQuantityResponse(
                materialGuid,
                QuantityAfter(changes, materialGuid, runtime.StoreGuid)))
            .ToArray();
        return new ReservationBillResponse(billGuid, number.Value, quantities);
    }

    private async Task<(ReservationBillRuntime? Runtime, ReservationBillDecision? Decision)> ResolveRuntimeAsync(
        CancellationToken cancellationToken)
    {
        var settings = await settingsService.GetAsync(cancellationToken);
        if (settings.BillTypeGuid is null || settings.BillTypeGuid == Guid.Empty)
        {
            return (null, ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                "Select a reservation bill type in the portal settings before syncing orders."));
        }

        var pattern = await patternReader.ReadAsync(settings.BillTypeGuid.Value, cancellationToken);
        if (pattern is null)
        {
            return (null, ReservationBillDecision.Fail(
                ReservationBillFailure.Validation,
                "The selected bill type was not found."));
        }

        var decision = ReservationBillGuard.EvaluatePatternForSync(pattern);
        if (!decision.Succeeded)
        {
            return (null, decision);
        }

        return (new ReservationBillRuntime(
            pattern.BillTypeGuid,
            pattern.StoreGuid!.Value,
            pattern.BillCurrencyGuid!.Value,
            pattern.PriceCurrencyGuid!.Value,
            pattern.CurrencyVal,
            pattern.PriceCurrencyVal), null);
    }

    private static void BindMutation(
        SqlCommand command,
        Guid billGuid,
        ReservationBillDraft? draft,
        ReservationBillRuntime runtime)
    {
        AddGuid(command, "@BillGuid", billGuid);
        AddGuid(command, "@TypeGuid", runtime.BillTypeGuid);
        AddGuid(command, "@StoreGuid", runtime.StoreGuid);
        AddGuid(command, "@BillCurrencyGuid", runtime.BillCurrencyGuid);
        AddGuid(command, "@PriceCurrencyGuid", runtime.PriceCurrencyGuid);
        AddFloat(command, "@CurrencyVal", runtime.CurrencyVal);
        AddFloat(command, "@PriceCurrencyVal", runtime.PriceCurrencyVal);
        if (draft is null)
        {
            return;
        }

        AddString(command, "@OwnerName", draft.OwnerName);
        AddFloat(command, "@LineTotal", draft.Total);
        AddFloat(command, "@TotalQty", draft.Lines.Sum(line => line.Quantity));
        for (var index = 0; index < draft.Lines.Count; index++)
        {
            var line = draft.Lines[index];
            var prefix = ReservationBillSql.LineParameterPrefix(index);
            AddFloat(command, prefix + "Number", index);
            AddFloat(command, prefix + "Qty", line.Quantity);
            AddFloat(command, prefix + "Price", line.UnitPrice);
            AddGuid(command, prefix + "Guid", Guid.NewGuid());
            AddGuid(command, prefix + "Mat", line.MaterialGuid);
        }
    }

    private static async Task<ReservationBillOwnership> ReadOwnershipAsync(
        SqlConnection connection,
        SqlTransaction transaction,
        Guid billGuid,
        CancellationToken cancellationToken)
    {
        var sql = ReservationBillSql.BuildOwnershipScript();
        ReservationBillSqlSafety.EnsureSafe(sql);
        await using var command = CreateCommand(connection, transaction, sql);
        AddGuid(command, "@BillGuid", billGuid);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            return new ReservationBillOwnership(false, null, null, false, null, 0, 0, 0, []);
        }

        var nullMaterialLines = ReadInt(reader, "NullMaterialLines");
        var ownership = new ReservationBillOwnership(
            Exists: true,
            TypeGuid: ReadGuid(reader, "TypeGUID"),
            StoreGuid: ReadGuid(reader, "StoreGUID"),
            IsPosted: ReadBool(reader, "IsPosted"),
            Number: ReadNumber(reader, "Number"),
            EntryCount: ReadInt(reader, "EntryCount"),
            PaymentCount: ReadInt(reader, "PaymentCount"),
            NullMaterialLineCount: nullMaterialLines,
            Lines: []);
        var lines = new List<ReservationBillLine>();
        if (await reader.NextResultAsync(cancellationToken))
        {
            while (await reader.ReadAsync(cancellationToken))
            {
                var materialGuid = ReadGuid(reader, "MatGUID");
                var quantity = ReadDouble(reader, "Qty");
                if (materialGuid.HasValue)
                {
                    lines.Add(new ReservationBillLine(materialGuid.Value, quantity ?? 0d, 0d));
                }
            }
        }

        return ownership with { Lines = lines };
    }

    private static async Task<ReservationObservation> ReadObservationAsync(
        SqlConnection connection,
        SqlTransaction transaction,
        string sql,
        Guid billGuid,
        CancellationToken cancellationToken)
    {
        await using var command = CreateCommand(connection, transaction, sql);
        AddGuid(command, "@BillGuid", billGuid);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            throw new InvalidOperationException("Reservation observation did not return a bill state.");
        }

        var state = new ReservationBillState(
            Exists: ReadInt(reader, "BillExists") != 0,
            TypeGuid: ReadGuid(reader, "TypeGuid"),
            StoreGuid: ReadGuid(reader, "StoreGuid"),
            IsPosted: ReadBool(reader, "IsPosted"),
            Number: ReadNumber(reader, "BillNumber"),
            EntryCount: ReadInt(reader, "EntryCount"),
            PaymentCount: ReadInt(reader, "PaymentCount"));

        var phases = new Dictionary<string, List<MaterialStoreQuantityChange>>(StringComparer.OrdinalIgnoreCase);
        while (await reader.NextResultAsync(cancellationToken))
        {
            while (await reader.ReadAsync(cancellationToken))
            {
                var phase = ReadString(reader, "Phase") ?? string.Empty;
                var materialGuid = ReadGuid(reader, "MatGUID");
                if (!materialGuid.HasValue || phase.Length == 0)
                {
                    continue;
                }

                if (!phases.TryGetValue(phase, out var changes))
                {
                    changes = [];
                    phases.Add(phase, changes);
                }

                changes.Add(new MaterialStoreQuantityChange(
                    materialGuid.Value,
                    ReadGuid(reader, "StoreGUID"),
                    ReadDouble(reader, "QtyBefore") ?? 0d,
                    ReadDouble(reader, "QtyAfter") ?? 0d,
                    ReadInt(reader, "ExistsAfter") != 0));
            }
        }

        return new ReservationObservation(
            state,
            phases.ToDictionary(
                pair => pair.Key,
                pair => (IReadOnlyList<MaterialStoreQuantityChange>)pair.Value,
                StringComparer.OrdinalIgnoreCase));
    }

    private static async Task ExecuteNonQueryAsync(
        SqlConnection connection,
        SqlTransaction? transaction,
        string sql,
        Action<SqlCommand>? bind,
        CancellationToken cancellationToken)
    {
        await using var command = CreateCommand(connection, transaction, sql);
        bind?.Invoke(command);
        await command.ExecuteNonQueryAsync(cancellationToken);
    }

    private static SqlCommand CreateCommand(SqlConnection connection, SqlTransaction? transaction, string sql)
    {
        var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = sql;
        command.CommandType = CommandType.Text;
        command.CommandTimeout = 120;
        return command;
    }

    private static async Task RollbackAsync(SqlTransaction transaction, CancellationToken cancellationToken)
    {
        try
        {
            if (transaction.Connection is not null)
            {
                await transaction.RollbackAsync(cancellationToken);
            }
        }
        catch (InvalidOperationException)
        {
            // XACT_ABORT already aborted the transaction.
        }
    }

    private static ReservationBillDecision FirstFailure(params ReservationBillDecision[] decisions) =>
        decisions.FirstOrDefault(decision => !decision.Succeeded) ?? ReservationBillDecision.Success;

    private static double QuantityAfter(
        IReadOnlyList<MaterialStoreQuantityChange> changes,
        Guid materialGuid,
        Guid storeGuid)
    {
        var rows = changes
            .Where(change => change.MaterialGuid == materialGuid && change.StoreGuid == storeGuid && change.ExistsAfter)
            .ToArray();
        return rows.Length == 0 ? 0d : rows.Sum(change => change.QuantityAfter);
    }

    private static void AddGuid(SqlCommand command, string name, Guid value) =>
        command.Parameters.Add(new SqlParameter(name, SqlDbType.UniqueIdentifier) { Value = value });

    private static void AddFloat(SqlCommand command, string name, double value) =>
        command.Parameters.Add(new SqlParameter(name, SqlDbType.Float) { Value = value });

    private static void AddString(SqlCommand command, string name, string value) =>
        command.Parameters.Add(new SqlParameter(name, SqlDbType.NVarChar, 1000) { Value = value });

    private static Guid? ReadGuid(SqlDataReader reader, string name)
    {
        var value = reader[name];
        return value switch
        {
            Guid guid => guid,
            string text when Guid.TryParse(text, out var parsed) => parsed,
            _ => null
        };
    }

    private static bool ReadBool(SqlDataReader reader, string name)
    {
        var value = reader[name];
        return value switch
        {
            bool bit => bit,
            byte or short or int or long => Convert.ToInt64(value, CultureInfo.InvariantCulture) != 0,
            _ => false
        };
    }

    private static int ReadInt(SqlDataReader reader, string name)
    {
        var value = reader[name];
        return value is null or DBNull ? 0 : Convert.ToInt32(value, CultureInfo.InvariantCulture);
    }

    private static int? ReadNumber(SqlDataReader reader, string name)
    {
        var value = reader[name];
        if (value is null or DBNull)
        {
            return null;
        }

        var number = Convert.ToDecimal(value, CultureInfo.InvariantCulture);
        var rounded = decimal.Round(number, 0, MidpointRounding.AwayFromZero);
        if (Math.Abs(number - rounded) > 0.001m || rounded > int.MaxValue || rounded < int.MinValue)
        {
            return null;
        }

        return (int)rounded;
    }

    private static double? ReadDouble(SqlDataReader reader, string name)
    {
        var value = reader[name];
        return value is null or DBNull ? null : Convert.ToDouble(value, CultureInfo.InvariantCulture);
    }

    private static string? ReadString(SqlDataReader reader, string name)
    {
        var value = reader[name];
        return value is null or DBNull ? null : Convert.ToString(value, CultureInfo.InvariantCulture);
    }

    private sealed record ReservationObservation(
        ReservationBillState State,
        IReadOnlyDictionary<string, IReadOnlyList<MaterialStoreQuantityChange>> Phases);

    private enum ReservationOperation
    {
        Create = 0,
        Update = 1,
        Delete = 2
    }
}
