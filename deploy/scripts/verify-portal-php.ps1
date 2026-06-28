#Requires -Version 5.1
<#
.SYNOPSIS
  Syntax-check key PHP files before packaging/deploy.

.EXAMPLE
  .\deploy\scripts\verify-portal-php.ps1
  .\deploy\scripts\verify-portal-php.ps1 -PortalDir C:\Users\HP\1\portal
#>
param(
    [string]$PortalDir = ''
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

if (-not $PortalDir) {
    $PortalDir = Join-Path $RepoRoot 'portal'
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Fail 'PHP not found in PATH. Install PHP or add it to PATH, then retry.'
    exit 1
}

$files = @(
    'views\helpers.php',
    'views\home.php',
    'views\layout.php',
    'views\store-catalog.php',
    'public\login.php',
    'public\index.php',
    'src\Services\StoreCartPricingService.php',
    'src\Support\StoreCartApi.php'
)

Write-Step "PHP syntax check -> $PortalDir"
$failed = @()
foreach ($rel in $files) {
    $path = Join-Path $PortalDir $rel
    if (-not (Test-Path $path)) {
        Write-Fail "Missing: $rel"
        $failed += $rel
        continue
    }
    $output = & php -l $path 2>&1
    if ($LASTEXITCODE -ne 0 -or ($output -notmatch 'No syntax errors')) {
        Write-Fail $rel
        Write-Host $output -ForegroundColor Red
        $failed += $rel
    } else {
        Write-Ok $rel
    }
}

$contentScan = @(
    Join-Path $PortalDir 'views\helpers.php',
    Join-Path $PortalDir 'views\home.php'
)
foreach ($path in $contentScan) {
    if (-not (Test-Path $path)) { continue }
    $text = Get-Content -Path $path -Raw -Encoding UTF8
    if ($text -match '<<<<<<<|=======|>>>>>>>') {
        Write-Fail "Merge conflict markers still present in $path"
        $failed += $path
    }
}

if ($failed.Count -gt 0) {
    Write-Fail "Fix the files above before deploy."
    exit 1
}

Write-Ok 'All checked PHP files are valid'
