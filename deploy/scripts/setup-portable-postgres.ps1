#Requires -Version 5.1
<#
.SYNOPSIS
  Initialize portable PostgreSQL (ZIP binaries) for Jawish portal on Windows.

.EXAMPLE
  .\deploy\scripts\setup-portable-postgres.ps1 -PgRoot D:\PostgreSQL -DbPassword "MySecret"
#>
param(
    [string]$PgRoot = 'D:\PostgreSQL',
    [string]$DataDir = '',
    [string]$DbUser = 'portal',
    [string]$DbPassword = 'portal',
    [string]$DbName = 'portal_db',
    [int]$Port = 5432
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

if (-not $DataDir) {
    $DataDir = Join-Path $PgRoot 'data'
}

$initdb = Join-Path $PgRoot 'bin\initdb.exe'
$pgctl = Join-Path $PgRoot 'bin\pg_ctl.exe'
$psql = Join-Path $PgRoot 'bin\psql.exe'
$createdb = Join-Path $PgRoot 'bin\createdb.exe'
$createuser = Join-Path $PgRoot 'bin\createuser.exe'

foreach ($tool in @($initdb, $pgctl, $psql)) {
    if (-not (Test-Path $tool)) {
        Write-Fail "Not found: $tool"
        Write-Host ''
        Write-Host 'Extract postgresql-*-windows-x64-binaries.zip to PgRoot (e.g. D:\PostgreSQL)' -ForegroundColor Yellow
        Write-Host 'Download on another PC if blocked, copy via USB. See deploy\WINDOWS-IIS-LOCAL.md' -ForegroundColor Yellow
        exit 1
    }
}

Write-Step "PostgreSQL portable at $PgRoot"

if (-not (Test-Path $DataDir)) {
    Write-Step "Initializing data directory: $DataDir"
    New-Item -ItemType Directory -Path $DataDir -Force | Out-Null
    $env:PGDATA = $DataDir
    & $initdb -D $DataDir -U postgres -E UTF8 --locale=C
    if ($LASTEXITCODE -ne 0) {
        throw "initdb failed ($LASTEXITCODE)"
    }
    Write-Ok 'Data directory initialized'
} else {
    Write-Warn "Data directory already exists: $DataDir"
}

$conf = Join-Path $DataDir 'postgresql.conf'
if (Test-Path $conf) {
    $text = Get-Content $conf -Raw
    if ($text -notmatch "port\s*=\s*$Port") {
        Add-Content $conf "`nport = $Port"
        Write-Ok "Set port = $Port in postgresql.conf"
    }
}

Write-Step 'Starting PostgreSQL'
& $pgctl -D $DataDir -l (Join-Path $DataDir 'server.log') start
if ($LASTEXITCODE -ne 0) {
    Write-Warn 'pg_ctl start returned non-zero - server may already be running'
}

Start-Sleep -Seconds 2

$env:PGPASSWORD = 'postgres'
Write-Step "Creating role $DbUser and database $DbName"

$roleExists = & $psql -h 127.0.0.1 -p $Port -U postgres -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DbUser'" 2>$null
if ($roleExists -ne '1') {
    & $psql -h 127.0.0.1 -p $Port -U postgres -d postgres -c "CREATE USER $DbUser WITH PASSWORD '$DbPassword' CREATEDB;"
    if ($LASTEXITCODE -ne 0) { throw 'CREATE USER failed' }
    Write-Ok "User $DbUser created"
} else {
    Write-Warn "User $DbUser already exists"
    & $psql -h 127.0.0.1 -p $Port -U postgres -d postgres -c "ALTER USER $DbUser WITH PASSWORD '$DbPassword';" | Out-Null
}

$dbExists = & $psql -h 127.0.0.1 -p $Port -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$DbName'" 2>$null
if ($dbExists -ne '1') {
    & $psql -h 127.0.0.1 -p $Port -U postgres -d postgres -c "CREATE DATABASE $DbName OWNER $DbUser ENCODING 'UTF8';"
    if ($LASTEXITCODE -ne 0) { throw 'CREATE DATABASE failed' }
    Write-Ok "Database $DbName created"
} else {
    Write-Warn "Database $DbName already exists"
}

$env:PGPASSWORD = $DbPassword
$test = & $psql -h 127.0.0.1 -p $Port -U $DbUser -d $DbName -tAc 'SELECT 1' 2>$null
if ($test -eq '1') {
    Write-Ok "Connection OK: $DbUser@127.0.0.1:$Port/$DbName"
} else {
    Write-Fail 'Could not connect with portal user'
    exit 1
}

Write-Host ''
Write-Ok 'PostgreSQL is ready for portal deploy'
Write-Host ''
Write-Host 'Next steps:' -ForegroundColor Cyan
Write-Host '  1) Register as Windows service (optional):' -ForegroundColor Gray
Write-Host "     $pgctl register -N PortalPostgreSQL -D `"$DataDir`"" -ForegroundColor White
Write-Host '  2) Set deploy.env PORTAL_DB_* to match' -ForegroundColor Gray
Write-Host '  3) .\deploy\wizard.ps1 -Action portal -DbSetup fresh' -ForegroundColor Gray
Write-Host ''
Write-Host 'See deploy\WINDOWS-IIS-LOCAL.md for full IIS guide' -ForegroundColor Gray
