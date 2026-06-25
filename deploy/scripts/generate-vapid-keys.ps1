#Requires -Version 5.1
<#
.SYNOPSIS
  Generate VAPID keys for Web Push (fallback when PHP openssl_pkey_new fails).

.EXAMPLE
  cd C:\Users\HP\1
  .\deploy\scripts\generate-vapid-keys.ps1
#>
param(
    [string]$PortalDir = ''
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

if (-not $PortalDir) {
    $PortalDir = Join-Path $RepoRoot 'portal'
}

$phpScript = Join-Path $PortalDir 'scripts\generate-vapid-keys.php'
if (-not (Test-Path $phpScript)) {
    Write-Fail "Missing $phpScript"
    exit 1
}

$php = $null
if (Test-CommandExists 'php') {
    $php = 'php'
} elseif (Test-Path 'C:\php\php.exe') {
    $php = 'C:\php\php.exe'
} else {
    Write-Fail 'PHP not found. Add php.exe to PATH or install under C:\php\'
    exit 1
}

Write-Step "Running PHP VAPID generator via: $php"
& $php $phpScript
if ($LASTEXITCODE -eq 0) {
    exit 0
}

Write-Warn 'PHP generator failed; trying openssl.exe + PHP PEM parser...'

$openssl = $null
if (Test-CommandExists 'openssl') {
    $openssl = 'openssl'
} else {
    $candidates = @(
        'C:\php\extras\ssl\openssl.exe',
        'C:\Program Files\Git\usr\bin\openssl.exe',
        'C:\Program Files\OpenSSL-Win64\bin\openssl.exe'
    )
    foreach ($path in $candidates) {
        if (Test-Path $path) {
            $openssl = $path
            break
        }
    }
}

if (-not $openssl) {
    Write-Fail 'openssl.exe not found. Install Git for Windows or copy extras\ssl from PHP package.'
    exit 1
}

$tmp = Join-Path $env:TEMP ("portal-vapid-" + [guid]::NewGuid().ToString() + '.pem')
try {
    & $openssl ecparam -name prime256v1 -genkey -noout -out $tmp 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $tmp)) {
        throw 'openssl ecparam failed'
    }

    Write-Step "Parsing PEM with PHP: $php"
    & $php $phpScript $tmp
    if ($LASTEXITCODE -ne 0) {
        throw 'PHP could not parse PEM key'
    }
    Write-Ok 'VAPID keys generated via openssl CLI + PHP'
} catch {
    Write-Fail $_.Exception.Message
    exit 1
} finally {
    Remove-Item $tmp -Force -ErrorAction SilentlyContinue
}
