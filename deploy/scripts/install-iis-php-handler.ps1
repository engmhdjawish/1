#Requires -Version 5.1
<#
.SYNOPSIS
  Register PHP with IIS FastCGI (fixes HTTP 404.3 on .php files).

.EXAMPLE
  # Run as Administrator on the SERVER:
  .\install-iis-php-handler.ps1 -PhpCgiPath "C:\php\php-cgi.exe" -SiteName "JawishPortal"
#>
param(
    [string]$PhpCgiPath = '',
    [string]$SiteName = '',
    [int]$SitePort = 0
)

$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message)   { Write-Host "[OK] $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "[!] $Message" -ForegroundColor Yellow }
function Write-Fail([string]$Message) { Write-Host "[FAIL] $Message" -ForegroundColor Red }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator
)
if (-not $isAdmin) {
    Write-Fail 'Run this script as Administrator (elevated PowerShell).'
    exit 1
}

if (-not $PhpCgiPath) {
    if (Get-Command php -ErrorAction SilentlyContinue) {
        $phpDir = Split-Path (Get-Command php).Source -Parent
        $PhpCgiPath = Join-Path $phpDir 'php-cgi.exe'
    }
}
if (-not (Test-Path $PhpCgiPath)) {
    Write-Fail "php-cgi.exe not found: $PhpCgiPath"
    Write-Host 'Install PHP 8.2+ (VS16 x64) and pass -PhpCgiPath "C:\php\php-cgi.exe"' -ForegroundColor Yellow
    exit 1
}
$PhpCgiPath = (Resolve-Path $PhpCgiPath).Path
$phpDir = Split-Path $PhpCgiPath -Parent

Write-Step "Using PHP CGI: $PhpCgiPath"

Write-Step 'Enabling IIS CGI feature (if needed)'
try {
    $cgi = Get-WindowsOptionalFeature -Online -FeatureName IIS-CGI -ErrorAction SilentlyContinue
    if ($cgi -and $cgi.State -ne 'Enabled') {
        Enable-WindowsOptionalFeature -Online -FeatureName IIS-CGI -All -NoRestart | Out-Null
        Write-Ok 'IIS-CGI enabled'
    } else {
        Write-Ok 'IIS-CGI already enabled or not applicable'
    }
} catch {
    Write-Warn "Could not enable IIS-CGI via OptionalFeatures: $($_.Exception.Message)"
    Write-Host '  Try: dism /online /enable-feature /featurename IIS-CGI' -ForegroundColor Gray
}

$appcmd = Join-Path $env:windir 'system32\inetsrv\appcmd.exe'
if (-not (Test-Path $appcmd)) {
    Write-Fail 'appcmd.exe not found - is IIS installed?'
    exit 1
}

Write-Step 'Registering FastCGI application'
$fastCgiList = & $appcmd list config /section:system.webServer/fastCGI 2>&1 | Out-String
if ($fastCgiList -notmatch [regex]::Escape($PhpCgiPath)) {
    & $appcmd set config /section:system.webServer/fastCGI /+`"[fullPath='$PhpCgiPath']`" /commit:apphost | Out-Null
}
& $appcmd set config /section:system.webServer/fastCGI /+`"[fullPath='$PhpCgiPath'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']`" /commit:apphost 2>$null | Out-Null
& $appcmd set config /section:system.webServer/fastCGI /+`"[fullPath='$PhpCgiPath'].environmentVariables.[name='PHPRC',value='$phpDir']`" /commit:apphost 2>$null | Out-Null
Write-Ok 'FastCGI application registered'

if (-not $SiteName -and $SitePort -gt 0) {
    $sitesXml = & $appcmd list sites /xml
    [xml]$xml = $sitesXml
    foreach ($site in $xml.site) {
        foreach ($binding in $site.bindings.Collection) {
            if ($binding.bindingInformation -match ":$SitePort`:") {
                $SiteName = $site.name
                break
            }
        }
        if ($SiteName) { break }
    }
}
if (-not $SiteName) {
    $SiteName = 'Default Web Site'
    Write-Warn "Site name not specified - using: $SiteName"
}

Write-Step "Adding PHP handler to site: $SiteName"
$handlerXml = & $appcmd list config "$SiteName/" /section:handlers /xml 2>&1 | Out-String
if ($handlerXml -match 'path=''?\*\.php') {
    Write-Warn 'Handler for *.php already exists on this site - skipping add'
} else {
    $handlerResult = & $appcmd set config "$SiteName/" /section:handlers /+`"[name='PHP_via_FastCGI',path='*.php',verb='*',modules='FastCGIModule',scriptProcessor='$PhpCgiPath',resourceType='Either',requireAccess='Script']`" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Fail "Could not add PHP handler: $handlerResult"
        exit 1
    }
    Write-Ok 'Handler *.php -> FastCGI added'
}

Write-Step 'Setting default document index.php'
& $appcmd set config "$SiteName/" /section:defaultDocument /+files.[value='index.php'] 2>$null | Out-Null

Write-Step 'Restarting IIS'
& $env:windir\system32\iisreset.exe /restart | Out-Null
Write-Ok 'IIS restarted'

Write-Host ''
Write-Ok 'PHP handler configured'
Write-Host "  Site: $SiteName" -ForegroundColor Gray
if ($SitePort -gt 0) {
    Write-Host "  Test: http://localhost:$SitePort/index.php" -ForegroundColor Gray
} else {
    Write-Host '  Test: http://localhost:<your-port>/index.php' -ForegroundColor Gray
}
Write-Host ''
Write-Host 'CLI test (optional): php -v' -ForegroundColor Gray
Write-Host "If errors persist, enable in php.ini: extension_dir, pdo_pgsql, mbstring" -ForegroundColor Yellow
Write-Host "  php.ini folder: $phpDir" -ForegroundColor Gray
