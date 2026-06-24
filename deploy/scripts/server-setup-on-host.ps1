#Requires -Version 5.1
<#
.SYNOPSIS
  Run ON THE SERVER after copying JawishPortal + docs (no Git required).

.EXAMPLE
  cd D:\JawishPortal
  C:\JawishDeploy\server-tools\server-setup-on-host.ps1 -PortalDir D:\JawishPortal
#>
param(
    [string]$PortalDir = 'D:\JawishPortal'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path (Join-Path $PortalDir 'bootstrap.php'))) {
    Write-Host "[FAIL] Portal not found: $PortalDir" -ForegroundColor Red
    exit 1
}

Push-Location $PortalDir
try {
    Write-Host '==> setup-database.php' -ForegroundColor Cyan
    php scripts/setup-database.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Write-Host '==> run-migrations.php' -ForegroundColor Cyan
    php scripts/run-migrations.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Write-Host '==> check-environment.php' -ForegroundColor Cyan
    php scripts/check-environment.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Write-Host '[OK] Database ready. Create admin if needed:' -ForegroundColor Green
    Write-Host '  php scripts/create-admin.php admin "Password" "مدير النظام"' -ForegroundColor Gray
} finally {
    Pop-Location
}
