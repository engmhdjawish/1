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

$pwaRequired = @(
    'public\icons\icon-192.png',
    'public\icons\icon-512.png',
    'public\manifest.webmanifest',
    'public\pwa-check.php'
)
foreach ($rel in $pwaRequired) {
    $full = Join-Path $portalDest $rel
    if (-not (Test-Path $full)) {
        Write-Fail "PWA file missing in package: $rel — run git pull then package again"
        exit 1
    }
}
Write-Ok 'PWA icon + manifest files present in package'

& (Join-Path $PSScriptRoot 'copy-pwa-bundle.ps1') | Out-Null

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
    'copy-pwa-bundle.ps1'
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
  Jawish Portal — نشر من الصفر (السيرفر)
========================================

الوضع المتوقع:
  - API شغّال على المنفذ 5000 (لا تعيد نشره)
  - PostgreSQL في C:\pgsql — المسؤول: admin
  - PHP في C:\php — IIS على المنفذ 90
  - الموقع في D:\JawishPortal

--- جهاز التطوير (مرة واحدة قبل النسخ) ---
  cd C:\Users\HP\1
  notepad deploy\deploy.env
  .\deploy\scripts\package-for-server.ps1
  انسخ deploy\output\server-package إلى السيرفر كـ C:\JawishDeploy\

--- السيرفر: الخطوة 0 — تنظيف (إعادة نشر فقط) ---
  PowerShell كمسؤول:
    iisreset /stop
    Remove-Item D:\JawishPortal -Recurse -Force -ErrorAction SilentlyContinue
  IIS Manager: احذف موقع JawishPortal إن وُجد
  (اختياري) لإعادة قاعدة البوابة من الصفر:
    C:\pgsql\bin\psql.exe -h 127.0.0.1 -U admin -d postgres -c "DROP DATABASE IF EXISTS portal_db;"
    C:\pgsql\bin\psql.exe -h 127.0.0.1 -U admin -d postgres -c "DROP USER IF EXISTS portal;"

--- السيرفر: الخطوة 1 — PHP ---
  PowerShell كمسؤول:
    dism /online /enable-feature /featurename IIS-CGI /all
  تأكد: C:\php\php.exe و C:\php\php-cgi.exe و C:\php\php.ini

--- السيرفر: الخطوة 2 — نسخ الملفات ---
  PowerShell عادي:
    xcopy /E /I /Y C:\JawishDeploy\JawishPortal D:\JawishPortal
    xcopy /E /I /Y C:\JawishDeploy\docs D:\JawishPortal\docs
    New-Item C:\JawishDeploy\lib -Force -ErrorAction SilentlyContinue
    Copy-Item C:\JawishDeploy\server-tools\common.ps1 C:\JawishDeploy\lib\common.ps1 -Force

--- السيرفر: الخطوة 3 — قاعدة البيانات (admin) ---
  PowerShell عادي:
    $env:PGPASSWORD = "ADMIN_PASSWORD"
    C:\pgsql\bin\psql.exe -h 127.0.0.1 -U admin -d postgres -c "CREATE USER portal WITH PASSWORD 'PORTAL_DB_PASSWORD' CREATEDB;"
    C:\pgsql\bin\psql.exe -h 127.0.0.1 -U admin -d postgres -c "CREATE DATABASE portal_db OWNER portal ENCODING 'UTF8';"
    $env:PGPASSWORD = "PORTAL_DB_PASSWORD"
    C:\pgsql\bin\psql.exe -h 127.0.0.1 -U portal -d portal_db -c "SELECT 1"

  أو بالسكربت (نسخة محدّثة):
    cd C:\JawishDeploy\server-tools
    .\setup-portable-postgres.ps1 -PgRoot C:\pgsql -SuperUser admin -SuperUserPassword "ADMIN_PASSWORD" -DbPassword "PORTAL_DB_PASSWORD"

--- السيرفر: الخطوة 4 — جداول + مدير ---
  PowerShell عادي:
    cd D:\JawishPortal
    php scripts\setup-database.php
    php scripts\run-migrations.php
    php scripts\create-admin.php admin SITE_ADMIN_PASSWORD مدير_النظام
    php scripts\check-environment.php

--- السيرفر: الخطوة 5 — PHP لـ IIS ---
  PowerShell كمسؤول:
    cd C:\JawishDeploy\server-tools
    .\fix-windows-php.ps1 -ApplyFix -PgBinDir C:\pgsql\bin
    .\install-iis-php-handler.ps1 -SitePort 90 -PhpCgiPath C:\php\php-cgi.exe -SiteName JawishPortal
    icacls D:\JawishPortal\storage /grant "IIS AppPool\JawishPortal:(OI)(CI)M" /T
    iisreset

--- السيرفر: الخطوة 6 — IIS (إن لم يُنشأ الموقع) ---
  IIS Manager (inetmgr):
    Site name: JawishPortal
    Physical path: D:\JawishPortal\public
    Binding: http 192.168.1.106:90

--- اختبار ---
  API:  http://127.0.0.1:5000/api/health
  Site: http://192.168.1.106:90
  Login: /dashboard/  user: admin

.env على السيرفر (D:\JawishPortal\.env):
  PORTAL_PUBLISH_DIR: {0}
  API: {1}
  Site URL: {2}

دليل كامل على جهاز التطوير: deploy\WINDOWS-IIS-LOCAL.md
'@ -f $serverPublishDir, $apiUrl, $portalAppUrl

$utf8Bom = New-Object System.Text.UTF8Encoding $true
[System.IO.File]::WriteAllText((Join-Path $OutputRoot 'SERVER-STEPS.txt'), $steps, $utf8Bom)

Write-Ok 'Server package ready'
Write-Host ''
Write-Host "  Folder: $OutputRoot" -ForegroundColor Green
Write-Host '  Copy to USB / server, then follow SERVER-STEPS.txt' -ForegroundColor Gray
Write-Host ''
