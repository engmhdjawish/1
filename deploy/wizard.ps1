#Requires -Version 5.1
<#
.SYNOPSIS
  Jawish deploy wizard - API + Portal

.EXAMPLE
  .\deploy\wizard.ps1
  .\deploy\wizard.ps1 -Action api
  .\deploy\wizard.ps1 -Action portal -DbSetup migrate
#>
param(
    [ValidateSet('menu', 'full', 'api', 'portal', 'check', 'migrate')]
    [string]$Action = 'menu',
    [ValidateSet('fresh', 'migrate', 'skip')]
    [string]$DbSetup = 'fresh'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\lib\common.ps1"

function Show-Banner {
    Write-Host ''
    Write-Host '========================================' -ForegroundColor Red
    Write-Host '   Jawish Deploy Wizard - API + Portal  ' -ForegroundColor Red
    Write-Host '========================================' -ForegroundColor Red
    Write-Host ''
}

function Read-EnvValue {
    param(
        [string]$Key,
        [string]$Prompt,
        [string]$Default = '',
        [switch]$Secret
    )

    $envFile = Join-Path $DeployRoot 'deploy.env'
    $existing = @{}
    if (Test-Path $envFile) {
        $existing = Read-DeployEnv -Path $envFile
    }

    $current = $existing[$Key]
    if (-not $current) {
        $current = $Default
    }

    if ($Secret) {
        $suffix = ''
        if ($current) {
            $suffix = '[saved - press Enter to keep]'
        }
        $promptText = $Prompt
        if ($suffix) {
            $promptText = "$Prompt $suffix"
        }
        $secure = Read-Host $promptText -AsSecureString
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
            [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        )
        if ($plain) {
            return $plain
        }
        return $current
    }

    $suffix = ''
    if ($current) {
        $suffix = "[$current]"
    }
    $promptText = $Prompt
    if ($suffix) {
        $promptText = "$Prompt $suffix"
    }
    $reply = Read-Host $promptText
    if ($reply) {
        return $reply
    }
    return $current
}

function Invoke-ConfigureWizard {
    $envFile = Join-Path $DeployRoot 'deploy.env'
    if (-not (Test-Path $envFile)) {
        Copy-Item (Join-Path $DeployRoot 'deploy.env.example') $envFile
    }

    Write-Step 'API setup'
    Save-DeployEnvValue 'API_PUBLISH_DIR' (Read-EnvValue 'API_PUBLISH_DIR' 'API publish folder' (Get-DefaultApiPublishDir))
    Save-DeployEnvValue 'API_PORT' (Read-EnvValue 'API_PORT' 'API port' '5000')
    Save-DeployEnvValue 'API_BIND_HOST' (Read-EnvValue 'API_BIND_HOST' 'API bind host' '0.0.0.0')

    $port = (Read-DeployEnv -Path $envFile)['API_PORT']
    Save-DeployEnvValue 'API_URL' (Read-EnvValue 'API_URL' 'API URL for portal' "http://127.0.0.1:$port")
    Save-DeployEnvValue 'MAIN_DB_CONNECTION' (Read-EnvValue 'MAIN_DB_CONNECTION' 'MainDb connection string (SQL Server)')
    Save-DeployEnvValue 'API_MANAGEMENT_DB_CONNECTION' (Read-EnvValue 'API_MANAGEMENT_DB_CONNECTION' 'ApiManagementDb connection string')

    $jwt = (Read-DeployEnv -Path $envFile)['JWT_SIGNING_KEY']
    if (-not $jwt -or $jwt -like 'REPLACE*') {
        $jwt = New-RandomSecret
        Save-DeployEnvValue 'JWT_SIGNING_KEY' $jwt
        Write-Ok 'Generated random JWT signing key'
    } else {
        $keep = Read-Host 'JWT key exists - press Enter to keep or type a new key'
        if ($keep) {
            Save-DeployEnvValue 'JWT_SIGNING_KEY' $keep
        }
    }

    $seed = Read-Host 'Create API admin user on first run? (y/N)'
    if ($seed -match '^[yY]') {
        Save-DeployEnvValue 'SEED_ADMIN_ENABLED' 'true'
        Save-DeployEnvValue 'SEED_ADMIN_PASSWORD' (Read-EnvValue 'SEED_ADMIN_PASSWORD' 'API admin password' -Secret)
    } else {
        Save-DeployEnvValue 'SEED_ADMIN_ENABLED' 'false'
    }

    Write-Step 'Portal setup'
    Save-DeployEnvValue 'PORTAL_PUBLISH_DIR' (Read-EnvValue 'PORTAL_PUBLISH_DIR' 'Portal publish folder' (Get-DefaultPortalPublishDir))
    Save-DeployEnvValue 'PORTAL_APP_URL' (Read-EnvValue 'PORTAL_APP_URL' 'Public site URL' 'http://127.0.0.1:8080')
    Save-DeployEnvValue 'PORTAL_DB_HOST' (Read-EnvValue 'PORTAL_DB_HOST' 'PostgreSQL host' '127.0.0.1')
    Save-DeployEnvValue 'PORTAL_DB_PORT' (Read-EnvValue 'PORTAL_DB_PORT' 'PostgreSQL port' '5432')
    Save-DeployEnvValue 'PORTAL_DB_NAME' (Read-EnvValue 'PORTAL_DB_NAME' 'Database name' 'portal_db')
    Save-DeployEnvValue 'PORTAL_DB_USER' (Read-EnvValue 'PORTAL_DB_USER' 'PostgreSQL user' 'portal')
    Save-DeployEnvValue 'PORTAL_DB_PASSWORD' (Read-EnvValue 'PORTAL_DB_PASSWORD' 'PostgreSQL password' -Secret)
    Save-DeployEnvValue 'AMINE_API_USERNAME' (Read-EnvValue 'AMINE_API_USERNAME' 'API service username' 'portal-service')
    Save-DeployEnvValue 'AMINE_API_PASSWORD' (Read-EnvValue 'AMINE_API_PASSWORD' 'API service password' -Secret)

    $admin = Read-Host 'Create dashboard admin user? (Y/n)'
    if ($admin -notmatch '^[nN]') {
        Save-DeployEnvValue 'PORTAL_ADMIN_USER' (Read-EnvValue 'PORTAL_ADMIN_USER' 'Dashboard username' 'admin')
        Save-DeployEnvValue 'PORTAL_ADMIN_PASSWORD' (Read-EnvValue 'PORTAL_ADMIN_PASSWORD' 'Dashboard password' -Secret)
        Save-DeployEnvValue 'PORTAL_ADMIN_DISPLAY_NAME' (Read-EnvValue 'PORTAL_ADMIN_DISPLAY_NAME' 'Display name (Arabic OK)' 'Admin')
    }

    Save-DeployEnvValue 'WEB_DOMAIN' (Read-EnvValue 'WEB_DOMAIN' 'Site domain (for nginx template)' 'localhost')
    Write-Ok 'Saved settings to deploy\deploy.env'
}

function Get-PortalDirForMigrate {
    $portalDir = Join-Path $RepoRoot 'portal'
    $envFile = Join-Path $DeployRoot 'deploy.env'
    if (Test-Path $envFile) {
        $vars = Read-DeployEnv -Path $envFile
        if ($vars['PORTAL_PUBLISH_DIR']) {
            $portalDir = $vars['PORTAL_PUBLISH_DIR']
        }
    }
    return $portalDir
}

Show-Banner

switch ($Action) {
    'check' {
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        exit $LASTEXITCODE
    }
    'migrate' {
        $portalDir = Get-PortalDirForMigrate
        Push-Location $portalDir
        php scripts/run-migrations.php
        Pop-Location
        exit $LASTEXITCODE
    }
    'api' {
        if (-not (Test-Path (Join-Path $DeployRoot 'deploy.env'))) {
            Invoke-ConfigureWizard
        }
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        if ($LASTEXITCODE -ne 0) { exit 1 }
        & "$PSScriptRoot\api\publish.ps1"
        exit $LASTEXITCODE
    }
    'portal' {
        if (-not (Test-Path (Join-Path $DeployRoot 'deploy.env'))) {
            Invoke-ConfigureWizard
        }
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        if ($LASTEXITCODE -ne 0) {
            Write-Fail 'Prerequisites missing - fix them before publishing the portal'
            if ($env:OS -match 'Windows') {
                Write-Host '  .\deploy\scripts\fix-windows-php.ps1' -ForegroundColor Yellow
                Write-Host '  Edit deploy\deploy.env -> PORTAL_PUBLISH_DIR=D:\JawishPortal' -ForegroundColor Yellow
            }
            exit 1
        }
        & "$PSScriptRoot\portal\publish.ps1" -DbSetup $DbSetup
        exit $LASTEXITCODE
    }
    'full' {
        Invoke-ConfigureWizard
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        & "$PSScriptRoot\api\publish.ps1"
        & "$PSScriptRoot\portal\publish.ps1" -DbSetup $DbSetup
        Write-Host ''
        Write-Ok 'Deploy finished. Next steps:'
        Write-Host '  1) Start API from publish folder (or Windows service/IIS)' -ForegroundColor Gray
        Write-Host '  2) Create portal-service user in ApiManagementDb' -ForegroundColor Gray
        Write-Host '  3) Point IIS to portal\public' -ForegroundColor Gray
        Write-Host '  4) See deploy\README.md' -ForegroundColor Gray
        exit 0
    }
    default {
        while ($true) {
            Show-Banner
            Write-Host '  1) Check prerequisites'
            Write-Host '  2) Full setup (API + Portal)'
            Write-Host '  3) Publish API only'
            Write-Host '  4) Publish Portal only'
            Write-Host '  5) Run portal DB migrations'
            Write-Host '  6) Edit settings (deploy.env)'
            Write-Host '  0) Exit'
            Write-Host ''
            $choice = Read-Host 'Choose option'
            switch ($choice) {
                '1' {
                    & "$PSScriptRoot\scripts\check-prerequisites.ps1"
                    Read-Host 'Press Enter to continue'
                }
                '2' {
                    & $PSCommandPath -Action full -DbSetup $DbSetup
                    Read-Host 'Press Enter to continue'
                }
                '3' {
                    & $PSCommandPath -Action api
                    Read-Host 'Press Enter to continue'
                }
                '4' {
                    $db = Read-Host 'Database mode: fresh / migrate / skip'
                    if (-not $db) { $db = 'fresh' }
                    & $PSCommandPath -Action portal -DbSetup $db
                    Read-Host 'Press Enter to continue'
                }
                '5' {
                    & $PSCommandPath -Action migrate
                    Read-Host 'Press Enter to continue'
                }
                '6' {
                    Invoke-ConfigureWizard
                    Read-Host 'Press Enter to continue'
                }
                '0' { exit 0 }
                default { Write-Warn 'Invalid option' }
            }
        }
    }
}
