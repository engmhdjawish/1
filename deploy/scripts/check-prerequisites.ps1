#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

Write-Step 'Checking deploy prerequisites'
$missing = $false

function Test-Tool {
    param([string]$Name, [string]$Label, [scriptblock]$Version = $null)
    if (Test-CommandExists $Name) {
        $v = if ($Version) { & $Version } else { 'found' }
        Write-Ok "$Label`: $v"
    } else {
        Write-Fail "$Label`: not found ($Name)"
        $script:missing = $true
    }
}

Test-Tool dotnet '.NET SDK' { dotnet --version }
Test-Tool php 'PHP' { php -v | Select-Object -First 1 }

$composerInvocation = Get-ComposerInvocation
if ($composerInvocation) {
    if ($composerInvocation.Executable -eq 'composer') {
        Write-Ok "Composer: $((composer --version) -split "`n" | Select-Object -First 1)"
    } else {
        Write-Ok "Composer: $($composerInvocation.Args[0])"
    }
} else {
    Write-Warn 'Composer: not in PATH (deploy will download composer.phar automatically)'
}

$ini = Get-PhpIniPath
if ($ini) {
    Write-Host "  php.ini: $ini" -ForegroundColor DarkGray
}

if (Test-CommandExists psql) {
    Write-Ok "PostgreSQL client: $((psql --version) -split "`n" | Select-Object -First 1)"
} else {
    Write-Warn 'psql not found - needed for portal SQL migrations'
}

@('pdo_pgsql', 'curl', 'mbstring', 'openssl', 'gd') | ForEach-Object {
    $ext = $_
    $loaded = php -r "echo extension_loaded('$ext') ? '1' : '0';"
    if ($loaded -eq '1') {
        Write-Ok "PHP extension: $ext"
    } else {
        Write-Fail "Missing PHP extension: $ext"
        $missing = $true
    }
}

if ($missing) {
    if ($env:OS -match 'Windows') {
        Write-Warn 'Run: .\deploy\scripts\fix-windows-php.ps1'
    }
}

if (Test-Path (Join-Path $RepoRoot 'ExistingDbWebApi.sln')) {
    Write-Ok 'API solution: ExistingDbWebApi.sln'
} else {
    Write-Fail 'ExistingDbWebApi.sln not found'
    $missing = $true
}

if (Test-Path (Join-Path $RepoRoot 'portal\public')) {
    Write-Ok 'Portal web root: portal\public'
} else {
    Write-Fail 'portal\public not found'
    $missing = $true
}

if ($missing) {
    Write-Fail 'Some prerequisites are missing'
    exit 1
}

Write-Ok 'All core prerequisites are available'
exit 0
