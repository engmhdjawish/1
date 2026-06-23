#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

Write-Step 'فحص متطلبات النشر'
$missing = $false

function Test-Tool {
    param([string]$Name, [string]$Label, [scriptblock]$Version = $null)
    if (Test-CommandExists $Name) {
        $v = if ($Version) { & $Version } else { 'موجود' }
        Write-Ok "$Label`: $v"
    } else {
        Write-Fail "$Label`: غير موجود ($Name)"
        $script:missing = $true
    }
}

Test-Tool dotnet '.NET SDK' { dotnet --version }
Test-Tool php 'PHP' { php -v | Select-Object -First 1 }
Test-Tool composer 'Composer' { composer --version | Select-Object -First 1 }

if (Test-CommandExists psql) {
    Write-Ok "PostgreSQL client: $((psql --version) -split "`n" | Select-Object -First 1)"
} else {
    Write-Warn 'psql غير موجود — مطلوب لترحيل قاعدة الموقع'
}

@('pdo_pgsql', 'curl', 'mbstring', 'openssl', 'gd') | ForEach-Object {
    $ext = $_
    $loaded = php -r "echo extension_loaded('$ext') ? '1' : '0';"
    if ($loaded -eq '1') {
        Write-Ok "امتداد PHP: $ext"
    } else {
        Write-Fail "امتداد PHP مفقود: $ext"
        $missing = $true
    }
}

if (Test-Path (Join-Path $RepoRoot 'ExistingDbWebApi.sln')) {
    Write-Ok 'حل API: ExistingDbWebApi.sln'
} else {
    Write-Fail 'لم يُعثر على ExistingDbWebApi.sln'
    $missing = $true
}

if (Test-Path (Join-Path $RepoRoot 'portal\public')) {
    Write-Ok 'مجلد الموقع: portal\public'
} else {
    Write-Fail 'لم يُعثر على portal\public'
    $missing = $true
}

if ($missing) {
    Write-Fail 'بعض المتطلبات ناقصة'
    exit 1
}

Write-Ok 'جميع المتطلبات الأساسية متوفرة'
exit 0
