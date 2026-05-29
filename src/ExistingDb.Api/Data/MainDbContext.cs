using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Data;

public sealed class MainDbContext(DbContextOptions<MainDbContext> options) : DbContext(options)
{
    public DbSet<CustomerRecord> Customers => Set<CustomerRecord>();
    public DbSet<MaterialRecord> Materials => Set<MaterialRecord>();
    public DbSet<MaterialImageRecord> MaterialImages => Set<MaterialImageRecord>();
    public DbSet<BillItemRecord> BillItems => Set<BillItemRecord>();
    public DbSet<BillHeaderRecord> Bills => Set<BillHeaderRecord>();
    public DbSet<PaymentRecord> Payments => Set<PaymentRecord>();
    public DbSet<AccountRecord> Accounts => Set<AccountRecord>();
    public DbSet<EntryRecord> Entries => Set<EntryRecord>();
    public DbSet<EntryBillRelationRecord> EntryBillRelations => Set<EntryBillRelationRecord>();
    public DbSet<EntryPaymentRelationRecord> EntryPaymentRelations => Set<EntryPaymentRelationRecord>();
    public DbSet<EntryNoteRelationRecord> EntryNoteRelations => Set<EntryNoteRelationRecord>();
    public DbSet<EntryCollectedNoteRelationRecord> EntryCollectedNoteRelations => Set<EntryCollectedNoteRelationRecord>();
    public DbSet<MaterialInventoryRecord> MaterialInventory => Set<MaterialInventoryRecord>();
    public DbSet<MaterialGroupRecord> MaterialGroups => Set<MaterialGroupRecord>();
    public DbSet<StoreRecord> Stores => Set<StoreRecord>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.Entity<CustomerRecord>(entity =>
        {
            entity.ToTable("cu000");
            entity.HasKey(customer => customer.Guid);

            entity.Property(customer => customer.Guid).HasColumnName("GUID");
            entity.Property(customer => customer.Number).HasColumnName("Number");
            entity.Property(customer => customer.CustomerName).HasColumnName("CustomerName").HasMaxLength(250);
            entity.Property(customer => customer.LatinName).HasColumnName("LatinName").HasMaxLength(250);
            entity.Property(customer => customer.Phone1).HasColumnName("Phone1").HasMaxLength(250);
            entity.Property(customer => customer.Phone2).HasColumnName("Phone2").HasMaxLength(250);
            entity.Property(customer => customer.Mobile).HasColumnName("Mobile").HasMaxLength(250);
            entity.Property(customer => customer.Email).HasColumnName("EMail").HasMaxLength(250);
            entity.Property(customer => customer.AccountGuid).HasColumnName("AccountGUID");
            entity.Property(customer => customer.BarCode).HasColumnName("BarCode").HasMaxLength(250);
            entity.Property(customer => customer.Type).HasColumnName("Type");
            entity.Property(customer => customer.State).HasColumnName("State");
            entity.Property(customer => customer.UseFlag).HasColumnName("UseFlag");
            entity.Property(customer => customer.Security).HasColumnName("Security");
            entity.Property(customer => customer.Notes).HasColumnName("Notes").HasMaxLength(250);
        });

        modelBuilder.Entity<MaterialRecord>(entity =>
        {
            entity.ToTable("mt000");
            entity.HasKey(material => material.Guid);

            entity.Property(material => material.Guid).HasColumnName("GUID");
            entity.Property(material => material.Number).HasColumnName("Number");
            entity.Property(material => material.Name).HasColumnName("Name").HasMaxLength(1000);
            entity.Property(material => material.Code).HasColumnName("Code").HasMaxLength(250);
            entity.Property(material => material.LatinName).HasColumnName("LatinName").HasMaxLength(1000);
            entity.Property(material => material.BarCode).HasColumnName("BarCode").HasMaxLength(100);
            entity.Property(material => material.BarCode2).HasColumnName("BarCode2").HasMaxLength(100);
            entity.Property(material => material.BarCode3).HasColumnName("BarCode3").HasMaxLength(100);
            entity.Property(material => material.Unity).HasColumnName("Unity").HasMaxLength(100);
            entity.Property(material => material.Unit2).HasColumnName("Unit2").HasMaxLength(100);
            entity.Property(material => material.Unit2Fact).HasColumnName("Unit2Fact");
            entity.Property(material => material.Unit2FactFlag).HasColumnName("Unit2FactFlag");
            entity.Property(material => material.Qty).HasColumnName("Qty");
            entity.Property(material => material.Whole).HasColumnName("Whole");
            entity.Property(material => material.Half).HasColumnName("Half");
            entity.Property(material => material.EndUser).HasColumnName("EndUser");
            entity.Property(material => material.AvgPrice).HasColumnName("AvgPrice");
            entity.Property(material => material.LastPrice).HasColumnName("LastPrice");
            entity.Property(material => material.CurrencyVal).HasColumnName("CurrencyVal");
            entity.Property(material => material.Origin).HasColumnName("Origin").HasMaxLength(250);
            entity.Property(material => material.Company).HasColumnName("Company").HasMaxLength(250);
            entity.Property(material => material.Dim).HasColumnName("Dim").HasMaxLength(250);
            entity.Property(material => material.Color).HasColumnName("Color").HasMaxLength(250);
            entity.Property(material => material.Provenance).HasColumnName("Provenance").HasMaxLength(250);
            entity.Property(material => material.GroupGuid).HasColumnName("GroupGUID");
            entity.Property(material => material.PictureGuid).HasColumnName("PictureGUID");
            entity.Property(material => material.CurrencyGuid).HasColumnName("CurrencyGUID");
            entity.Property(material => material.Type).HasColumnName("Type");
            entity.Property(material => material.Security).HasColumnName("Security");
            entity.Property(material => material.UseFlag).HasColumnName("UseFlag");
            entity.Property(material => material.IsHidden).HasColumnName("bHide");
        });

        modelBuilder.Entity<MaterialImageRecord>(entity =>
        {
            entity.ToTable("bm000");
            entity.HasKey(image => image.Guid);

            entity.Property(image => image.Guid).HasColumnName("GUID");
            entity.Property(image => image.Name).HasColumnName("Name").HasMaxLength(260);
        });

        modelBuilder.Entity<BillItemRecord>(entity =>
        {
            entity.ToTable("bi000");
            entity.HasKey(item => item.Guid);

            entity.Property(item => item.Guid).HasColumnName("GUID");
            entity.Property(item => item.ParentGuid).HasColumnName("ParentGUID");
            entity.Property(item => item.MaterialGuid).HasColumnName("MatGUID");
        });

        modelBuilder.Entity<BillHeaderRecord>(entity =>
        {
            entity.ToTable("bu000");
            entity.HasKey(bill => bill.Guid);

            entity.Property(bill => bill.Guid).HasColumnName("GUID");
            entity.Property(bill => bill.Number).HasColumnName("Number");
            entity.Property(bill => bill.Date).HasColumnName("Date");
            entity.Property(bill => bill.Notes).HasColumnName("Notes").HasMaxLength(1000);
        });

        modelBuilder.Entity<PaymentRecord>(entity =>
        {
            entity.ToTable("py000");
            entity.HasKey(payment => payment.Guid);

            entity.Property(payment => payment.Guid).HasColumnName("GUID");
            entity.Property(payment => payment.Number).HasColumnName("Number");
            entity.Property(payment => payment.Date).HasColumnName("Date");
            entity.Property(payment => payment.Notes).HasColumnName("Notes").HasMaxLength(1000);
        });

        modelBuilder.Entity<AccountRecord>(entity =>
        {
            entity.ToTable("ac000");
            entity.HasKey(account => account.Guid);

            entity.Property(account => account.Guid).HasColumnName("GUID");
            entity.Property(account => account.Number).HasColumnName("Number");
            entity.Property(account => account.Name).HasColumnName("Name").HasMaxLength(250);
            entity.Property(account => account.Code).HasColumnName("Code").HasMaxLength(250);
            entity.Property(account => account.Debit).HasColumnName("Debit");
            entity.Property(account => account.Credit).HasColumnName("Credit");
            entity.Property(account => account.InitDebit).HasColumnName("InitDebit");
            entity.Property(account => account.InitCredit).HasColumnName("InitCredit");
        });

        modelBuilder.Entity<EntryRecord>(entity =>
        {
            entity.ToTable("en000");
            entity.HasKey(entry => entry.Guid);

            entity.Property(entry => entry.Guid).HasColumnName("GUID");
            entity.Property(entry => entry.Number).HasColumnName("Number");
            entity.Property(entry => entry.Date).HasColumnName("Date");
            entity.Property(entry => entry.Debit).HasColumnName("Debit");
            entity.Property(entry => entry.Credit).HasColumnName("Credit");
            entity.Property(entry => entry.Notes).HasColumnName("Notes").HasMaxLength(1000);
            entity.Property(entry => entry.ParentGuid).HasColumnName("ParentGUID");
            entity.Property(entry => entry.AccountGuid).HasColumnName("AccountGUID");
            entity.Property(entry => entry.ContraAccountGuid).HasColumnName("ContraAccGUID");
            entity.Property(entry => entry.CustomerGuid).HasColumnName("CustomerGUID");
        });

        modelBuilder.Entity<EntryBillRelationRecord>(entity =>
        {
            entity.ToView("vwER_EntriesBills");
            entity.HasNoKey();

            entity.Property(relation => relation.EntryGuid).HasColumnName("erEntryGUID");
            entity.Property(relation => relation.BillGuid).HasColumnName("erBillGUID");
        });

        modelBuilder.Entity<EntryPaymentRelationRecord>(entity =>
        {
            entity.ToView("vwER_EntriesPays");
            entity.HasNoKey();

            entity.Property(relation => relation.EntryGuid).HasColumnName("erEntryGUID");
            entity.Property(relation => relation.PaymentGuid).HasColumnName("erPayGUID");
        });

        modelBuilder.Entity<EntryNoteRelationRecord>(entity =>
        {
            entity.ToView("vwER_EntriesNotes");
            entity.HasNoKey();

            entity.Property(relation => relation.EntryGuid).HasColumnName("erEntryGUID");
            entity.Property(relation => relation.NoteGuid).HasColumnName("erNoteGUID");
        });

        modelBuilder.Entity<EntryCollectedNoteRelationRecord>(entity =>
        {
            entity.ToView("vwER_EntriesCollectedNotes");
            entity.HasNoKey();

            entity.Property(relation => relation.EntryGuid).HasColumnName("erEntryGUID");
            entity.Property(relation => relation.NoteGuid).HasColumnName("erNoteGUID");
        });

        modelBuilder.Entity<MaterialInventoryRecord>(entity =>
        {
            entity.ToView("vwMaterialInventory");
            entity.HasNoKey();

            entity.Property(inventory => inventory.MaterialGuid).HasColumnName("MaterialGuid");
            entity.Property(inventory => inventory.StoreGuid).HasColumnName("StoreGuid");
            entity.Property(inventory => inventory.Qty).HasColumnName("Qty");
        });

        modelBuilder.Entity<MaterialGroupRecord>(entity =>
        {
            entity.ToTable("gr000");
            entity.HasKey(group => group.Guid);

            entity.Property(group => group.Guid).HasColumnName("GUID");
            entity.Property(group => group.Number).HasColumnName("Number");
            entity.Property(group => group.Code).HasColumnName("Code").HasMaxLength(250);
            entity.Property(group => group.Name).HasColumnName("Name").HasMaxLength(250);
            entity.Property(group => group.LatinName).HasColumnName("LatinName").HasMaxLength(250);
            entity.Property(group => group.ParentGuid).HasColumnName("ParentGUID");
        });

        modelBuilder.Entity<StoreRecord>(entity =>
        {
            entity.ToTable("st000");
            entity.HasKey(store => store.Guid);

            entity.Property(store => store.Guid).HasColumnName("GUID");
            entity.Property(store => store.Number).HasColumnName("Number");
            entity.Property(store => store.Code).HasColumnName("Code").HasMaxLength(100);
            entity.Property(store => store.Name).HasColumnName("Name").HasMaxLength(250);
            entity.Property(store => store.LatinName).HasColumnName("LatinName").HasMaxLength(250);
            entity.Property(store => store.IsActive).HasColumnName("IsActive");
        });
    }
}

