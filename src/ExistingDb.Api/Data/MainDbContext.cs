using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Data;

public sealed class MainDbContext(DbContextOptions<MainDbContext> options) : DbContext(options)
{
    public DbSet<CustomerRecord> Customers => Set<CustomerRecord>();

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
    }
}

