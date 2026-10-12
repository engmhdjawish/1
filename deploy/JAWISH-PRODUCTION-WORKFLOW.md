# طريقة نشر جاوиш (Mohammad) — المرجع للوكلاء

> **لـ Cloud Agent:** هذا هو **الروتين الفعلي** على سيرفر Linux. لا تقترح Windows/package-for-server إلا إذا طلب المستخدم سيرفر Windows صراحة.

## الإنتاج (Linux — nginx + PHP 8.5-FPM)

| المكوّن | المعتاد |
|---------|---------|
| مستودع Git على السيرفر | `/opt/jawish` |
| نشر Portal | `deploy/portal/publish.sh` + `deploy/deploy.env` |
| PHP | `php8.5-fpm` |
| الويب | nginx |

---

## A) نشر تحديث Portal (بعد `main` أو فرع قبل الدمج)

### على `main` (بعد الدمج)

```bash
cd /opt/jawish
git checkout main
git pull origin main
PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh
sudo systemctl reload php8.5-fpm
sudo nginx -t && sudo systemctl reload nginx
```

### فرع قبل الدمج (مثال: تشخيص الأداء)

```bash
cd /opt/jawish
git fetch origin
git checkout cursor/system-performance-diagnostic-f529
git pull origin cursor/system-performance-diagnostic-f529
PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh
sudo systemctl reload php8.5-fpm
sudo nginx -t && sudo systemctl reload nginx
```

- **`PORTAL_DB_SETUP=migrate`**: ترحيلات DB فقط؛ **`.env` في مجلد النشر يُحفظ** (لا يُستبدل من `deploy.env` عند migrate).
- **`publish.sh`**: ينسخ `portal/` إلى `PORTAL_PUBLISH_DIR` (من `deploy.env`)، `composer install --no-dev`، `run-migrations.php`، `check-environment.php`، ويبني CSS إن وُجد `npm`.

**رسالة `cannot delete non-empty directory: storage/cache`:**  
`rsync --delete` حاولت محاذاة مجلد cache مع Git (فارغ/غير موجود) و**فشل الحذف** — **الكاش على السيرفر لم يُمسح**. في الإصدارات الأحدث من السكربت يُستثنى `storage/cache` (و`zip-jobs` و`material-image-worker`) من المزامنة.

### تحقق سريع (اختياري)

```bash
cd "${PORTAL_PUBLISH_DIR:-/var/www/jawish-portal}"
php scripts/diagnose-portal.php
php scripts/run-system-diagnostic.php
```

لوحة: `/dashboard/system-diagnostic.php`

---

## B) متى يُعاد نشر API؟

**فقط** عند تغيير **ExistingDb.Api** — ليس جزءاً من أوامر Portal أعلاه.

---

## C) بديل Windows/IIS (حزمة USB — ليس الروتين على Linux)

إذا نشر على Windows: `deploy/scripts/package-for-server.ps1` → `C:\JawishDeploy` → `robocopy` → `D:\JawishPortal`.  
راجع `deploy/templates/SERVER-STEPS.template.txt` و `deploy/WINDOWS-IIS-LOCAL.md`.

---

## D) للوكلاء

1. **افترض** أوامر `/opt/jawish` + `publish.sh` + `php8.5-fpm` + nginx.
2. Portal-only: **لا** `deploy/api/publish`.
3. بطء/timeout: بعد النشر → **`/dashboard/system-diagnostic.php`**.
