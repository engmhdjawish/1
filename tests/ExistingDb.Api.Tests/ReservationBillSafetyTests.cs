using System.Reflection;
using System.Text.RegularExpressions;
using ExistingDb.Api.Contracts.Bills;
using ExistingDb.Api.Services.Bills;
using Xunit;

namespace ExistingDb.Api.Tests;

public sealed class ReservationBillSafetyTests
{
    private static readonly Guid ReservationType = Guid.Parse("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa");
    private static readonly Guid ReservationStore = Guid.Parse("bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb");
    private static readonly Guid BillCurrency = Guid.Parse("cccccccc-cccc-cccc-cccc-cccccccccccc");
    private static readonly Guid PriceList = Guid.Parse("dddddddd-dddd-dddd-dddd-dddddddddddd");
    private static readonly Guid Branch = Guid.Parse("eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee");
    private static readonly Guid OtherStore = Guid.Parse("11111111-1111-1111-1111-111111111111");
    private static readonly Guid Material = Guid.Parse("22222222-2222-2222-2222-222222222222");
    private static readonly Guid OtherType = Guid.Parse("33333333-3333-3333-3333-333333333333");

    private static readonly string[] ProcedureParameters =
    [
        "@Number", "@Qty", "@Order", "@OrderQnt", "@Unity", "@Price", "@BonusQnt", "@Discount",
        "@BonusDisc", "@Extra", "@CurrencyVal", "@Notes", "@Profits", "@Num1", "@Num2", "@Qty2",
        "@Qty3", "@ClassPtr", "@ExpireDate", "@ProductionDate", "@Length", "@Width", "@Height",
        "@GUID", "@VAT", "@VATRatio", "@ParentGUID", "@MatGUID", "@CurrencyGUID", "@StoreGUID",
        "@CostGUID", "@SOType", "@SOGuid", "@Count", "@SOGroup", "@TotalDiscountPercent",
        "@TotalExtraPercent", "@ContractItemGuid", "@ContractDiscount", "@ClassPrice",
        "@IsDiscountValue", "@IsExtraValue", "@TaxCode", "@ExciseTaxVal", "@PurchaseVal",
        "@ReversChargeVal", "@ExciseTaxPercent", "@ExciseTaxCode", "@RelatedTo", "@CustomsRate",
        "@OrginalTaxCode", "@NetProfit", "@NetProfitAfterTax", "@SalesTax", "@ReversChargeTaxRatio",
        "@Barcode", "@DiscAfterTaxValue", "@EDeclarationState", "@ProfitMargin"
    ];

    [Fact]
    public void Pattern_reads_the_exact_bt000_columns()
    {
        var row = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
        {
            ["Name"] = "فاتورة حجز",
            ["StoreGUID"] = OtherStore,
            ["CurrencyGUID"] = Guid.Parse("ffffffff-ffff-ffff-ffff-ffffffffffff"),
            ["bGenEntry"] = 1,
            ["bNoEntry"] = 1,
            ["bAutoEntry"] = 1,
            ["bAutoEntryPost"] = 0,
            ["bFixStore"] = 1,
            ["DefStoreGUID"] = ReservationStore,
            ["bFixCurrency"] = 1,
            ["DefCurrencyGUID"] = BillCurrency,
            ["DefPrice"] = 3,
            ["MaterialPriceListGUID"] = PriceList,
            ["bFixBranch"] = 1,
            ["DefBranchGUID"] = Branch
        };

        var pattern = ReservationBillPatternInterpreter.Interpret(ReservationType, row);
        var presented = ReservationBillPatternPresenter.Present(pattern, ReservationOwnerNameSources.OrderCustomerName);

        Assert.Equal(ReservationStore, pattern.StoreGuid);
        Assert.Equal(BillCurrency, pattern.BillCurrencyGuid);
        Assert.True(pattern.NoEntry);
        Assert.True(pattern.AutoEntry);
        Assert.Equal(3, pattern.DefPrice);
        Assert.Equal(PriceList, pattern.MaterialPriceListGuid);
        Assert.Equal(Branch, pattern.BranchGuid);
        Assert.True(ReservationBillGuard.EvaluatePatternForSync(pattern).Succeeded);
        Assert.Contains(presented.Locked, property => property.Key == "DefPrice" && property.Locked);
        Assert.Contains(presented.Locked, property => property.Key == "MaterialPriceListGUID" && property.Locked);
        Assert.Contains(presented.Locked, property => property.Key == "bFixBranch" && property.Locked);
        Assert.DoesNotContain(presented.Locked, property => property.Key is "priceCurrency" or "MatCurrencyGUID");
    }

    [Fact]
    public void Pattern_refuses_sync_unless_entries_are_off_store_is_fixed_and_currency_guid_is_present()
    {
        var missing = ReservationBillPatternInterpreter.Interpret(ReservationType, new Dictionary<string, object?>());
        Assert.Null(missing.StoreGuid);
        Assert.Null(missing.BillCurrencyGuid);
        Assert.Contains("bNoEntry", missing.MissingColumns);
        Assert.Contains("DefStoreGUID", missing.MissingColumns);
        Assert.Contains("DefCurrencyGUID", missing.MissingColumns);
        Assert.DoesNotContain("bFixBranch", missing.MissingColumns);
        Assert.False(ReservationBillGuard.EvaluatePatternForSync(missing).Succeeded);

        var generates = ReadyPattern(bNoEntry: 0);
        var generatesDecision = ReservationBillGuard.EvaluatePatternForSync(generates);
        Assert.Equal(ReservationBillFailure.NotReservation, generatesDecision.Failure);
        Assert.Contains("bNoEntry", generatesDecision.Message, StringComparison.OrdinalIgnoreCase);

        Assert.False(ReservationBillGuard.EvaluatePatternForSync(ReadyPattern(bFixStore: 0)).Succeeded);
        Assert.False(ReservationBillGuard.EvaluatePatternForSync(ReadyPattern(storeGuid: Guid.Empty)).Succeeded);
        var missingCurrency = ReservationBillGuard.EvaluatePatternForSync(ReadyPattern(currencyGuid: Guid.Empty));
        Assert.False(missingCurrency.Succeeded);
        Assert.Contains("has no DefCurrencyGUID", missingCurrency.Message, StringComparison.Ordinal);
        Assert.DoesNotContain("lock", missingCurrency.Message, StringComparison.OrdinalIgnoreCase);
        Assert.True(ReservationBillGuard.EvaluatePatternForSync(ReadyPattern(bAutoEntry: 1)).Succeeded);
    }

    [Fact]
    public void Unlocked_currency_still_uses_DefCurrencyGUID()
    {
        var pattern = ReadyPattern(bFixCurrency: 0);
        var decision = ReservationBillGuard.EvaluatePatternForSync(pattern);
        var presented = ReservationBillPatternPresenter.Present(pattern, ReservationOwnerNameSources.OrderCustomerName);

        Assert.True(decision.Succeeded);
        Assert.True(pattern.SyncAllowed);
        Assert.False(pattern.FixCurrency);
        Assert.Equal(BillCurrency, pattern.BillCurrencyGuid);
        Assert.True(presented.SyncAllowed);
        Assert.Contains(
            presented.Locked,
            property => property.Key == "bFixCurrency" && property.Locked && property.Value == "false");
        Assert.Contains(
            presented.Locked,
            property => property.Key == "DefCurrencyGUID"
                && property.Locked
                && property.Value == BillCurrency.ToString("D"));
    }

    [Fact]
    public void Saved_settings_request_accepts_the_type_owner_name_source_and_sync_mode()
    {
        var properties = typeof(SaveReservationBillSettingsRequest).GetProperties().Select(property => property.Name).ToArray();
        Assert.Equal(["BillTypeGuid", "OwnerNameSource", "SyncMode"], properties);
        Assert.True(ReservationOwnerNameSources.IsAllowed("orderCustomerName"));
        Assert.False(ReservationOwnerNameSources.IsAllowed("storeGuid"));
        Assert.True(ReservationSyncModes.IsAllowed("automatic"));
        Assert.True(ReservationSyncModes.IsAllowed("manual"));
        Assert.False(ReservationSyncModes.IsAllowed("live"));
        Assert.Equal(ReservationSyncModes.Manual, ReservationSyncModes.Normalize(null));
        Assert.Equal(ReservationSyncModes.Manual, ReservationSyncModes.Normalize(" "));
        Assert.Equal(ReservationSyncModes.Manual, ReservationSyncModes.Normalize("unexpected"));
        Assert.Equal(ReservationSyncModes.Automatic, ReservationSyncModes.Normalize("automatic"));
    }

    [Fact]
    public void Repo_does_not_pin_the_live_reservation_guids()
    {
        var root = RepoRoot();
        var files = new[]
        {
            "src/ExistingDb.Api/appsettings.json",
            "src/ExistingDb.Api/appsettings.Development.json",
            "portal/views/dashboard/settings.php",
            "portal/public/dashboard/settings.php"
        };
        foreach (var relative in files)
        {
            var text = File.ReadAllText(Path.Combine(root, relative));
            Assert.DoesNotContain("09A285A8-21AD-480E-914F-C53E0FEE5251", text, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("2543E24D-3213-46D6-8B9C-4A2F641A65E9", text, StringComparison.OrdinalIgnoreCase);
        }
    }

    [Fact]
    public void Ownership_rejects_a_bill_that_is_not_the_reservation_type()
    {
        var decision = ReservationBillGuard.EvaluateOwnership(PostedBill(typeGuid: OtherType), ReservationType, ReservationStore);

        Assert.False(decision.Succeeded);
        Assert.Equal(ReservationBillFailure.NotReservation, decision.Failure);
    }

    [Fact]
    public void Ownership_rejects_a_bill_outside_the_reservation_store()
    {
        var decision = ReservationBillGuard.EvaluateOwnership(PostedBill(storeGuid: OtherStore), ReservationType, ReservationStore);

        Assert.False(decision.Succeeded);
        Assert.Equal(ReservationBillFailure.NotReservation, decision.Failure);
    }

    [Fact]
    public void Ownership_rejects_accounting_entries_and_payments()
    {
        var entries = ReservationBillGuard.EvaluateOwnership(PostedBill(entryCount: 1), ReservationType, ReservationStore);
        var payments = ReservationBillGuard.EvaluateOwnership(PostedBill(paymentCount: 2), ReservationType, ReservationStore);

        Assert.Equal(ReservationBillFailure.HasLedgerRows, entries.Failure);
        Assert.Equal(ReservationBillFailure.HasLedgerRows, payments.Failure);
    }

    [Fact]
    public void Ownership_rejects_an_unposted_or_missing_bill()
    {
        var unposted = ReservationBillGuard.EvaluateOwnership(PostedBill(isPosted: false), ReservationType, ReservationStore);
        var missing = ReservationBillGuard.EvaluateOwnership(
            new ReservationBillOwnership(false, null, null, false, null, 0, 0, 0, []),
            ReservationType,
            ReservationStore);

        Assert.Equal(ReservationBillFailure.NotReservation, unposted.Failure);
        Assert.Equal(ReservationBillFailure.NotFound, missing.Failure);
    }

    [Fact]
    public void Ownership_accepts_a_posted_reservation_bill_without_ledger_rows()
    {
        var decision = ReservationBillGuard.EvaluateOwnership(PostedBill(), ReservationType, ReservationStore);

        Assert.True(decision.Succeeded);
    }

    [Fact]
    public void Post_assert_rejects_entries_payments_and_a_changed_identity()
    {
        Assert.Equal(
            ReservationBillFailure.AssertFailed,
            ReservationBillGuard.EvaluatePostedBill(State(entryCount: 1), ReservationType, ReservationStore).Failure);
        Assert.Equal(
            ReservationBillFailure.AssertFailed,
            ReservationBillGuard.EvaluatePostedBill(State(paymentCount: 1), ReservationType, ReservationStore).Failure);
        Assert.Equal(
            ReservationBillFailure.AssertFailed,
            ReservationBillGuard.EvaluatePostedBill(State(typeGuid: OtherType), ReservationType, ReservationStore).Failure);
        Assert.Equal(
            ReservationBillFailure.AssertFailed,
            ReservationBillGuard.EvaluatePostedBill(State(storeGuid: OtherStore), ReservationType, ReservationStore).Failure);
        Assert.Equal(
            ReservationBillFailure.AssertFailed,
            ReservationBillGuard.EvaluatePostedBill(State(isPosted: false), ReservationType, ReservationStore).Failure);
    }

    [Fact]
    public void Inventory_assert_rejects_a_reservation_delta_other_than_the_reserved_quantity()
    {
        var expected = ReservationBillGuard.ReserveDeltas([Line(Material, 2)]);
        var changes = new[]
        {
            Change(Material, ReservationStore, before: -12, after: -13)
        };

        var decision = ReservationBillGuard.EvaluateInventory(ReservationStore, expected, changes);

        Assert.Equal(ReservationBillFailure.AssertFailed, decision.Failure);
    }

    [Fact]
    public void Inventory_assert_rejects_when_another_store_changes()
    {
        var expected = ReservationBillGuard.ReserveDeltas([Line(Material, 2)]);
        var changes = new[]
        {
            Change(Material, ReservationStore, before: -12, after: -14),
            Change(Material, OtherStore, before: 5, after: 4)
        };

        var decision = ReservationBillGuard.EvaluateInventory(ReservationStore, expected, changes);

        Assert.Equal(ReservationBillFailure.AssertFailed, decision.Failure);
        Assert.Contains("non-reservation", decision.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Inventory_assert_rejects_a_missing_reservation_store_row_when_quantity_must_change()
    {
        var expected = ReservationBillGuard.ReserveDeltas([Line(Material, 1)]);
        var changes = new[]
        {
            new MaterialStoreQuantityChange(Material, ReservationStore, 1, 0, ExistsAfter: false)
        };

        var decision = ReservationBillGuard.EvaluateInventory(ReservationStore, expected, changes);

        Assert.Equal(ReservationBillFailure.AssertFailed, decision.Failure);
    }

    [Fact]
    public void Posting_quantity_two_decreases_only_the_reservation_store_by_two()
    {
        var expected = ReservationBillGuard.ReserveDeltas([Line(Material, 2)]);
        var changes = new[]
        {
            Change(Material, ReservationStore, before: -12, after: -14),
            Change(Material, OtherStore, before: 8, after: 8)
        };

        var decision = ReservationBillGuard.EvaluateInventory(ReservationStore, expected, changes);

        Assert.True(decision.Succeeded);
        Assert.Equal(-2d, Assert.Single(expected).Value);
    }

    [Fact]
    public void Unpost_restores_the_previous_reserved_quantity_on_the_reservation_store()
    {
        var expected = ReservationBillGuard.WithZeroFill(
            ReservationBillGuard.RestoreDeltas([Line(Material, 1)]),
            [Material]);
        var changes = new[]
        {
            Change(Material, ReservationStore, before: -13, after: -12)
        };

        var decision = ReservationBillGuard.EvaluateInventory(ReservationStore, expected, changes);

        Assert.True(decision.Succeeded);
    }

    [Fact]
    public void Delete_assert_requires_the_bill_to_be_gone_and_the_quantity_restored()
    {
        var stillThere = ReservationBillGuard.EvaluateDeletedBill(State());
        var restored = ReservationBillGuard.EvaluateInventory(
            ReservationStore,
            ReservationBillGuard.RestoreDeltas([Line(Material, 1)]),
            [Change(Material, ReservationStore, before: -13, after: -12)]);
        var notRestored = ReservationBillGuard.EvaluateInventory(
            ReservationStore,
            ReservationBillGuard.RestoreDeltas([Line(Material, 1)]),
            [Change(Material, ReservationStore, before: -13, after: -13)]);

        Assert.Equal(ReservationBillFailure.AssertFailed, stillThere.Failure);
        Assert.True(restored.Succeeded);
        Assert.Equal(ReservationBillFailure.AssertFailed, notRestored.Failure);
        Assert.True(ReservationBillGuard.EvaluateDeletedBill(State(exists: false, entryCount: 0, paymentCount: 0)).Succeeded);
    }

    [Fact]
    public void Write_validation_rejects_blank_owner_bad_quantity_and_duplicate_materials()
    {
        Assert.Equal(
            ReservationBillFailure.Validation,
            ReservationBillGuard.ValidateWrite("  ", [RequestLine()], out _).Failure);
        Assert.Equal(
            ReservationBillFailure.Validation,
            ReservationBillGuard.ValidateWrite("Owner", [RequestLine(quantity: 0)], out _).Failure);
        Assert.Equal(
            ReservationBillFailure.Validation,
            ReservationBillGuard.ValidateWrite("Owner", [RequestLine(), RequestLine()], out _).Failure);

        var accepted = ReservationBillGuard.ValidateWrite("  Owner  ", [RequestLine(quantity: 2, price: 3.5)], out var draft);
        Assert.True(accepted.Succeeded);
        Assert.Equal("Owner", draft!.OwnerName);
        Assert.Equal(7d, draft.Total);
    }

    [Fact]
    public void Built_commands_never_call_forbidden_procedures_or_set_exclusive()
    {
        var scripts = new[]
        {
            ReservationBillSql.BuildCreateScript(2),
            ReservationBillSql.BuildUpdateScript(2),
            ReservationBillSql.BuildDeleteScript(),
            ReservationBillSql.BuildOwnershipScript(),
            ReservationBillSql.BuildCreateObservationScript(),
            ReservationBillSql.BuildUpdateObservationScript(),
            ReservationBillSql.BuildDeleteObservationScript(),
            ReservationBillSql.BuildResetIgnoreWarnings(),
            ReservationBillSql.EnableAbortOnError
        };

        foreach (var script in scripts)
        {
            ReservationBillSqlSafety.EnsureSafe(script);
            Assert.DoesNotContain("prcBill_genEntry", script, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("prcBill_reGenEntry", script, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("prc_InsertOrModifyMaturity", script, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("fnFlag_isSet", script, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotMatch(@"Exclusive\s*=\s*1\b", script);
        }
    }

    [Theory]
    [InlineData("EXEC dbo.prcBill_genEntry @Guid = @BillGuid;")]
    [InlineData("exec dbo.prcBill_reGenEntry")]
    [InlineData("EXEC dbo.prc_InsertOrModifyMaturity @Guid = @BillGuid;")]
    [InlineData("SELECT dbo.fnFlag_isSet(1000)")]
    [InlineData("UPDATE dbo.connections SET Exclusive = 1 WHERE SPID = @@SPID;")]
    [InlineData("SET XACT_ABORT OFF;")]
    public void Safety_scanner_rejects_forbidden_commands(string sql)
    {
        var error = Assert.Throws<InvalidOperationException>(() => ReservationBillSqlSafety.EnsureSafe(sql));
        Assert.False(string.IsNullOrWhiteSpace(error.Message));
    }

    [Fact]
    public void Amount_column_names_are_inserted_once_including_VATValue()
    {
        var scripts = new[]
        {
            ReservationBillSql.BuildCreateScript(1),
            ReservationBillSql.BuildCreateScript(3),
            ReservationBillSql.BuildUpdateScript(2),
            ReservationBillSql.BuildDeleteScript(),
            ReservationBillSql.BuildOwnershipScript(),
            ReservationBillSql.BuildCreateObservationScript(),
            ReservationBillSql.BuildUpdateObservationScript(),
            ReservationBillSql.BuildDeleteObservationScript()
        };

        var sawAmountColumns = false;
        foreach (var script in scripts)
        {
            foreach (var insert in ColumnNameInserts(script))
            {
                var distinct = insert.Names.Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
                Assert.Equal(insert.Names.Length, distinct.Length);
                if (!insert.Target.Equals("@AmountColumns", StringComparison.Ordinal))
                {
                    continue;
                }

                sawAmountColumns = true;
                Assert.Contains("DECLARE @AmountColumns TABLE (Name sysname NOT NULL PRIMARY KEY);", script);
                Assert.Equal(1, insert.Names.Count(name => name.Equals("VATValue", StringComparison.OrdinalIgnoreCase)));
                Assert.Contains(insert.Names, name => name == "VATValue");
                Assert.DoesNotContain(insert.Names, name => name == "VatValue");
            }
        }

        Assert.True(sawAmountColumns);
    }

    [Fact]
    public void Create_script_posts_only_after_an_unposted_header_and_named_line_procedure()
    {
        var script = ReservationBillSql.BuildCreateScript(1);

        Assert.Contains("WHEN c.name = N'IsPosted' THEN N'0'", script);
        Assert.Contains("@Order = @OrderSentinel", script);
        Assert.Contains("@CurrencyGUID = @BillCurrencyGuid", script);
        Assert.DoesNotContain("PriceCurrency", script, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("bGenEntry", script, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("2.125", script);
        Assert.Contains("prcupdatebuNote", script);
        Assert.Contains("IgnoreWarnings = 1", script);
        Assert.Contains("Exclusive = 0", script);
        Assert.Contains("PayType", script);
        Assert.Contains("00000000-0000-0000-0000-000000000000", script);
        Assert.DoesNotContain("CustGUID = NULL", script, StringComparison.OrdinalIgnoreCase);
        var header = script.IndexOf("INSERT INTO dbo.bu000", StringComparison.Ordinal);
        var lines = script.IndexOf("EXEC dbo.prcBillItem_Add", StringComparison.Ordinal);
        var post = script.LastIndexOf("EXEC dbo.prcBill_Post1", StringComparison.Ordinal);
        var snapshot = script.IndexOf("#MsBefore", StringComparison.Ordinal);
        Assert.True(snapshot >= 0 && snapshot < lines && lines < post);

        foreach (var parameter in ProcedureParameters)
        {
            Assert.Contains(parameter + " =", script);
        }
    }

    [Fact]
    public void Update_script_unposts_replaces_lines_then_posts()
    {
        var script = ReservationBillSql.BuildUpdateScript(1);
        var start = script.IndexOf("#MsAtStart", StringComparison.Ordinal);
        var unpost = script.IndexOf("SET IsPosted = 0", StringComparison.Ordinal);
        var lines = script.IndexOf("EXEC dbo.prcBillItem_Add", StringComparison.Ordinal);
        var mid = script.IndexOf("#MsUnposted", StringComparison.Ordinal);
        var post = script.LastIndexOf("EXEC dbo.prcBill_Post1", StringComparison.Ordinal);

        Assert.True(start < unpost && unpost < lines && lines < mid && mid < post);
        Assert.Contains("@Order = @OrderSentinel", script);
    }

    [Fact]
    public void Delete_script_uses_the_single_guid_procedure_or_direct_deletes()
    {
        var script = ReservationBillSql.BuildDeleteScript();
        var snapshot = script.IndexOf("#MsBefore", StringComparison.Ordinal);
        var procedure = script.IndexOf("prcBill_delete", StringComparison.Ordinal);

        Assert.True(snapshot < procedure);
        Assert.Contains("@paramCount = 1", script);
        Assert.Contains("DELETE FROM dbo.bi000", script);
        Assert.Contains("DELETE FROM dbo.bu000", script);
        Assert.DoesNotContain("prcBillItem_Add", script);
    }

    [Fact]
    public void Observation_queries_read_entry_and_payment_guards()
    {
        foreach (var script in new[]
                 {
                     ReservationBillSql.BuildCreateObservationScript(),
                     ReservationBillSql.BuildUpdateObservationScript(),
                     ReservationBillSql.BuildDeleteObservationScript(),
                     ReservationBillSql.BuildOwnershipScript()
                 })
        {
            Assert.Contains("vwER_EntriesBills", script);
            Assert.Contains("pt000", script);
            Assert.Contains("RefGUID", script);
        }
    }

    [Fact]
    public void Reset_clears_ignore_warnings_without_leaving_the_session_exclusive()
    {
        var script = ReservationBillSql.BuildResetIgnoreWarnings();

        Assert.Contains("IgnoreWarnings = 0", script);
        Assert.Contains("Exclusive = 0", script);
        ReservationBillSqlSafety.EnsureSafe(script);
    }

    private static IEnumerable<(string Target, string[] Names)> ColumnNameInserts(string script)
    {
        foreach (Match insert in Regex.Matches(
                     script,
                     @"INSERT INTO (?<target>@\w+) \(Name\) VALUES(?<values>.*?);",
                     RegexOptions.Singleline | RegexOptions.CultureInvariant))
        {
            var names = Regex.Matches(insert.Groups["values"].Value, @"\(N'([^']*)'\)")
                .Select(match => match.Groups[1].Value)
                .ToArray();
            Assert.NotEmpty(names);
            yield return (insert.Groups["target"].Value, names);
        }
    }

    private static string RepoRoot()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null)
        {
            if (File.Exists(Path.Combine(dir.FullName, "ExistingDbWebApi.sln")))
            {
                return dir.FullName;
            }

            dir = dir.Parent;
        }

        throw new InvalidOperationException("Repository root was not found.");
    }

    private static ReservationBillPattern ReadyPattern(
        int bNoEntry = 1,
        int bFixStore = 1,
        int bFixCurrency = 1,
        int bAutoEntry = 0,
        Guid? storeGuid = null,
        Guid? currencyGuid = null)
    {
        var row = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
        {
            ["bNoEntry"] = bNoEntry,
            ["bAutoEntry"] = bAutoEntry,
            ["bAutoEntryPost"] = 0,
            ["bFixStore"] = bFixStore,
            ["DefStoreGUID"] = storeGuid ?? ReservationStore,
            ["bFixCurrency"] = bFixCurrency,
            ["DefCurrencyGUID"] = currencyGuid ?? BillCurrency,
            ["DefPrice"] = 1,
            ["MaterialPriceListGUID"] = PriceList
        };
        return ReservationBillPatternInterpreter.Interpret(ReservationType, row);
    }

    private static ReservationBillLine Line(Guid materialGuid, double quantity) =>
        new(materialGuid, quantity, 10d);

    private static ReservationBillLineRequest RequestLine(double quantity = 1, double price = 1) =>
        new()
        {
            MaterialGuid = Material,
            Quantity = quantity,
            UnitPrice = price
        };

    private static MaterialStoreQuantityChange Change(Guid materialGuid, Guid storeGuid, double before, double after) =>
        new(materialGuid, storeGuid, before, after, ExistsAfter: true);

    private static ReservationBillOwnership PostedBill(
        Guid? typeGuid = null,
        Guid? storeGuid = null,
        bool isPosted = true,
        int entryCount = 0,
        int paymentCount = 0) =>
        new(
            true,
            typeGuid ?? ReservationType,
            storeGuid ?? ReservationStore,
            isPosted,
            41,
            entryCount,
            paymentCount,
            0,
            [Line(Material, 1)]);

    private static ReservationBillState State(
        bool exists = true,
        Guid? typeGuid = null,
        Guid? storeGuid = null,
        bool isPosted = true,
        int entryCount = 0,
        int paymentCount = 0) =>
        new(
            exists,
            typeGuid ?? ReservationType,
            storeGuid ?? ReservationStore,
            isPosted,
            exists ? 41 : null,
            entryCount,
            paymentCount);
}
