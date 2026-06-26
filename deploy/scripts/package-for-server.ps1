#Requires -Version 5.1
<#
.SYNOPSIS
  Build a USB-ready folder on YOUR PC (no Git on server required).

  Creates deploy\output\server-package\ with:
    JawishPortal\     - site files + vendor + .env for the server
    docs\             - SQL schema + migrations
    server-tools\     - scripts to run once on the server
    SERVER-STEPS.txt  - short checklist

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

$pwaRequired = @(
    'public\icons\icon-192.png',
    'public\icons\icon-512.png',
    'public\manifest.webmanifest',
    'public\pwa-check.php'
)
foreach ($rel in $pwaRequired) {
    $full = Join-Path $portalDest $rel
    if (-not (Test-Path $full)) {
        Write-Fail "PWA file missing in package: $rel - run git pull then package again"
        exit 1
    }
}
Write-Ok 'PWA icon + manifest files present in package'

& (Join-Path $PSScriptRoot 'copy-pwa-bundle.ps1')

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
    'fix-iis-php-concurrency.ps1',
    'server-setup-on-host.ps1',
    'install-iis-php-handler.ps1',
    'copy-pwa-bundle.ps1',
    'server-verify-pwa-files.ps1'
)
foreach ($name in $toolFiles) {
    Copy-Item (Join-Path $PSScriptRoot $name) (Join-Path $toolsDest $name) -Force
}
Copy-Item (Join-Path $DeployRoot 'lib\common.ps1') (Join-Path $toolsDest 'common.ps1') -Force
Copy-Item (Join-Path $DeployRoot 'lib\common.ps1') (Join-Path $libDest 'common.ps1') -Force

$apiUrl = [string]$vars['API_URL']
$portalAppUrl = [string]$vars['PORTAL_APP_URL']

$stepsTemplate = Join-Path $DeployRoot 'templates\SERVER-STEPS.template.txt'
if (-not (Test-Path $stepsTemplate)) {
    throw "Missing template: $stepsTemplate"
}
$steps = [string]::Format((Get-Content -Path $stepsTemplate -Raw -Encoding UTF8), $serverPublishDir, $apiUrl, $portalAppUrl)

$utf8Bom = New-Object System.Text.UTF8Encoding $true
[System.IO.File]::WriteAllText((Join-Path $OutputRoot 'SERVER-STEPS.txt'), $steps, $utf8Bom)

Write-Ok 'Server package ready'
Write-Host ''
Write-Host "  Folder: $OutputRoot" -ForegroundColor Green
Write-Host '  Copy to USB / server, then follow SERVER-STEPS.txt' -ForegroundColor Gray
Write-Host ''
