# طريقة نشر جاوиш (Mohammad) — المرجع للوكلاء والفريق

> **مهم للـ Cloud Agent:** لا تستخدم `git pull` على السيرفر إلا إذا طلب المستخدم ذلك صراحة.  
> الطريقة المعتادة: **حزمة من PC التطوير → `C:\JawishDeploy` → `robocopy` → `D:\JawishPortal`**.

## البنية المتوقعة

| المكوّن | المسار / المنفذ |
|---------|------------------|
| Portal (IIS) | `D:\JawishPortal` — جذر الويب: `D:\JawishPortal\public` |
| PHP | `C:\php\php.exe` / `php-cgi.exe` |
| PostgreSQL | `C:\pgsql` — superuser: `admin` |
| IIS | موقع **JawishPortal** — غالباً منفذ **90** / HTTPS `jawish.ddns.net` |
| API الأمين | `http://127.0.0.1:5000` — **لا تعيد نشر API** إلا إذا تغيّر فرع API أو طلب المستخدم |
| حزمة على السيرفر | `C:\JawishDeploy\` (من USB / RDP) |

---

## A) نشر تحديث Portal (الروتين — بعد كل فرع / قبل الدمج)

### 1) على PC التطوير (`C:\Users\HP\1` أو مسار الـ repo)

```powershell
cd C:\Users\HP\1
git fetch origin
git checkout cursor/system-performance-diagnostic-f529   # أو الفرع المطلوب
git pull origin cursor/system-performance-diagnostic-f529

# تأكد deploy\deploy.env مضبوط لقيم السيرفر
.\deploy\scripts\package-for-server.ps1
```

الناتج: `deploy\output\server-package\`

### 2) انسخ الحزمة إلى السيرفر

انسخ محتوى `server-package` إلى **`C:\JawishDeploy\`** على السيرفر (USB / مشاركة / RDP).

### 3) على السيرفر (PowerShell)

```powershell
robocopy C:\JawishDeploy\JawishPortal D:\JawishPortal /E /XF .env
robocopy C:\JawishDeploy\docs D:\JawishPortal\docs /E

cd D:\JawishPortal
C:\php\php.exe scripts\run-migrations.php

iisreset
```

- **`/XF .env`**: يحافظ على `.env` الحقيقي على السيرفر — لا تستبدله من الحزمة.
- **`storage\`**: لا تُمسح؛ الـ robocopy `/E` يحدّث الملفات ويترك التخزين المحلي.

### 4) تحقق

```powershell
C:\php\php.exe scripts\diagnose-portal.php
C:\php\php.exe scripts\run-system-diagnostic.php
```

- API: `http://127.0.0.1:5000/api/health`
- الموقع: `https://jawish.ddns.net`
- لوحة: `/dashboard/system-diagnostic.php` (بعد دمج ميزة التشخيص)

---

## B) إعداد كامل من صفر (مرة واحدة)

اتبع **`deploy\output\server-package\SERVER-STEPS.txt`** (يُولَّد من `package-for-server.ps1`)  
أو القالب: `deploy\templates\SERVER-STEPS.template.txt`  
والدليل: `deploy\WINDOWS-IIS-LOCAL.md`

---

## C) ما لا يُستخدم في الروتين

| أمر | متى |
|-----|-----|
| `.\deploy\portal\publish.ps1` | نشر مباشر على نفس الجهاز إن `PORTAL_PUBLISH_DIR` محلي — **ليس** مسار السيرفر المعتاد |
| `git pull` على `D:\JawishPortal` | فقط إذا كان السيرفر clone git (غير المعتاد حسب هذا الملف) |
| `deploy\api\publish.ps1` | عند تغيير **ExistingDb.Api** فقط |

---

## D) ملاحظات للوكلاء

1. بعد أي تغيير Portal: **package-for-server → robocopy → migrations → iisreset**.
2. فرع **Portal-only** (مثل تشخيص الأداء): لا حاجة لنشر API.
3. إن شكا المستخدم من بطء/timeout: بعد النشر افتح **`/dashboard/system-diagnostic.php`**.
