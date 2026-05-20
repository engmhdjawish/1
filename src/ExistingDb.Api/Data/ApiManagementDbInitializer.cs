using ExistingDb.Api.Auth;
using ExistingDb.Api.Data.Entities;
using ExistingDb.Api.Options;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;

namespace ExistingDb.Api.Data;

public sealed class ApiManagementDbInitializer(
    IServiceProvider serviceProvider,
    ILogger<ApiManagementDbInitializer> logger) : IHostedService
{
    public async Task StartAsync(CancellationToken cancellationToken)
    {
        using var scope = serviceProvider.CreateScope();
        var dbContext = scope.ServiceProvider.GetRequiredService<ApiManagementDbContext>();

        await dbContext.Database.MigrateAsync(cancellationToken);
        await SeedAdminAsync(scope.ServiceProvider, dbContext, cancellationToken);
    }

    public Task StopAsync(CancellationToken cancellationToken) => Task.CompletedTask;

    private async Task SeedAdminAsync(
        IServiceProvider scopedServices,
        ApiManagementDbContext dbContext,
        CancellationToken cancellationToken)
    {
        var options = scopedServices.GetRequiredService<IOptions<SeedAdminOptions>>().Value;
        if (!options.Enabled)
        {
            return;
        }

        if (string.IsNullOrWhiteSpace(options.Password))
        {
            logger.LogWarning("SeedAdmin is enabled, but SeedAdmin:Password is empty. No admin user was created.");
            return;
        }

        var normalizedUserName = Normalize(options.UserName);
        var exists = await dbContext.Users.AnyAsync(user => user.NormalizedUserName == normalizedUserName, cancellationToken);
        if (exists)
        {
            return;
        }

        var passwordHasher = scopedServices.GetRequiredService<IPasswordHasher>();
        var adminUser = new ApiUser
        {
            UserName = options.UserName,
            NormalizedUserName = normalizedUserName,
            Email = options.Email,
            NormalizedEmail = Normalize(options.Email),
            DisplayName = options.DisplayName,
            PasswordHash = passwordHasher.HashPassword(options.Password),
            IsActive = true,
            CreatedAt = DateTimeOffset.UtcNow
        };

        dbContext.Users.Add(adminUser);
        dbContext.UserRoles.Add(new ApiUserRole { UserId = adminUser.Id, RoleId = 1 });
        await dbContext.SaveChangesAsync(cancellationToken);
        logger.LogInformation("Seeded initial API admin user {UserName}.", options.UserName);
    }

    private static string Normalize(string value) => value.Trim().ToUpperInvariant();
}

