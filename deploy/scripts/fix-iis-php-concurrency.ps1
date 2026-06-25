#Requires -Version 5.1
<#
.SYNOPSIS
  Increase IIS FastCGI PHP concurrency so one slow page does not block the whole site.

.EXAMPLE
  # Run as Administrator on the SERVER:
  .\fix-iis-php-concurrency.ps1 -PhpCgiPath "C:\php\php-cgi.exe" -SiteName "JawishPortal"
#>
param(
    [string]$PhpCgiPath = 'C:\php\php-cgi.exe',
    [string]$SiteName = 'JawishPortal',
    [int]$MaxInstances = 8,
    [int]$RequestTimeout = 90
)

$ErrorActionPreference = 'Stop'

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator
)
if (-not $isAdmin) {
    Write-Host '[FAIL] Run as Administrator.' -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $PhpCgiPath)) {
    Write-Host "[FAIL] php-cgi not found: $PhpCgiPath" -ForegroundColor Red
    exit 1
}

$PhpCgiPath = (Resolve-Path $PhpCgiPath).Path
$appcmd = Join-Path $env:windir 'system32\inetsrv\appcmd.exe'
if (-not (Test-Path $appcmd)) {
    Write-Host '[FAIL] appcmd.exe not found - is IIS installed?' -ForegroundColor Red
    exit 1
}

Write-Host "==> FastCGI: $PhpCgiPath (maxInstances=$MaxInstances)" -ForegroundColor Cyan
$escaped = $PhpCgiPath -replace "'", "''"
& $appcmd set config -section:system.webServer/fastCGI "/[fullPath='$escaped'].maxInstances:$MaxInstances" /commit:apphost | Out-Null
& $appcmd set config -section:system.webServer/fastCGI "/[fullPath='$escaped'].instanceMaxRequests:10000" /commit:apphost | Out-Null
& $appcmd set config -section:system.webServer/fastCGI "/[fullPath='$escaped'].activityTimeout:600" /commit:apphost | Out-Null
& $appcmd set config -section:system.webServer/fastCGI "/[fullPath='$escaped'].requestTimeout:$RequestTimeout" /commit:apphost | Out-Null

$poolName = $SiteName
$poolXml = & $appcmd list apppool /name:$poolName /xml 2>$null
if (-not $poolXml) {
    $poolName = 'DefaultAppPool'
    Write-Host "[!] App pool $SiteName not found — using $poolName" -ForegroundColor Yellow
}

Write-Host "==> App pool: $poolName (queueLength=5000)" -ForegroundColor Cyan
& $appcmd set apppool $poolName /queueLength:5000 | Out-Null
& $appcmd set apppool $poolName /processModel.idleTimeout:00:20:00 | Out-Null

Write-Host '==> Restarting IIS' -ForegroundColor Cyan
& $env:windir\system32\iisreset.exe /restart | Out-Null

Write-Host '[OK] PHP concurrency updated. Slow store requests should no longer block all visitors.' -ForegroundColor Green
