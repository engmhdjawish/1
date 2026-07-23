#Requires -Version 5.1
<#
.SYNOPSIS
  Run website/API load simulation (Node runner).

.EXAMPLE
  .\loadtest\run.ps1 -Scenario api-browse -BaseUrl http://127.0.0.1:5249 `
    -Username portal-service -Password 'Secret' -Vus 20 -Duration 60

.EXAMPLE
  .\loadtest\run.ps1 -Scenario site-browse -SiteUrl http://127.0.0.1:8080 -Vus 30 -Duration 90
#>
param(
    [ValidateSet('api-browse', 'site-browse')]
    [string]$Scenario = 'api-browse',
    [string]$BaseUrl = $(if ($env:LOADTEST_BASE_URL) { $env:LOADTEST_BASE_URL } else { 'http://127.0.0.1:5249' }),
    [string]$SiteUrl = $(if ($env:LOADTEST_SITE_URL) { $env:LOADTEST_SITE_URL } else { 'http://127.0.0.1:8080' }),
    [string]$Username = $(if ($env:LOADTEST_USERNAME) { $env:LOADTEST_USERNAME } else { '' }),
    [string]$Password = $(if ($env:LOADTEST_PASSWORD) { $env:LOADTEST_PASSWORD } else { '' }),
    [int]$Vus = 10,
    [int]$Duration = 60,
    [int]$RampUp = 10,
    [int]$ThinkMs = 200,
    [switch]$Insecure
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$runner = Join-Path $PSScriptRoot 'run.mjs'

$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $node) {
    Write-Host '[FAIL] Node.js is required. Install Node 18+ and retry.' -ForegroundColor Red
    exit 1
}

$nodeArgs = @(
    $runner,
    '--scenario', $Scenario,
    '--base-url', $BaseUrl,
    '--site-url', $SiteUrl,
    '--vus', "$Vus",
    '--duration', "$Duration",
    '--ramp-up', "$RampUp",
    '--think-ms', "$ThinkMs"
)

if ($Username) { $nodeArgs += @('--username', $Username) }
if ($Password) { $nodeArgs += @('--password', $Password) }
if ($Insecure) { $nodeArgs += '--insecure' }

Write-Host "==> node $($nodeArgs -join ' ')" -ForegroundColor Cyan
& node @nodeArgs
exit $LASTEXITCODE
