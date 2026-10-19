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

### الموقع لا يستجيب — التشخيص بدون لوحة

لوحة `/dashboard/system-diagnostic.php` تحتاج **nginx + PHP-FPM + تسجيل دخول** — إن الموقع «معلّق» للزوار غالباً **اللوحة أيضاً لا تفتح**.

من **SSH** (يعمل حتى لو الويب caído، ما دام PHP CLI وPostgreSQL شغّالين):

```bash
cd /var/www/jawish-portal
php scripts/run-system-diagnostic.php
# أو حفظ آخر نتيجة:
php scripts/run-system-diagnostic.php | tee /tmp/jawish-diagnostic.json
php scripts/diagnose-portal.php
```

فحص سريع أن الويب حي من **داخل السيرفر**:

```bash
# 127.0.0.1/ بدون Host غالباً 404 — هذا ليس موقع البوابة (server_name = النطاق)
WEB_DOMAIN="$(grep server_name /etc/nginx/sites-enabled/jawish-portal 2>/dev/null | grep -vE 'server_name\s+_;' | head -1 | awk '{print $2}' | tr -d ';')"
# لا تستخدم grep -v '_' على السطر كاملاً — يُفرّغ WEB_DOMAIN ويصبح curl Host فارغ → HTTP 400
curl -sS -o /dev/null -w "HTTP %{http_code}\n" -H "Host: $WEB_DOMAIN" http://127.0.0.1/index.php
curl -sSk -o /dev/null -w "HTTPS %{http_code}\n" -H "Host: $WEB_DOMAIN" https://127.0.0.1/index.php
grep -Rn fastcgi_pass /etc/nginx/sites-enabled/
bash /opt/jawish/deploy/scripts/diagnose-502-gateway.sh
sudo systemctl status nginx php8.5-fpm --no-pager
sudo tail -n 30 /var/log/nginx/error.log
```

| العرض | ماذا تفعل |
|--------|-----------|
| `curl` localhost بطيء/timeout | PHP-FPM مشبع أو طلبات عالقة — `systemctl restart php8.5-fpm` |
| **502 Bad Gateway** (nginx) | راجع **`error.log`**: `(11: Resource temporarily unavailable)` = **PHP-FPM مشبع** (كل الـ workers مشغولون) — `bash deploy/scripts/diagnose-fpm-saturation.sh` ثم `sudo bash deploy/scripts/tune-php-fpm-jawish.sh`؛ وإلا socket/FPM متوقف — `diagnose-502-gateway.sh` |
| **conflicting server name** في `nginx -t` | ملف `.bak` أو vhost مكرر في `sites-enabled/` — انقله إلى `/etc/nginx/disabled/` |
| **HTTP 301** + **HTTPS 502** | certbot فصل **`listen 80`** (إعادة توجيه فقط) عن **`listen 443`** بدون `location ~ \.php$` — [502 على HTTPS فقط](#502-على-https-فقط-http-يعيد-301) |
| `curl` سريع لكن الزوار لا يرون الموقع | DNS، firewall، SSL، أو شبكة خارجية |
| CLI diagnostic `critical` على API | سيرفر الأمين `82.137.204.70:9090` — ليس إصلاح Portal وحده |

### 502 على HTTPS فقط (HTTP يعيد 301)

الاختبار:

```bash
HOST=jawishco.sy
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' -H "Host: $HOST" http://127.0.0.1/index.php   # غالباً 301
curl -sSk -o /dev/null -w 'HTTPS %{http_code}\n' -H "Host: $HOST" https://127.0.0.1/index.php  # 502 = كتلة SSL ناقصة
sudo bash /opt/jawish/deploy/scripts/audit-nginx-https-php.sh
```

**الإصلاح:** افتح `/etc/nginx/sites-enabled/jawish-portal` — في **`server { listen 443 ssl; ... }`** أضف نفس إعدادات البوابة (أو انسخها من كتلة 80):

```nginx
    root /var/www/jawish-portal/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
```

(مع `ssl_certificate` / `ssl_certificate_key` كما هي.) ثم:

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -sSk -o /dev/null -w '%{http_code}\n' -H "Host: jawishco.sy" https://127.0.0.1/index.php
```

(اختياري) cron كل 5 دقائق يحفظ آخر JSON للمراجعة بعد انقطاع:

```bash
# /etc/cron.d/jawish-diagnostic-snapshot
*/5 * * * * root /usr/bin/php8.5 /var/www/jawish-portal/scripts/run-system-diagnostic.php > /var/www/jawish-portal/storage/diagnostic-last.json 2>&1
```

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
