#Requires -Version 5.1
<#
.SYNOPSIS
  Show how to enable required PHP extensions on Windows for Jawish portal deploy.
#>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

Write-Step 'PHP configuration check (Windows)'

if (-not (Test-CommandExists php)) {
    Write-Fail 'PHP not found in PATH'
    exit 1
}

$ini = Get-PhpIniPath
if (-not $ini -or -not (Test-Path $ini)) {
    Write-Fail 'Could not locate php.ini'
    exit 1
}

Write-Host "php.ini: $ini" -ForegroundColor Gray
Write-Host ''

$required = @('pdo_pgsql', 'curl', 'mbstring', 'openssl', 'gd')
$missing = @()
foreach ($ext in $required) {
    $loaded = php -r "echo extension_loaded('$ext') ? '1' : '0';"
    if ($loaded -eq '1') {
        Write-Ok "extension: $ext"
    } else {
        Write-Fail "extension missing: $ext"
        $missing += $ext
    }
}

if ($missing.Count -eq 0) {
    Write-Ok 'All required PHP extensions are enabled'
    exit 0
}

Write-Host ''
Write-Warn 'Enable missing extensions in php.ini, then restart any open shells / IIS:'
Write-Host ''
foreach ($ext in $missing) {
    Write-Host "  extension=$ext" -ForegroundColor Yellow
}
Write-Host ''
Write-Host 'Typical steps:' -ForegroundColor Cyan
Write-Host '  1) Open php.ini as Administrator:' -ForegroundColor Gray
Write-Host "     notepad `"$ini`"" -ForegroundColor White
Write-Host '  2) Search for the extension name and remove the leading ;' -ForegroundColor Gray
Write-Host '  3) If extension= line is missing, add it under [Extensions]' -ForegroundColor Gray
Write-Host '  4) Save, then run: php -m | findstr mbstring' -ForegroundColor Gray
Write-Host '  5) Restart IIS: iisreset' -ForegroundColor Gray
Write-Host ''
Write-Host 'Composer (if missing from PATH):' -ForegroundColor Cyan
Write-Host '  Option A: https://getcomposer.org/Composer-Setup.exe' -ForegroundColor Gray
Write-Host '  Option B: deploy will auto-download composer.phar on next publish' -ForegroundColor Gray
exit 1
