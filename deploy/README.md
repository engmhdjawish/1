# دليل نشر جاويش — API + الموقع

هذا المجلد يحتوي معالج نشر تفاعلي وسكريبتات لتجهيز **ExistingDb.Api** (.NET 9) و**البوابة** (PHP + PostgreSQL) للإنتاج.

## البنية

```
deploy/
├── wizard.ps1              # معالج Windows (موصى به على السيرفر المحلي)
├── wizard.sh               # معالج Linux/macOS
├── deploy.env.example      # قالب الإعدادات
├── deploy.env              # إعداداتك (لا يُرفع إلى Git)
├── api/
│   ├── publish.ps1 / .sh   # dotnet publish + ملفات الإنتاج
├── portal/
│   ├── publish.ps1 / .sh   # نسخ الموقع + composer + DB + فحص
├── scripts/
│   └── check-prerequisites # فحص dotnet / php / composer / امتدادات
├── templates/
│   ├── api/                # appsettings.Production, systemd, env
│   └── portal/             # nginx, IIS web.config
└── output/                 # ملفات مُولَّدة (nginx, systemd)
```

## البدء السريع (Windows)

```powershell
cd C:\Users\HP\1
git fetch origin
git checkout cursor/deploy-wizard-f03f

# المعالج التفاعلي (Windows PowerShell 5.1+)
.\deploy\wizard.ps1
```

> **ملاحظة:** سكريبتات `.ps1` تستخدم نصوصاً إنجليزية في الطرفية لتجنب مشاكل ترميز PowerShell 5.1 على Windows. الدليل الكامل بالعربية في هذا الملف.

```powershell

# أو مباشرة:
.\deploy\wizard.ps1 -Action check      # فحص المتطلبات
.\deploy\wizard.ps1 -Action full       # إعداد كامل
.\deploy\wizard.ps1 -Action api        # API فقط
.\deploy\wizard.ps1 -Action portal -DbSetup fresh
```

## البدء السريع (Linux)

```bash
chmod +x deploy/wizard.sh deploy/**/*.sh
./deploy/wizard.sh full fresh
# أو
./deploy/wizard.sh menu
```

---

## المتطلبات

| المكوّن | المتطلب |
|---------|---------|
| API | .NET SDK 9.0.305+، SQL Server (MainDb + ApiManagementDb) |
| الموقع | PHP 8.2+ (`pdo_pgsql`, `curl`, `mbstring`, `openssl`, `gd`)، Composer، PostgreSQL 16+ |
| اختياري | `psql` لترحيل SQL، nginx/Apache/IIS، `rsync` (Linux) |

```powershell
.\deploy\scripts\check-prerequisites.ps1
```

---

## ماذا يفعل المعالج؟

### 1) جمع الإعدادات → `deploy/deploy.env`

- مسارات النشر
- سلاسل اتصال SQL Server
- مفتاح JWT (يُولَّد تلقائياً إن لم يُدخل)
- إعدادات PostgreSQL
- ربط الموقع بـ API (`AMINE_API_*`)
- مستخدم لوحة التحكم (اختياري)

### 2) نشر API

```powershell
.\deploy\api\publish.ps1
```

ينفّذ:

- `dotnet publish -c Release`
- ينشئ `appsettings.Production.json` و `api.env` في مجلد النشر
- على Linux: قالب `systemd` في `deploy/output/`

**تشغيل API (Windows):**

```powershell
cd C:\publish\existingdb-api
$env:ASPNETCORE_ENVIRONMENT = 'Production'
dotnet ExistingDb.Api.dll
```

**تشغيل API (Linux systemd):**

```bash
sudo cp deploy/output/existingdb-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now existingdb-api
```

### 3) نشر الموقع

```powershell
.\deploy\portal\publish.ps1 -DbSetup fresh   # قاعدة جديدة
.\deploy\portal\publish.ps1 -DbSetup migrate # ترقية قاعدة موجودة
```

ينفّذ:

- نسخ `portal/` إلى مجلد النشر (مع استثناء `.env` و `storage` الحساس)
- `composer install --no-dev`
- `setup-database.php` (fresh) أو `run-migrations.php` (migrate)
- `create-admin.php` (إن وُجدت بيانات في deploy.env)
- `check-environment.php`
- ينسخ `web.config` لـ IIS ويولّد قالب nginx

**جذر الويب:** `PORTAL_PUBLISH_DIR/public`

---

## ترحيل قاعدة بيانات الموقع

```bash
cd portal
php scripts/run-migrations.php        # تطبيق الترحيلات الجديدة فقط
php scripts/run-migrations.php --list # عرض القائمة
```

الترحيلات في `docs/portal-migrations/` + ملفات مستقلة. يُسجَّل ما طُبِّق في جدول `portal_schema_migrations`.

---

## بعد النشر — خطوات يدوية مهمة

### 1) حساب خدمة API للموقع

أنشئ مستخدم `portal-service` في ApiManagementDb بصلاحيات القراءة (ورفع الصور لاحقاً).

راجع: [docs/portal-api-service-account.md](../docs/portal-api-service-account.md)

### 2) محاذاة المنافذ

- المعالج يضبط `AMINE_API_BASE_URL` في `.env` للموقع
- تأكد أن API يعمل على نفس العنوان/المنفذ
- في التطوير: API قد يكون `5249` (launchSettings) أو `5000` — وحّد القيمة

### 3) IIS (Windows)

1. أنشئ موقعاً يشير إلى `PORTAL_PUBLISH_DIR\public`
2. `web.config` يُنسخ تلقائياً
3. فعّل PHP عبر FastCGI
4. امنح صلاحية كتابة على `storage\`

### 4) nginx (Linux)

```bash
sudo cp deploy/output/nginx-jawish-portal.conf /etc/nginx/sites-available/jawish-portal
sudo ln -s /etc/nginx/sites-available/jawish-portal /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 5) مجلد صور الأمين

في `ApiSettings` (قاعدة ApiManagementDb) عيّن `Images:Directory` لمسار صور المواد على السيرفر.

### 6) تعطيل SeedAdmin

بعد إنشاء مستخدم admin للـ API:

```
SeedAdmin__Enabled=false
```

---

## ترتيب المشروع للإنتاج

| المسار | الدور |
|--------|------|
| `src/ExistingDb.Api/` | كود API — يُنشر عبر `dotnet publish` |
| `portal/public/` | جذر الويب الوحيد للموقع |
| `portal/storage/` | بيانات وقت التشغيل (صور، وسائط، توكن) — **قابل للكتابة** |
| `docs/` | مخطط SQL وترحيلات — لا يُعرض على الويب |
| `deploy/` | سكريبتات النشر — لا يُعرض على الويب |

**لا تنشر:** `.env`, `deploy.env`, `storage/amine-api-token.json`, مجلد `.git`

---

## استكشاف الأخطاء

| المشكلة | الحل |
|---------|------|
| الموقع لا يتصل بالـ API | تحقق من `AMINE_API_BASE_URL` ووجود `portal-service` |
| فشل ترحيل DB | تأكد من `psql` وصلاحيات المستخدم على PostgreSQL |
| `openssl` / `gd` مفقود | فعّل الامتدادات في `php.ini` |
| API لا يبدأ | راجع سلاسل الاتصال و`Jwt:SigningKey` (32+ حرف) |
| 403 على storage | صلاحيات مجلد `storage` للمستخدم الذي يشغّل PHP |

---

## أوامر مرجعية بدون معالج

```powershell
# API
dotnet publish src\ExistingDb.Api\ExistingDb.Api.csproj -c Release -o C:\publish\existingdb-api

# Portal
cd portal
composer install --no-dev
php scripts/setup-database.php
php scripts/run-migrations.php
php scripts/create-admin.php admin "YourPassword" "مدير النظام"
php scripts/check-environment.php
```
