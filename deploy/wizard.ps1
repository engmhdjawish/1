#Requires -Version 5.1
<#
.SYNOPSIS
  معالج نشر جاويش — API + الموقع

.DESCRIPTION
  يفحص المتطلبات، يجمع الإعدادات، وينفّذ نشر API والموقع.

.EXAMPLE
  .\deploy\wizard.ps1
  .\deploy\wizard.ps1 -Action api
  .\deploy\wizard.ps1 -Action portal -DbSetup migrate
#>
param(
    [ValidateSet('menu', 'full', 'api', 'portal', 'check', 'migrate')]
    [string]$Action = 'menu',
    [ValidateSet('fresh', 'migrate', 'skip')]
    [string]$DbSetup = 'fresh'
)

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\lib\common.ps1"

function Show-Banner {
    Write-Host ''
    Write-Host '╔══════════════════════════════════════╗' -ForegroundColor Red
    Write-Host '║   معالج نشر جاويش — API + الموقع    ║' -ForegroundColor Red
    Write-Host '╚══════════════════════════════════════╝' -ForegroundColor Red
    Write-Host ''
}

function Read-EnvValue {
    param([string]$Key, [string]$Prompt, [string]$Default = '', [switch]$Secret)
    $envFile = Join-Path $DeployRoot 'deploy.env'
    $existing = @{}
    if (Test-Path $envFile) { $existing = Read-DeployEnv -Path $envFile }
    $current = $existing[$Key]
    if (-not $current) { $current = $Default }

    if ($Secret) {
        $secure = Read-Host "$Prompt $(if ($current) { '[محفوظ — Enter للإبقاء]' })" -AsSecureString
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
            [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        )
        if ($plain) { return $plain }
        return $current
    }

    $reply = Read-Host "$Prompt $(if ($current) { "[$current]" })"
    if ($reply) { return $reply }
    return $current
}

function Invoke-ConfigureWizard {
    $envFile = Join-Path $DeployRoot 'deploy.env'
    if (-not (Test-Path $envFile)) {
        Copy-Item (Join-Path $DeployRoot 'deploy.env.example') $envFile
    }

    Write-Step 'إعداد API'
    Save-DeployEnvValue 'API_PUBLISH_DIR' (Read-EnvValue 'API_PUBLISH_DIR' 'مجلد نشر API' 'C:\publish\existingdb-api')
    Save-DeployEnvValue 'API_PORT' (Read-EnvValue 'API_PORT' 'منفذ API' '5000')
    Save-DeployEnvValue 'API_BIND_HOST' (Read-EnvValue 'API_BIND_HOST' 'عنوان الربط' '0.0.0.0')
    $port = (Read-DeployEnv -Path $envFile)['API_PORT']
    Save-DeployEnvValue 'API_URL' (Read-EnvValue 'API_URL' 'رابط API للموقع' "http://127.0.0.1:$port")
    Save-DeployEnvValue 'MAIN_DB_CONNECTION' (Read-EnvValue 'MAIN_DB_CONNECTION' 'سلسلة MainDb (SQL Server)')
    Save-DeployEnvValue 'API_MANAGEMENT_DB_CONNECTION' (Read-EnvValue 'API_MANAGEMENT_DB_CONNECTION' 'سلسلة ApiManagementDb')

    $jwt = (Read-DeployEnv -Path $envFile)['JWT_SIGNING_KEY']
    if (-not $jwt -or $jwt -like 'REPLACE*') {
        $jwt = New-RandomSecret
        Save-DeployEnvValue 'JWT_SIGNING_KEY' $jwt
        Write-Ok 'تم توليد مفتاح JWT عشوائي'
    } else {
        $keep = Read-Host 'مفتاح JWT موجود — Enter للإبقاء أو اكتب جديداً'
        if ($keep) { Save-DeployEnvValue 'JWT_SIGNING_KEY' $keep }
    }

    $seed = Read-Host 'إنشاء مستخدم admin للـ API عند أول تشغيل؟ (y/N)'
    if ($seed -match '^[yY]') {
        Save-DeployEnvValue 'SEED_ADMIN_ENABLED' 'true'
        Save-DeployEnvValue 'SEED_ADMIN_PASSWORD' (Read-EnvValue 'SEED_ADMIN_PASSWORD' 'كلمة مرور admin API' -Secret)
    } else {
        Save-DeployEnvValue 'SEED_ADMIN_ENABLED' 'false'
    }

    Write-Step 'إعداد الموقع (Portal)'
    Save-DeployEnvValue 'PORTAL_PUBLISH_DIR' (Read-EnvValue 'PORTAL_PUBLISH_DIR' 'مجلد نشر الموقع' 'C:\publish\jawish-portal')
    Save-DeployEnvValue 'PORTAL_APP_URL' (Read-EnvValue 'PORTAL_APP_URL' 'رابط الموقع العام' 'http://127.0.0.1:8080')
    Save-DeployEnvValue 'PORTAL_DB_HOST' (Read-EnvValue 'PORTAL_DB_HOST' 'PostgreSQL host' '127.0.0.1')
    Save-DeployEnvValue 'PORTAL_DB_PORT' (Read-EnvValue 'PORTAL_DB_PORT' 'PostgreSQL port' '5432')
    Save-DeployEnvValue 'PORTAL_DB_NAME' (Read-EnvValue 'PORTAL_DB_NAME' 'اسم القاعدة' 'portal_db')
    Save-DeployEnvValue 'PORTAL_DB_USER' (Read-EnvValue 'PORTAL_DB_USER' 'مستخدم PostgreSQL' 'portal')
    Save-DeployEnvValue 'PORTAL_DB_PASSWORD' (Read-EnvValue 'PORTAL_DB_PASSWORD' 'كلمة مرور PostgreSQL' -Secret)
    Save-DeployEnvValue 'AMINE_API_USERNAME' (Read-EnvValue 'AMINE_API_USERNAME' 'مستخدم خدمة API' 'portal-service')
    Save-DeployEnvValue 'AMINE_API_PASSWORD' (Read-EnvValue 'AMINE_API_PASSWORD' 'كلمة مرور portal-service' -Secret)

    $admin = Read-Host 'إنشاء مستخدم لوحة التحكم؟ (Y/n)'
    if ($admin -notmatch '^[nN]') {
        Save-DeployEnvValue 'PORTAL_ADMIN_USER' (Read-EnvValue 'PORTAL_ADMIN_USER' 'اسم مستخدم اللوحة' 'admin')
        Save-DeployEnvValue 'PORTAL_ADMIN_PASSWORD' (Read-EnvValue 'PORTAL_ADMIN_PASSWORD' 'كلمة مرور اللوحة' -Secret)
        Save-DeployEnvValue 'PORTAL_ADMIN_DISPLAY_NAME' (Read-EnvValue 'PORTAL_ADMIN_DISPLAY_NAME' 'الاسم العربي' 'مدير النظام')
    }

    Save-DeployEnvValue 'WEB_DOMAIN' (Read-EnvValue 'WEB_DOMAIN' 'نطاق الموقع (لـ nginx)' 'localhost')
    Write-Ok "تم حفظ الإعدادات في deploy\deploy.env"
}

Show-Banner

switch ($Action) {
    'check' {
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        exit $LASTEXITCODE
    }
    'migrate' {
        $portalDir = (Read-DeployEnv -ErrorAction SilentlyContinue)['PORTAL_PUBLISH_DIR']
        if (-not $portalDir) { $portalDir = Join-Path $RepoRoot 'portal' }
        Push-Location $portalDir
        php scripts/run-migrations.php
        Pop-Location
        exit $LASTEXITCODE
    }
    'api' {
        if (-not (Test-Path (Join-Path $DeployRoot 'deploy.env'))) { Invoke-ConfigureWizard }
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        & "$PSScriptRoot\api\publish.ps1"
        exit 0
    }
    'portal' {
        if (-not (Test-Path (Join-Path $DeployRoot 'deploy.env'))) { Invoke-ConfigureWizard }
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        & "$PSScriptRoot\portal\publish.ps1" -DbSetup $DbSetup
        exit 0
    }
    'full' {
        Invoke-ConfigureWizard
        & "$PSScriptRoot\scripts\check-prerequisites.ps1"
        & "$PSScriptRoot\api\publish.ps1"
        & "$PSScriptRoot\portal\publish.ps1" -DbSetup $DbSetup
        Write-Host ''
        Write-Ok 'اكتمل النشر — الخطوات التالية:'
        Write-Host '  1) شغّل API من مجلد النشر (أو كخدمة Windows/IIS)' -ForegroundColor Gray
        Write-Host '  2) أنشئ مستخدم portal-service في ApiManagementDb' -ForegroundColor Gray
        Write-Host '  3) اربط IIS بمجلد portal\public' -ForegroundColor Gray
        Write-Host '  4) راجع deploy\README.md' -ForegroundColor Gray
        exit 0
    }
    default {
        while ($true) {
            Show-Banner
            Write-Host '  1) فحص المتطلبات'
            Write-Host '  2) إعداد كامل (API + الموقع)'
            Write-Host '  3) نشر API فقط'
            Write-Host '  4) نشر الموقع فقط'
            Write-Host '  5) ترحيل قاعدة بيانات الموقع'
            Write-Host '  6) تعديل الإعدادات (deploy.env)'
            Write-Host '  0) خروج'
            Write-Host ''
            $choice = Read-Host 'اختر رقم'
            switch ($choice) {
                '1' { & "$PSScriptRoot\scripts\check-prerequisites.ps1"; Read-Host 'Enter للمتابعة' }
                '2' { & $PSCommandPath -Action full -DbSetup $DbSetup; Read-Host 'Enter للمتابعة' }
                '3' { & $PSCommandPath -Action api; Read-Host 'Enter للمتابعة' }
                '4' {
                    $db = Read-Host 'قاعدة البيانات: fresh / migrate / skip' 
                    if (-not $db) { $db = 'fresh' }
                    & $PSCommandPath -Action portal -DbSetup $db
                    Read-Host 'Enter للمتابعة'
                }
                '5' { & $PSCommandPath -Action migrate; Read-Host 'Enter للمتابعة' }
                '6' { Invoke-ConfigureWizard; Read-Host 'Enter للمتابعة' }
                '0' { exit 0 }
                default { Write-Warn 'خيار غير صالح' }
            }
        }
    }
}
