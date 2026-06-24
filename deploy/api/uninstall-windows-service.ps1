#Requires -Version 5.1
#Requires -RunAsAdministrator
param(
    [string]$ServiceName = 'JawishExistingDbApi',
    [string]$ApiDir = ''
)

$ErrorActionPreference = 'Stop'

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $svc) {
    Write-Host "Service not found: $ServiceName"
    exit 0
}

if ($svc.Status -eq 'Running') {
    Stop-Service -Name $ServiceName -Force
}

sc.exe delete $ServiceName | Out-Null
Write-Host "[OK] Removed service: $ServiceName"

if ($ApiDir) {
    $starter = Join-Path $ApiDir 'start-api-service.ps1'
    if (Test-Path $starter) {
        Remove-Item $starter -Force
        Write-Host "[OK] Removed $starter"
    }
}
