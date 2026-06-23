#Requires -Version 5.1
param(
    [string]$EnvFile,
    [ValidateSet('fresh', 'migrate', 'skip')]
    [string]$DbSetup = 'fresh'
)
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

$envPath = if ($EnvFile) { $EnvFile } else { Join-Path $DeployRoot 'deploy.env' }
$vars = Read-DeployEnv -Path $envPath

$publishDir = $vars['PORTAL_PUBLISH_DIR']
if (-not $publishDir) { throw 'PORTAL_PUBLISH_DIR is not set in deploy.env' }

$source = $vars['PORTAL_SOURCE_DIR']
if (-not $source) { $source = Join-Path $RepoRoot 'portal' }

Write-Step "Publishing portal to $publishDir"
Copy-PortalTree -Destination $publishDir -Source $source
Write-PortalEnv -Destination $publishDir -Env $vars

Push-Location $publishDir
try {
    composer install --no-dev --optimize-autoloader --no-interaction

    if ($DbSetup -eq 'fresh') {
        Write-Step 'Creating database (schema + seed)'
        php scripts/setup-database.php
        Write-Step 'Applying extra migrations'
        php scripts/run-migrations.php
    } elseif ($DbSetup -eq 'migrate') {
        Write-Step 'Running database migrations'
        php scripts/run-migrations.php
    }

    $adminUser = $vars['PORTAL_ADMIN_USER']
    $adminPass = $vars['PORTAL_ADMIN_PASSWORD']
    if ($adminUser -and $adminPass) {
        $display = $vars['PORTAL_ADMIN_DISPLAY_NAME']
        if (-not $display) { $display = 'Admin' }
        php scripts/create-admin.php $adminUser $adminPass $display
    }

    php scripts/check-environment.php
} finally {
    Pop-Location
}

$out = Join-Path $DeployRoot 'output'
if (-not (Test-Path $out)) { New-Item -ItemType Directory -Path $out -Force | Out-Null }

Expand-Template `
  -TemplatePath (Join-Path $DeployRoot 'templates\portal\nginx-site.conf.template') `
  -OutputPath (Join-Path $out 'nginx-jawish-portal.conf') `
  -Variables $vars

Copy-Item `
  (Join-Path $DeployRoot 'templates\portal\iis-web.config.template') `
  (Join-Path $publishDir 'public\web.config') `
  -Force

Write-Ok 'Portal publish finished'
Write-Host "  IIS web root: $publishDir\public" -ForegroundColor Gray
