#Requires -Version 5.1
param(
    [string]$EnvFile
)
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\..\lib\common.ps1"

$envPath = if ($EnvFile) { $EnvFile } else { Join-Path $DeployRoot 'deploy.env' }
$vars = Read-DeployEnv -Path $envPath

$publishDir = $vars['API_PUBLISH_DIR']
if (-not $publishDir) { throw 'API_PUBLISH_DIR is not set in deploy.env' }

Write-Step "Publishing API to $publishDir"
if (-not (Test-CommandExists dotnet)) { throw 'dotnet not found' }
if (-not (Test-Path $publishDir)) { New-Item -ItemType Directory -Path $publishDir -Force | Out-Null }

$sln = Join-Path $RepoRoot 'ExistingDbWebApi.sln'
$proj = Join-Path $RepoRoot 'src\ExistingDb.Api\ExistingDb.Api.csproj'

dotnet restore $sln
dotnet publish $proj -c Release -o $publishDir --no-restore

$templates = Join-Path $DeployRoot 'templates\api'
$out = Join-Path $DeployRoot 'output'
if (-not (Test-Path $out)) { New-Item -ItemType Directory -Path $out -Force | Out-Null }

Expand-Template `
  -TemplatePath (Join-Path $templates 'appsettings.Production.json.template') `
  -OutputPath (Join-Path $publishDir 'appsettings.Production.json') `
  -Variables $vars

Expand-Template `
  -TemplatePath (Join-Path $templates 'api.env.template') `
  -OutputPath (Join-Path $publishDir 'api.env') `
  -Variables $vars

Write-Ok "API published to $publishDir"
Write-Host "  Run: `$env:ASPNETCORE_ENVIRONMENT='Production'; dotnet ExistingDb.Api.dll" -ForegroundColor Gray
