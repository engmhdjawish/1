# Shared helpers for deploy scripts (Windows PowerShell)

$ErrorActionPreference = 'Stop'

$script:DeployRoot = Split-Path -Parent $PSScriptRoot
$script:RepoRoot = Split-Path -Parent $script:DeployRoot

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message)   { Write-Host "✓ $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "! $Message" -ForegroundColor Yellow }
function Write-Fail([string]$Message) { Write-Host "✗ $Message" -ForegroundColor Red }

function Get-DeployRoot { $script:DeployRoot }
function Get-RepoRoot   { $script:RepoRoot }

function Test-CommandExists([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Read-DeployEnv {
    param([string]$Path = (Join-Path $script:DeployRoot 'deploy.env'))
    if (-not (Test-Path $Path)) {
        throw "ملف الإعداد غير موجود: $Path — شغّل المعالج أولاً أو انسخ deploy.env.example"
    }
    $vars = @{}
    Get-Content $Path | ForEach-Object {
        if ($_ -match '^\s*#' -or $_ -match '^\s*$') { return }
        $parts = $_ -split '=', 2
        if ($parts.Count -eq 2) {
            $vars[$parts[0].Trim()] = $parts[1]
        }
    }
    return $vars
}

function Save-DeployEnvValue {
    param(
        [string]$Key,
        [string]$Value,
        [string]$Path = (Join-Path $script:DeployRoot 'deploy.env')
    )
    if (-not (Test-Path $Path)) {
        Copy-Item (Join-Path $script:DeployRoot 'deploy.env.example') $Path
    }
    $lines = Get-Content $Path
    $found = $false
    $newLines = foreach ($line in $lines) {
        if ($line -match "^$([regex]::Escape($Key))=") {
            $found = $true
            "$Key=$Value"
        } else {
            $line
        }
    }
    if (-not $found) {
        $newLines += "$Key=$Value"
    }
    Set-Content -Path $Path -Value $newLines -Encoding UTF8
}

function New-RandomSecret {
    $bytes = New-Object byte[] 36
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return [Convert]::ToBase64String($bytes).Replace('+','').Replace('/','').Substring(0, 48)
}

function Expand-Template {
    param(
        [string]$TemplatePath,
        [string]$OutputPath,
        [hashtable]$Variables
    )
    $content = Get-Content $TemplatePath -Raw
    foreach ($key in $Variables.Keys) {
        $token = "{{$key}}"
        $content = $content.Replace($token, [string]$Variables[$key])
    }
    $dir = Split-Path -Parent $OutputPath
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    Set-Content -Path $OutputPath -Value $content -Encoding UTF8
    Write-Ok "تم إنشاء $OutputPath"
}

function Copy-PortalTree {
    param(
        [string]$Destination,
        [string]$Source = (Join-Path $script:RepoRoot 'portal')
    )
    Write-Step "نسخ ملفات الموقع إلى $Destination"
    if (-not (Test-Path $Destination)) {
        New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    }
    $exclude = @('.env', 'vendor', '.git')
    robocopy $Source $Destination /MIR /XD storage vendor .git /XF .env amine-api-token.json `
        /NFL /NDL /NJH /NJS /NC /NS | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "فشل نسخ الموقع (robocopy exit $LASTEXITCODE)"
    }
    $storageDirs = @(
        'storage',
        'storage\material-images',
        'storage\material-images\thumbnails',
        'storage\site-media',
        'storage\fonts'
    )
    foreach ($d in $storageDirs) {
        $p = Join-Path $Destination $d
        if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
    }
    Write-Ok 'تم نسخ الموقع'
}

function Write-PortalEnv {
    param(
        [string]$Destination,
        [hashtable]$Env
    )
    $envPath = Join-Path $Destination '.env'
    Write-Step "إنشاء $envPath"
    @"
PORTAL_DB_HOST=$($Env.PORTAL_DB_HOST)
PORTAL_DB_PORT=$($Env.PORTAL_DB_PORT)
PORTAL_DB_NAME=$($Env.PORTAL_DB_NAME)
PORTAL_DB_USER=$($Env.PORTAL_DB_USER)
PORTAL_DB_PASSWORD=$($Env.PORTAL_DB_PASSWORD)

AMINE_API_BASE_URL=$($Env.API_URL)
AMINE_API_USERNAME=$($Env.AMINE_API_USERNAME)
AMINE_API_PASSWORD=$($Env.AMINE_API_PASSWORD)

PORTAL_APP_URL=$($Env.PORTAL_APP_URL)
PORTAL_SESSION_NAME=$($Env.PORTAL_SESSION_NAME)
PORTAL_STORAGE_PATH=$($Env.PORTAL_STORAGE_PATH)
PORTAL_REPO_DOCS_PATH=$($Env.PORTAL_REPO_DOCS_PATH)
PORTAL_DETAILS_FONT_PATH=$($Env.PORTAL_DETAILS_FONT_PATH)
"@ | Set-Content -Path $envPath -Encoding UTF8
    Write-Ok 'تم إنشاء .env'
}
