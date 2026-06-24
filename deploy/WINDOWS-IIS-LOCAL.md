# نشر الموقع على Windows + IIS (نفس سيرفر الأمين)

الموقع (PHP) و**API الأمين** (.NET + SQL Server) يعملان على **نفس السيرفر** بدون تعارض:

| المكوّن | القاعدة | المنفذ المعتاد |
|---------|---------|----------------|
| API الأمين | SQL Server (MainDb) | 5000 |
| الموقع | **PostgreSQL** (portal_db) | 5432 |
| IIS — الموقع | مجلد `public` | 8080 |

> **مهم:** الموقع لا يعمل بدون PostgreSQL. لا يمكن استبداله بـ SQL Server بدون إعادة بناء المشروع.

---

## المشروع على جهازك فقط (لا Git على السيرفر)

| أين | ماذا |
|-----|------|
| **جهازك** | `package-for-server.ps1` → مجلد `deploy\output\server-package` → فلاشة |
| **السيرفر** | PostgreSQL + نسخ المجلد + `server-setup-on-host.ps1` + IIS |

```powershell
# على جهازك:
cd C:\Users\HP\1
notepad deploy\deploy.env
.\deploy\scripts\package-for-server.ps1
# انسخ deploy\output\server-package إلى السيرفر
```

على السيرفر اتبع `SERVER-STEPS.txt` داخل المجلد المنقول.

---

## 1) الحصول على PostgreSQL رغم الحجب (اختر طريقة واحدة)

### أ) نسخ من جهاز آخر (الأنسب لسوريا)

على جهاز فيه إنترنت (صديق، VPN، هاتف):

1. حمّل **بدون مُثبّت** (ملف ZIP فقط):
   - [PostgreSQL 16 Windows x64 binaries](https://www.enterprisedb.com/download-postgresql-binaries)  
   - الملف مثل: `postgresql-16.x-windows-x64-binaries.zip`
2. انسخ الملف إلى السيرفر (فلاشة / شبكة محلية).
3. فكّ الضغط إلى `D:\PostgreSQL` (يجب أن يظهر `D:\PostgreSQL\bin\psql.exe`).

### ب) Docker (إن كان Docker Desktop مثبتاً)

على جهاز آخر:

```bash
docker pull postgres:16-alpine
docker save postgres:16-alpine -o postgres-16-alpine.tar
```

انقل `postgres-16-alpine.tar` للسيرفر ثم:

```powershell
docker load -i postgres-16-alpine.tar
cd C:\Users\HP\1\portal
docker compose up -d
```

### ج) مُثبّت عادي (إن توفّر التحميل لمرة واحدة)

- [PostgreSQL Windows installer](https://www.postgresql.org/download/windows/)  
- أثناء التثبيت: مستخدم `portal`، كلمة مرور، قاعدة `portal_db`، منفذ `5432`.

---

## 2) تهيئة PostgreSQL المحمول (ZIP)

```powershell
cd C:\Users\HP\1
git pull origin cursor/staff-permissions-f03f

.\deploy\scripts\setup-portable-postgres.ps1 `
  -PgRoot D:\PostgreSQL `
  -DataDir D:\PostgreSQL\data `
  -DbUser portal `
  -DbPassword "كلمة_سر_قوية" `
  -DbName portal_db
```

ثم تثبيته كخدمة Windows (اختياري):

```powershell
D:\PostgreSQL\bin\pg_ctl.exe register -N "PortalPostgreSQL" -D D:\PostgreSQL\data
Start-Service PortalPostgreSQL
```

تحقق:

```powershell
D:\PostgreSQL\bin\psql.exe -h 127.0.0.1 -U portal -d portal_db -c "SELECT 1"
```

---

## 3) PHP + امتدادات (قبل IIS)

```powershell
.\deploy\scripts\fix-windows-php.ps1
```

فعّل في **php.ini** (نفس الملف الذي يستخدمه IIS و CLI):

```ini
extension=pdo_pgsql
extension=pgsql
extension=mbstring
extension=openssl
extension=curl
extension=gd
```

أضف مجلد PostgreSQL إلى PATH أو انسخ `libpq.dll` من `D:\PostgreSQL\bin` بجانب `php.exe`.

```powershell
php -m | findstr /i "pdo_pgsql mbstring openssl"
iisreset
```

---

## 4) إعداد `deploy.env`

```powershell
notepad C:\Users\HP\1\deploy\deploy.env
```

مثال لسيرفرك:

```env
API_PUBLISH_DIR=D:\AmeenApi\existingdb-api
API_URL=http://127.0.0.1:5000
API_PORT=5000

PORTAL_PUBLISH_DIR=D:\JawishPortal
PORTAL_APP_URL=http://192.168.1.106:8080

PORTAL_DB_HOST=127.0.0.1
PORTAL_DB_PORT=5432
PORTAL_DB_NAME=portal_db
PORTAL_DB_USER=portal
PORTAL_DB_PASSWORD=كلمة_سر_قوية

AMINE_API_USERNAME=portal-service
AMINE_API_PASSWORD=كلمة_خدمة_API

PORTAL_ADMIN_USER=admin
PORTAL_ADMIN_PASSWORD=كلمة_مدير_الموقع
PORTAL_ADMIN_DISPLAY_NAME=مدير النظام
```

---

## 5) نشر الموقع

```powershell
cd C:\Users\HP\1
.\deploy\wizard.ps1 -Action portal -DbSetup fresh
```

أو إن كانت القاعدة جاهزة وترغب بالترقية فقط:

```powershell
.\deploy\wizard.ps1 -Action portal -DbSetup migrate
```

---

## 6) IIS — موقع الموقع

1. **IIS Manager** → Sites → Add Website  
   - Name: `JawishPortal`  
   - Physical path: `D:\JawishPortal\public`  
   - Binding: `http` — IP `192.168.1.106` — Port `8080`

2. تأكد من وجود `D:\JawishPortal\public\web.config` (يُنسخ تلقائياً من النشر).

3. **FastCGI** لـ PHP (مطلوب — بدونه يظهر **404.3** على `index.php`):

   ```powershell
   # PowerShell كمسؤول على السيرفر
   cd C:\JawishDeploy\server-tools
   .\install-iis-php-handler.ps1 -SitePort 90
   # أو: -PhpCgiPath "C:\php\php-cgi.exe" -SiteName "JawishPortal"
   iisreset
   ```

   يدوياً: IIS Manager → Handler Mappings → Add `*.php` → `php-cgi.exe`  
   أو [PHP Manager for IIS](https://phpmanager.gitlab.io/) إن وُجد.

4. صلاحيات الكتابة على:
   - `D:\JawishPortal\storage`
   - `D:\JawishPortal\storage\material-images`
   - `D:\JawishPortal\storage\site-media`

   للمستخدم: `IIS AppPool\JawishPortal` (أو اسم الـ pool الذي تستخدمه).

5. **URL Rewrite** module مطلوب لـ `web.config` (حمّله مرة واحدة من جهاز آخر إن لزم).

---

## 7) بعد النشر

```powershell
cd D:\JawishPortal
php scripts\run-migrations.php
php scripts\check-environment.php
```

من المتصفح:

- الموقع: `http://192.168.1.106:8080`
- API: `http://192.168.1.106:5000/api/health`
- لوحة التحكم: `http://192.168.1.106:8080/dashboard/`
- المستخدمون والأدوار: `/dashboard/users.php`

---

## 8) استكشاف أخطاء

| المشكلة | الحل |
|---------|------|
| `could not connect to server` | PostgreSQL غير شغّال — `Start-Service PortalPostgreSQL` |
| `could not find driver` / `pdo_pgsql` | فعّل الامتداد في `php.ini` + انسخ `libpq.dll` من `D:\PostgreSQL\bin` بجانب `php.exe` — `.\fix-windows-php.ps1 -ApplyFix` ثم `iisreset` |
| الموقع لا يتصل بالـ API | `AMINE_API_BASE_URL=http://127.0.0.1:5000` في `.env` |
| 500.19 / 0x8007000d على IIS | ثبّت URL Rewrite **أو** استخدم `web.config` بدون rewrite (القالب الافتراضي الجديد) |
| **404.3** على `index.php` (StaticFile / Handler StaticFile) | PHP غير مربوط بـ IIS — شغّل `install-iis-php-handler.ps1` كمسؤول ثم `iisreset`؛ تأكد من وجود `php-cgi.exe` |
| لا يمكن تحميل PostgreSQL | انسخ ZIP من جهاز آخر (القسم 1أ) |

---

## ترتيب العمل المختصر

```
PostgreSQL (ZIP أو Docker) → PHP extensions → deploy.env → wizard portal → IIS :8080 → migrations → users.php
```

API الأمين يبقى كما هو على المنفذ 5000 — لا حاجة لإعادة نشره لنشر الموقع فقط.
