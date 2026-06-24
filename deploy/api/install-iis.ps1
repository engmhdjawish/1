#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Configure IIS site for ExistingDb.Api.

.EXAMPLE
  .\deploy\api\install-iis.ps1 -ApiDir D:\AmeenApi\existingdb-api -Port 5000
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$ApiDir,
    [int]$Port = 5000,
    [string]$SiteName = 'AmeenApi',
    [string]$AppPoolName = 'AmeenApiPool'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

Import-Module WebAdministration -ErrorAction Stop

$ApiDir = (Resolve-Path $ApiDir).Path
$dll = Join-Path $ApiDir 'ExistingDb.Api.dll'
if (-not (Test-Path $dll)) {
    throw "ExistingDb.Api.dll not found in $ApiDir"
}

$logsDir = Join-Path $ApiDir 'logs'
if (-not (Test-Path $logsDir)) {
    New-Item -ItemType Directory -Path $logsDir -Force | Out-Null
}

$webConfig = Join-Path $ApiDir 'web.config'
$template = Join-Path $DeployRoot 'templates\api\web.config.template'
Copy-Item $template $webConfig -Force
Write-Ok "Created $webConfig (stdout logs: $logsDir)"

$existingService = Get-Service -Name 'JawishExistingDbApi' -ErrorAction SilentlyContinue
if ($existingService -and $existingService.Status -eq 'Running') {
    Write-Warn 'Stopping JawishExistingDbApi Windows service (same port conflict)'
    Stop-Service -Name 'JawishExistingDbApi' -Force
}

if (Test-Path "IIS:\AppPools\$AppPoolName") {
    Write-Warn "App pool exists: $AppPoolName"
} else {
    New-WebAppPool -Name $AppPoolName | Out-Null
}
Set-ItemProperty "IIS:\AppPools\$AppPoolName" -Name managedRuntimeVersion -Value ''
Set-ItemProperty "IIS:\AppPools\$AppPoolName" -Name startMode -Value 'AlwaysRunning'

if (Get-Website -Name $SiteName -ErrorAction SilentlyContinue) {
    Remove-Website -Name $SiteName
}
New-Website -Name $SiteName -PhysicalPath $ApiDir -Port $Port -ApplicationPool $AppPoolName | Out-Null

Write-Ok "IIS site ready: http://127.0.0.1:$Port"
Write-Host "Test:  http://127.0.0.1:$Port/api/health" -ForegroundColor Gray
Write-Host "Logs:  $logsDir\stdout_*.log (if 500.30, read the newest file)" -ForegroundColor Gray
Write-Host ''
Write-Host 'If SQL uses Trusted_Connection, set App Pool identity in IIS:' -ForegroundColor Yellow
Write-Host "  App Pools -> $AppPoolName -> Advanced Settings -> Identity -> Custom account (Windows user with SQL access)"
