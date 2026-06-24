# Shared helpers for deploy scripts (Windows PowerShell)

$ErrorActionPreference = 'Stop'

$script:DeployRoot = Split-Path -Parent $PSScriptRoot
$script:RepoRoot = Split-Path -Parent $script:DeployRoot

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message)   { Write-Host "[OK] $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "[!] $Message" -ForegroundColor Yellow }
function Write-Fail([string]$Message) { Write-Host "[FAIL] $Message" -ForegroundColor Red }

function Get-DeployRoot { $script:DeployRoot }
function Get-RepoRoot   { $script:RepoRoot }

function Test-CommandExists([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Get-PhpIniPath {
    $ini = php --ini 2>$null | Select-String 'Loaded Configuration File' | ForEach-Object {
        ($_ -split ':', 2)[1].Trim()
    } | Select-Object -First 1
    return $ini
}

function Get-ComposerInvocation {
    if (Test-CommandExists 'composer') {
        return @{ Executable = 'composer'; Args = @() }
    }

    $candidates = @(
        (Join-Path $script:DeployRoot 'composer.phar'),
        (Join-Path $script:RepoRoot 'composer.phar'),
        (Join-Path $script:RepoRoot 'portal\composer.phar')
    )
    foreach ($path in $candidates) {
        if (Test-Path $path) {
            return @{ Executable = 'php'; Args = @($path) }
        }
    }

    return $null
}

function Install-ComposerPhar {
  param([string]$TargetPath = (Join-Path $script:DeployRoot 'composer.phar'))

    if (Test-Path $TargetPath) {
        return $TargetPath
    }

    Write-Step 'Downloading composer.phar'
    $installer = Join-Path $env:TEMP ('composer-setup-' + [guid]::NewGuid().ToString() + '.php')
    try {
        Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $installer -UseBasicParsing
        $installDir = Split-Path -Parent $TargetPath
        if (-not (Test-Path $installDir)) {
            New-Item -ItemType Directory -Path $installDir -Force | Out-Null
        }
        & php $installer --install-dir=$installDir --filename=$(Split-Path -Leaf $TargetPath)
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $TargetPath)) {
            throw 'Failed to download composer.phar'
        }
        Write-Ok "composer.phar ready at $TargetPath"
        return $TargetPath
    } finally {
        Remove-Item $installer -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-ComposerInstall {
    param([string]$WorkingDirectory)

    $invocation = Get-ComposerInvocation
    if (-not $invocation) {
        $phar = Install-ComposerPhar
        $invocation = @{ Executable = 'php'; Args = @($phar) }
    }

    Push-Location $WorkingDirectory
    try {
        $args = @($invocation.Args + @('install', '--no-dev', '--optimize-autoloader', '--no-interaction'))
        Write-Step "Running: $($invocation.Executable) $($args -join ' ')"
        & $invocation.Executable @args
        if ($LASTEXITCODE -ne 0) {
            throw "composer install failed (exit $LASTEXITCODE)"
        }
        Write-Ok 'Composer dependencies installed'
    } finally {
        Pop-Location
    }
}

function Get-DefaultPortalPublishDir {
    if ($env:OS -match 'Windows') {
        return 'D:\JawishPortal'
    }
    return '/var/www/jawish-portal'
}

function Get-DefaultApiPublishDir {
    if ($env:OS -match 'Windows') {
        return 'D:\AmeenApi\existingdb-api'
    }
    return '/var/www/existingdb-api'
}

function Read-DeployEnv {
    param([string]$Path = (Join-Path $script:DeployRoot 'deploy.env'))
    if (-not (Test-Path $Path)) {
        throw "Config file not found: $Path - run the wizard first or copy deploy.env.example"
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
    $utf8Bom = New-Object System.Text.UTF8Encoding $true
    [System.IO.File]::WriteAllLines($Path, $newLines, $utf8Bom)
}

function New-RandomSecret {
    $bytes = New-Object byte[] 36
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return [Convert]::ToBase64String($bytes).Replace('+', '').Replace('/', '').Substring(0, 48)
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
    $utf8Bom = New-Object System.Text.UTF8Encoding $true
    [System.IO.File]::WriteAllText($OutputPath, $content, $utf8Bom)
    Write-Ok "Created $OutputPath"
}

function Copy-PortalTree {
    param(
        [string]$Destination,
        [string]$Source = (Join-Path $script:RepoRoot 'portal')
    )
    Write-Step "Copying portal files to $Destination"
    if (-not (Test-Path $Destination)) {
        New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    }
    robocopy $Source $Destination /MIR /XD storage vendor .git /XF .env amine-api-token.json `
        /NFL /NDL /NJH /NJS /NC /NS | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "Portal copy failed (robocopy exit $LASTEXITCODE)"
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
        if (-not (Test-Path $p)) {
            New-Item -ItemType Directory -Path $p -Force | Out-Null
        }
    }
    Write-Ok 'Portal files copied'
}

function Write-PortalEnv {
    param(
        [string]$Destination,
        [hashtable]$Env
    )
    $envPath = Join-Path $Destination '.env'
    Write-Step "Creating $envPath"
    $content = @"
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
"@
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($envPath, $content, $utf8NoBom)
    Write-Ok 'Created .env'
}
