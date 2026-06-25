#Requires -Version 5.1
<#
.SYNOPSIS
  Verify PWA files on the server (no HTTPS required).

.EXAMPLE
  .\server-verify-pwa-files.ps1
  .\server-verify-pwa-files.ps1 -PortalRoot D:\JawishPortal -SitePort 90
#>
param(
    [string]$PortalRoot = 'D:\JawishPortal',
    [int]$SitePort = 90
)

$ErrorActionPreference = 'Continue'
$public = Join-Path $PortalRoot 'public'

$required = @(
    'manifest.php',
    'manifest.webmanifest',
    'sw.js',
    'icons\icon-192.png',
    'icons\icon-512.png',
    'views\partials\head-icons.php'
)

Write-Host '=== PWA files on disk ===' -ForegroundColor Cyan
$allOk = $true
foreach ($rel in $required) {
    $path = if ($rel.StartsWith('views')) {
        Join-Path $PortalRoot $rel
    } else {
        Join-Path $public $rel
    }
    $ok = Test-Path $path
    if (-not $ok) { $allOk = $false }
    $label = if ($ok) { 'OK' } else { 'MISSING' }
    $color = if ($ok) { 'Green' } else { 'Red' }
    Write-Host "  $rel : $label" -ForegroundColor $color
}

Write-Host ''
Write-Host '=== Local HTTP test (no SSL) ===' -ForegroundColor Cyan
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$urls = @(
    "http://127.0.0.1:$SitePort/manifest.php",
    "http://127.0.0.1:$SitePort/icons/icon-192.png",
    "http://127.0.0.1:$SitePort/icons/icon-512.png"
)
foreach ($url in $urls) {
    try {
        $res = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15
        Write-Host "  $url -> $($res.StatusCode)" -ForegroundColor Green
    } catch {
        Write-Host "  $url -> FAILED ($($_.Exception.Message))" -ForegroundColor Red
        $allOk = $false
    }
}

Write-Host ''
if ($allOk) {
    Write-Host 'PWA files look good. Open https://jawish.ddns.net in Chrome, clear cache, wait 30s, then Chrome menu -> Install.' -ForegroundColor Green
} else {
    Write-Host 'Fix MISSING files: copy deploy\output\pwa-bundle to D:\JawishPortal' -ForegroundColor Yellow
    Write-Host '  robocopy C:\JawishDeploy\output\pwa-bundle D:\JawishPortal /E /XF .env' -ForegroundColor Yellow
}
