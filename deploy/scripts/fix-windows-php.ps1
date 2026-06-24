#Requires -Version 5.1
<#
.SYNOPSIS
  Check and optionally fix required PHP extensions on Windows (CLI + IIS FastCGI).

.EXAMPLE
  .\fix-windows-php.ps1
  .\fix-windows-php.ps1 -ApplyFix -PgBinDir D:\PostgreSQL\bin
#>
param(
    [switch]$ApplyFix,
    [string]$PgBinDir = ''
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

function Test-PhpExtensionLoaded {
    param([string]$PhpExe, [string]$Extension)
    $result = & $PhpExe -r "echo extension_loaded('$Extension') ? '1' : '0';" 2>$null
    return $result -eq '1'
}

function Get-PhpDirectory {
    param([string]$PhpExe)
    return Split-Path (Resolve-Path $PhpExe).Path -Parent
}

function Find-PostgresBinDir {
    param([string]$Preferred)

    if ($Preferred -and (Test-Path (Join-Path $Preferred 'libpq.dll'))) {
        return (Resolve-Path $Preferred).Path
    }

    $candidates = @(
        'D:\PgSQL\bin',
        'D:\PostgreSQL\bin',
        'C:\PostgreSQL\bin',
        'C:\Program Files\PostgreSQL\16\bin',
        'C:\Program Files\PostgreSQL\15\bin'
    )
    foreach ($dir in $candidates) {
        if (Test-Path (Join-Path $dir 'libpq.dll')) {
            return (Resolve-Path $dir).Path
        }
    }
    return ''
}

function Enable-PhpIniExtensions {
    param(
        [string]$IniPath,
        [string[]]$Extensions
    )

    $content = Get-Content -LiteralPath $IniPath -Raw
    $changed = $false

    foreach ($ext in $Extensions) {
        $pattern = "(?m)^\s*;\s*extension\s*=\s*$([regex]::Escape($ext))\s*$"
        if ($content -match $pattern) {
            $content = [regex]::Replace($content, $pattern, "extension=$ext")
            $changed = $true
            continue
        }
        if ($content -notmatch "(?m)^\s*extension\s*=\s*$([regex]::Escape($ext))\s*$") {
            $content = $content.TrimEnd() + "`r`nextension=$ext`r`n"
            $changed = $true
        }
    }

    if ($content -notmatch '(?m)^\s*extension_dir\s*=') {
        $content = $content -replace '(?m)^(\s*;\s*Directory in which the loadable extensions)', "`$1`r`nextension_dir = `"ext`"`r`n; `$1"
        if ($content -notmatch '(?m)^\s*extension_dir\s*=') {
            $content = $content.TrimEnd() + "`r`nextension_dir = `"ext`"`r`n"
        }
        $changed = $true
    } elseif ($content -match '(?m)^\s*;\s*extension_dir\s*=') {
        $content = [regex]::Replace($content, '(?m)^\s*;\s*(extension_dir\s*=.*)$', '$1')
        $changed = $true
    }

    if ($changed) {
        Set-Content -LiteralPath $IniPath -Value $content -NoNewline
    }
    return $changed
}

Write-Step 'PHP configuration check (Windows)'

if (-not (Test-CommandExists php)) {
    Write-Fail 'PHP not found in PATH'
    exit 1
}

$phpCli = (Get-Command php).Source
$phpDir = Get-PhpDirectory -PhpExe $phpCli
$phpCgi = Join-Path $phpDir 'php-cgi.exe'
$hasCgi = Test-Path $phpCgi

Write-Host "PHP CLI:  $phpCli" -ForegroundColor Gray
if ($hasCgi) {
    Write-Host "PHP CGI:  $phpCgi (used by IIS)" -ForegroundColor Gray
} else {
    Write-Warn 'php-cgi.exe not found next to php.exe — IIS FastCGI will not work'
}
Write-Host ''

$ini = Get-PhpIniPath
if (-not $ini -or -not (Test-Path $ini)) {
    $devIni = Join-Path $phpDir 'php.ini-development'
    $prodIni = Join-Path $phpDir 'php.ini-production'
    if ($ApplyFix -and (Test-Path $devIni)) {
        Copy-Item $devIni (Join-Path $phpDir 'php.ini') -Force
        $ini = Join-Path $phpDir 'php.ini'
        Write-Ok "Created php.ini from php.ini-development"
    } else {
        Write-Fail 'Could not locate php.ini'
        if (Test-Path $devIni) {
            Write-Host "  Copy one of these to php.ini in the PHP folder:" -ForegroundColor Yellow
            Write-Host "    copy `"$devIni`" `"$phpDir\php.ini`"" -ForegroundColor White
        }
        exit 1
    }
}

Write-Host "php.ini: $ini" -ForegroundColor Gray

$libpqInPhp = Join-Path $phpDir 'libpq.dll'
if (Test-Path $libpqInPhp) {
    Write-Ok "libpq.dll found in PHP folder"
} else {
    Write-Fail 'libpq.dll missing next to php.exe (required for pdo_pgsql on Windows)'
    $pgBin = Find-PostgresBinDir -Preferred $PgBinDir
    if ($pgBin) {
        Write-Host "  Found PostgreSQL bin: $pgBin" -ForegroundColor Gray
        if ($ApplyFix) {
            Copy-Item (Join-Path $pgBin 'libpq.dll') $libpqInPhp -Force
            Write-Ok 'Copied libpq.dll into PHP folder'
        } else {
            Write-Host "  Run with -ApplyFix -PgBinDir `"$pgBin`" to copy automatically" -ForegroundColor Yellow
        }
    } else {
        Write-Host '  Copy libpq.dll from PostgreSQL bin (e.g. D:\PostgreSQL\bin) next to php.exe' -ForegroundColor Yellow
    }
}

Write-Host ''

$required = @('pdo_pgsql', 'curl', 'mbstring', 'openssl', 'gd')
$missingCli = @()
$missingCgi = @()

foreach ($ext in $required) {
    $cliOk = Test-PhpExtensionLoaded -PhpExe $phpCli -Extension $ext
    $cgiOk = $false
    if ($hasCgi) {
        $cgiOk = Test-PhpExtensionLoaded -PhpExe $phpCgi -Extension $ext
    }

    if ($cliOk -and (-not $hasCgi -or $cgiOk)) {
        Write-Ok "extension: $ext (CLI" + ($(if ($hasCgi) { ' + CGI' } else { '' })) + ')'
    } else {
        if (-not $cliOk) { $missingCli += $ext }
        if ($hasCgi -and -not $cgiOk) { $missingCgi += $ext }
        Write-Fail "extension missing: $ext (CLI=$cliOk CGI=$cgiOk)"
    }
}

if ($missingCli.Count -eq 0 -and $missingCgi.Count -eq 0) {
    Write-Ok 'All required PHP extensions are enabled for CLI and IIS'
    exit 0
}

if ($ApplyFix) {
    Write-Step 'Applying php.ini fixes'
    $toEnable = @($missingCli + $missingCgi | Select-Object -Unique)
    if (Enable-PhpIniExtensions -IniPath $ini -Extensions $toEnable) {
        Write-Ok 'Updated php.ini'
    } else {
        Write-Warn 'php.ini already had extension lines — verify manually'
    }

    if (-not (Test-Path $libpqInPhp)) {
        $pgBin = Find-PostgresBinDir -Preferred $PgBinDir
        if ($pgBin) {
            Copy-Item (Join-Path $pgBin 'libpq.dll') $libpqInPhp -Force
            Write-Ok 'Copied libpq.dll into PHP folder'
        }
    }

    Write-Step 'Re-checking extensions after fix'
    $stillMissing = @()
    foreach ($ext in $required) {
        if (-not (Test-PhpExtensionLoaded -PhpExe $phpCli -Extension $ext)) {
            $stillMissing += $ext
        }
        if ($hasCgi -and -not (Test-PhpExtensionLoaded -PhpExe $phpCgi -Extension $ext)) {
            if ($stillMissing -notcontains $ext) { $stillMissing += $ext }
        }
    }

    if ($stillMissing.Count -eq 0) {
        Write-Ok 'Extensions loaded after fix'
        Write-Host ''
        Write-Warn 'Restart IIS so the site picks up changes:'
        Write-Host '  iisreset' -ForegroundColor White
        exit 0
    }

    Write-Fail "Still missing after -ApplyFix: $($stillMissing -join ', ')"
}

Write-Host ''
Write-Warn 'Enable missing extensions in php.ini, then restart IIS:'
Write-Host ''
foreach ($ext in ($missingCli + $missingCgi | Select-Object -Unique)) {
    Write-Host "  extension=$ext" -ForegroundColor Yellow
}
Write-Host ''
Write-Host 'Quick fix (run as Administrator):' -ForegroundColor Cyan
Write-Host "  .\fix-windows-php.ps1 -ApplyFix -PgBinDir D:\PostgreSQL\bin" -ForegroundColor White
Write-Host ''
Write-Host 'Manual steps:' -ForegroundColor Cyan
Write-Host '  1) notepad "' + $ini + '"' -ForegroundColor Gray
Write-Host '  2) Uncomment extension=pdo_pgsql, mbstring, openssl, curl, gd' -ForegroundColor Gray
Write-Host '  3) Set: extension_dir = "ext"' -ForegroundColor Gray
Write-Host '  4) Copy D:\PostgreSQL\bin\libpq.dll next to php.exe' -ForegroundColor Gray
Write-Host '  5) php -m | findstr pdo_pgsql' -ForegroundColor Gray
Write-Host '  6) iisreset' -ForegroundColor Gray
exit 1
