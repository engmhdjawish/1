#Requires -Version 5.1
<#
.SYNOPSIS
  Install portal PHP dependencies without global Composer in PATH.

.EXAMPLE
  cd C:\Users\HP\1
  .\deploy\scripts\portal-composer-install.ps1

  Then generate VAPID keys:
  cd portal
  php scripts\generate-vapid-keys.php
#>
param(
    [string]$PortalDir = ''
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

if (-not $PortalDir) {
    $PortalDir = Join-Path $RepoRoot 'portal'
}
if (-not (Test-Path (Join-Path $PortalDir 'composer.json'))) {
    Write-Fail "composer.json not found in: $PortalDir"
    exit 1
}

Write-Step "Portal directory: $PortalDir"
Invoke-ComposerInstall -WorkingDirectory $PortalDir
Write-Ok 'Done. You can now run: php scripts\generate-vapid-keys.php'
