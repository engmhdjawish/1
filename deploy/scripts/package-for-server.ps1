#Requires -Version 5.1
<#
.SYNOPSIS
  Build a USB-ready folder on YOUR PC (no Git on server required).

  Creates deploy\output\server-package\ with:
    JawishPortal\     — site files + vendor + .env for the server
    docs\             — SQL schema + migrations
    server-tools\     — scripts to run once on the server
    SERVER-STEPS.txt  — short checklist

.EXAMPLE
  .\deploy\scripts\package-for-server.ps1
  Copy deploy\output\server-package to the server (USB / RDP).
#>
param(
    [string]$EnvFile,
    [string]$OutputRoot = ''
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

$envPath = if ($EnvFile) { $EnvFile } else { Join-Path $DeployRoot 'deploy.env' }
if (-not (Test-Path $envPath)) {
    Write-Fail "Missing $envPath - copy deploy.env.example to deploy.env and fill server values first"
    Write-Host '  notepad deploy\deploy.env' -ForegroundColor Yellow
    exit 1
}

$vars = Read-DeployEnv -Path $envPath
if (-not $OutputRoot) {
    $OutputRoot = Join-Path $DeployRoot 'output\server-package'
}

$portalDest = Join-Path $OutputRoot 'JawishPortal'
$docsDest = Join-Path $OutputRoot 'docs'
$toolsDest = Join-Path $OutputRoot 'server-tools'
$libDest = Join-Path $OutputRoot 'lib'

Write-Step "Packaging for server -> $OutputRoot"

if (Test-Path $OutputRoot) {
    Remove-Item $OutputRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $portalDest, $docsDest, $toolsDest, $libDest -Force | Out-Null

$portalSource = $vars['PORTAL_SOURCE_DIR']
if (-not $portalSource) { $portalSource = Join-Path $RepoRoot 'portal' }

Copy-PortalTree -Destination $portalDest -Source $portalSource

$serverPublishDir = $vars['PORTAL_PUBLISH_DIR']
if (-not $serverPublishDir) { $serverPublishDir = 'D:\JawishPortal' }

$vars['PORTAL_REPO_DOCS_PATH'] = Join-Path $serverPublishDir 'docs'
Write-PortalEnv -Destination $portalDest -Env $vars

Write-Step 'Installing PHP dependencies (composer)'
Invoke-ComposerInstall -WorkingDirectory $portalDest

Write-Step 'Copying docs (schema + migrations)'
$docsSource = Join-Path $RepoRoot 'docs'
robocopy $docsSource $docsDest /E /XD .git /NFL /NDL /NJH /NJS /NC /NS | Out-Null
if ($LASTEXITCODE -ge 8) { throw "docs copy failed ($LASTEXITCODE)" }

Copy-Item `
  (Join-Path $DeployRoot 'templates\portal\iis-web.config.minimal.template') `
  (Join-Path $portalDest 'public\web.config') `
  -Force
Copy-Item `
  (Join-Path $DeployRoot 'templates\portal\iis-web.config.template') `
  (Join-Path $portalDest 'public\web.config.with-rewrite') `
  -Force

$toolFiles = @(
    'setup-portable-postgres.ps1',
    'fix-windows-php.ps1',
    'server-setup-on-host.ps1',
    'install-iis-php-handler.ps1'
)
foreach ($name in $toolFiles) {
    Copy-Item (Join-Path $PSScriptRoot $name) (Join-Path $toolsDest $name) -Force
}
Copy-Item (Join-Path $DeployRoot 'lib\common.ps1') (Join-Path $toolsDest 'common.ps1') -Force
Copy-Item (Join-Path $DeployRoot 'lib\common.ps1') (Join-Path $libDest 'common.ps1') -Force

$apiUrl = [string]$vars['API_URL']
$portalAppUrl = [string]$vars['PORTAL_APP_URL']

$steps = @'
========================================
  Jawish Portal — run ON THE SERVER
========================================

Prerequisites on server (copy via USB if blocked):
  - postgresql-16.*-windows-x64-binaries.zip  -> extract to D:\PostgreSQL
  - PHP 8.2+ with FastCGI for IIS (if not installed)
  - IIS URL Rewrite module (optional but recommended)

1) Copy this entire folder to the server, e.g. C:\JawishDeploy\

2) PostgreSQL (first time only):
   cd C:\JawishDeploy\server-tools
   .\setup-portable-postgres.ps1 -PgRoot C:\pgsql -DbPassword YOUR_DB_PASSWORD
   (if superuser is not postgres: -SuperUser admin -SuperUserPassword YOUR_ADMIN_PASSWORD)

3) Install site files:
   xcopy /E /I /Y C:\JawishDeploy\JawishPortal D:\JawishPortal
   xcopy /E /I /Y C:\JawishDeploy\docs D:\JawishPortal\docs

4) Database + admin (on server, after PostgreSQL is running):
   cd D:\JawishPortal
   php scripts\setup-database.php
   php scripts\run-migrations.php
   php scripts\create-admin.php admin YOUR_PASSWORD مدير_النظام
   php scripts\check-environment.php

   Or run: C:\JawishDeploy\server-tools\server-setup-on-host.ps1

5) IIS:
   - Site physical path: D:\JawishPortal\public
   - Binding: http port 8080 (or your IP)
   - Run AS ADMINISTRATOR (fixes 404.3 on .php):
     cd C:\JawishDeploy\server-tools
     .\install-iis-php-handler.ps1 -SitePort 90
     (or -PhpCgiPath C:\php\php-cgi.exe -SiteName YourSiteName)
   - Enable pdo_pgsql for IIS (fixes "could not find driver"):
     .\fix-windows-php.ps1 -ApplyFix -PgBinDir D:\PgSQL\bin
     iisreset
   - Write permission on D:\JawishPortal\storage for AppPool identity

6) Test:
   - API:  http://127.0.0.1:5000/api/health
   - Site: http://YOUR_SERVER_IP:8080
   - Admin: /dashboard/users.php

Configured in .env (inside JawishPortal):
  PORTAL_PUBLISH_DIR target on server: {0}
  API_URL: {1}
  PORTAL_APP_URL: {2}

Full guide: deploy\WINDOWS-IIS-LOCAL.md (on dev PC repo)
'@ -f $serverPublishDir, $apiUrl, $portalAppUrl

$utf8Bom = New-Object System.Text.UTF8Encoding $true
[System.IO.File]::WriteAllText((Join-Path $OutputRoot 'SERVER-STEPS.txt'), $steps, $utf8Bom)

Write-Ok 'Server package ready'
Write-Host ''
Write-Host "  Folder: $OutputRoot" -ForegroundColor Green
Write-Host '  Copy to USB / server, then follow SERVER-STEPS.txt' -ForegroundColor Gray
Write-Host ''
