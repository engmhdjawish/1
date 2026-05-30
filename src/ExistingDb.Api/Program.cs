using System.Text;
using ExistingDb.Api.Auth;
using ExistingDb.Api.Authorization;
using ExistingDb.Api.Data;
using ExistingDb.Api.Images;
using ExistingDb.Api.Middleware;
using ExistingDb.Api.Options;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.EntityFrameworkCore;
using Microsoft.IdentityModel.Tokens;
using Microsoft.OpenApi.Models;

var builder = WebApplication.CreateBuilder(args);

builder.Services.Configure<JwtOptions>(builder.Configuration.GetSection(JwtOptions.SectionName));
builder.Services.Configure<SeedAdminOptions>(builder.Configuration.GetSection(SeedAdminOptions.SectionName));
builder.Services.Configure<DevelopmentAuthOptions>(builder.Configuration.GetSection(DevelopmentAuthOptions.SectionName));

var developmentAuthOptions = builder.Configuration.GetSection(DevelopmentAuthOptions.SectionName).Get<DevelopmentAuthOptions>()
    ?? new DevelopmentAuthOptions();
var useDevelopmentBypassAuth = builder.Environment.IsDevelopment() && developmentAuthOptions.BypassSwaggerAuth;

var apiManagementConnection = builder.Configuration.GetConnectionString("ApiManagementDb")
    ?? throw new InvalidOperationException("ConnectionStrings:ApiManagementDb is required.");
var mainDbConnection = builder.Configuration.GetConnectionString("MainDb")
    ?? throw new InvalidOperationException("ConnectionStrings:MainDb is required.");

builder.Services.AddDbContext<ApiManagementDbContext>(options =>
    options.UseSqlServer(apiManagementConnection, sqlServerOptions =>
        sqlServerOptions.EnableRetryOnFailure()));
builder.Services.AddDbContext<MainDbContext>(options =>
    options.UseSqlServer(mainDbConnection, sqlServerOptions =>
    {
        sqlServerOptions.EnableRetryOnFailure();
        // MainDb may run on legacy compatibility levels that do not support OPENJSON ('$').
        sqlServerOptions.UseCompatibilityLevel(120);
    }));

var authenticationBuilder = builder.Services
    .AddAuthentication(options =>
    {
        options.DefaultAuthenticateScheme = useDevelopmentBypassAuth
            ? DevelopmentBypassAuthenticationHandler.SchemeName
            : JwtBearerDefaults.AuthenticationScheme;
        options.DefaultChallengeScheme = useDevelopmentBypassAuth
            ? DevelopmentBypassAuthenticationHandler.SchemeName
            : JwtBearerDefaults.AuthenticationScheme;
    });

if (useDevelopmentBypassAuth)
{
    authenticationBuilder.AddScheme<AuthenticationSchemeOptions, DevelopmentBypassAuthenticationHandler>(
        DevelopmentBypassAuthenticationHandler.SchemeName,
        _ => { });
}

authenticationBuilder.AddJwtBearer(options =>
{
    var jwtOptions = builder.Configuration.GetSection(JwtOptions.SectionName).Get<JwtOptions>()
        ?? throw new InvalidOperationException("Jwt configuration is required.");

    if (Encoding.UTF8.GetByteCount(jwtOptions.SigningKey) < 32)
    {
        throw new InvalidOperationException("Jwt:SigningKey must be at least 32 bytes.");
    }

    options.TokenValidationParameters = new TokenValidationParameters
    {
        ValidateIssuer = true,
        ValidIssuer = jwtOptions.Issuer,
        ValidateAudience = true,
        ValidAudience = jwtOptions.Audience,
        ValidateIssuerSigningKey = true,
        IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(jwtOptions.SigningKey)),
        ValidateLifetime = true,
        ClockSkew = TimeSpan.FromMinutes(1)
    };
});

builder.Services.AddAuthorization();

builder.Services.AddScoped<IPasswordHasher, Pbkdf2PasswordHasher>();
builder.Services.AddScoped<ITokenService, JwtTokenService>();
builder.Services.AddScoped<IAuthService, AuthService>();
builder.Services.AddScoped<IPermissionService, PermissionService>();
builder.Services.AddSingleton<IFieldMasker, FieldMasker>();
builder.Services.AddScoped<IImageSettingsService, ImageSettingsService>();
builder.Services.AddScoped<IImageStorageService, ImageStorageService>();
builder.Services.AddHostedService<ApiManagementDbInitializer>();

builder.Services.AddControllers();
builder.Services.AddEndpointsApiExplorer();
builder.Services.AddSwaggerGen(options =>
{
    options.SwaggerDoc("v1", new OpenApiInfo
    {
        Title = "Existing DB Web API",
        Version = "v1",
        Description = "REST API secured by JWT with separate API management database."
    });

    if (!useDevelopmentBypassAuth)
    {
        options.AddSecurityDefinition("Bearer", new OpenApiSecurityScheme
        {
            Description = "JWT Authorization header using the Bearer scheme.",
            Name = "Authorization",
            In = ParameterLocation.Header,
            Type = SecuritySchemeType.Http,
            Scheme = "bearer",
            BearerFormat = "JWT"
        });

        options.AddSecurityRequirement(new OpenApiSecurityRequirement
        {
            {
                new OpenApiSecurityScheme
                {
                    Reference = new OpenApiReference
                    {
                        Type = ReferenceType.SecurityScheme,
                        Id = "Bearer"
                    }
                },
                []
            }
        });
    }
});

var app = builder.Build();

if (app.Environment.IsDevelopment())
{
    app.UseSwagger();
    app.UseSwaggerUI();
}

app.UseStaticFiles();
app.UseAuthentication();
app.UseMiddleware<AuditLoggingMiddleware>();
app.UseAuthorization();

app.MapControllers();
app.MapGet("/portal", () => Results.Redirect("/portal/index.html"));

app.Run();
