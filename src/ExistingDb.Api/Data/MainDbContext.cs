using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Data;

public sealed class MainDbContext(DbContextOptions<MainDbContext> options) : DbContext(options)
{
    public DbSet<CustomerRecord> Customers => Set<CustomerRecord>();
    public DbSet<MaterialRecord> Materials => Set<MaterialRecord>();

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
            entity.ToView("JocVwMaterialsWithAlternatives");
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
            entity.Property(material => material.Unit2Fact).HasColumnName("Unit2Fact");
            entity.Property(material => material.Unit2FactFlag).HasColumnName("Unit2FactFlag");
            entity.Property(material => material.Qty).HasColumnName("Qty");
            entity.Property(material => material.Whole).HasColumnName("Whole");
            entity.Property(material => material.Half).HasColumnName("Half");
            entity.Property(material => material.Retail).HasColumnName("Retail");
            entity.Property(material => material.AvgPrice).HasColumnName("AvgPrice");
            entity.Property(material => material.LastPrice).HasColumnName("LastPrice");
            entity.Property(material => material.CurrencyVal).HasColumnName("CurrencyVal");
            entity.Property(material => material.GroupGuid).HasColumnName("GroupGUID");
            entity.Property(material => material.CurrencyGuid).HasColumnName("CurrencyGUID");
            entity.Property(material => material.Type).HasColumnName("Type");
            entity.Property(material => material.Security).HasColumnName("Security");
            entity.Property(material => material.UseFlag).HasColumnName("UseFlag");
            entity.Property(material => material.IsHidden).HasColumnName("bHide");
        });
    }
}

