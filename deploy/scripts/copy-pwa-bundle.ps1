#Requires -Version 5.1
<#
.SYNOPSIS
  Copy only PWA-critical files into a small folder for server deploy.

.EXAMPLE
  .\deploy\scripts\copy-pwa-bundle.ps1
  robocopy deploy\output\pwa-bundle D:\JawishPortal /E
#>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

$repoRoot = $script:RepoRoot
$portalSource = Join-Path $repoRoot 'portal'
$bundleRoot = Join-Path $DeployRoot 'output\pwa-bundle'

$relativeFiles = @(
    'public\manifest.webmanifest',
    'public\manifest.php',
    'public\pwa-check.php',
    'public\sw.js',
    'public\icons\icon-32.png',
    'public\icons\icon-180.png',
    'public\icons\icon-192.png',
    'public\icons\icon-512.png',
    'public\icons\icon-png.php',
    'public\assets\pwa.js',
    'public\css\pwa-install.css',
    'views\helpers.php',
    'views\layout.php',
    'views\partials\head-icons.php',
    'src\Bootstrap.php',
    'src\Support\HttpsGate.php'
)

Write-Step "Building PWA bundle -> $bundleRoot"
if (Test-Path $bundleRoot) {
    Remove-Item $bundleRoot -Recurse -Force
}

$missing = @()
foreach ($rel in $relativeFiles) {
    $src = Join-Path $portalSource $rel
    $dest = Join-Path $bundleRoot $rel
    if (-not (Test-Path $src)) {
        $missing += $rel
        continue
    }
    $destDir = Split-Path $dest -Parent
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item $src $dest -Force
}

if ($missing.Count -gt 0) {
    Write-Fail "Missing source files: $($missing -join ', ')"
    exit 1
}

$readme = @"
PWA bundle — copy to D:\JawishPortal

On the SERVER (PowerShell as Admin):

  robocopy C:\JawishDeploy\output\pwa-bundle D:\JawishPortal /E /XF .env

Then verify:

  Invoke-WebRequest -Uri https://jawish.ddns.net/icons/icon-192.png -UseBasicParsing | Select-Object StatusCode
  Invoke-WebRequest -Uri https://jawish.ddns.net/manifest.webmanifest -UseBasicParsing | Select-Object StatusCode

Both must be 200. Then open https://jawish.ddns.net/pwa-check.php
"@
Set-Content -Path (Join-Path $bundleRoot 'README-PWA.txt') -Value $readme -Encoding UTF8

Write-Ok "PWA bundle ready: $bundleRoot"
Write-Host $readme -ForegroundColor Cyan
