# نشر تطبيق أندرويد (TWA) على Google Play

المشروع الجاهز: **[../android-twa](../android-twa)** (`sy.jawishco.portal` → `https://www.jawishco.sy`).

## ما الذي يحدث على السيرفر؟

1. **ملف Digital Asset Links** على النطاق الذي يفتحه التطبيق (`www`):

   `https://www.jawishco.sy/.well-known/assetlinks.json`

   يُولَّد من `portal/public/.well-known/assetlinks.php` حسب متغيرات `.env`:

   ```env
   PORTAL_ANDROID_PACKAGE_ID=sy.jawishco.portal
   PORTAL_ANDROID_SHA256_FINGERPRINTS=AA:BB:...
   ```

2. **لا تحويل 301** بين `www` و`apex` على هذا المسار — نفس سياسة PWA في [JAWISH-PRODUCTION-WORKFLOW.md](JAWISH-PRODUCTION-WORKFLOW.md).

## nginx

القالب الافتراضي كان يمنع مجلدات تبدأ بـ `.` بالكامل. أُضيف استثناء لـ asset links **قبل** `location ~ /\.`.

بعد `publish.sh`، تأكد أن الملف المُولَّد `deploy/output/nginx-jawish-portal.conf` يحتوي على:

```nginx
location = /.well-known/assetlinks.json {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/.well-known/assetlinks.php;
    fastcgi_param SCRIPT_NAME /.well-known/assetlinks.php;
    include fastcgi_params;
}
```

غيّر مسار `fastcgi_pass` ليطابق PHP-FPM على السيرفر.

**تحقق:**

```bash
curl -sS https://www.jawishco.sy/.well-known/assetlinks.json
```

استخدم أيضاً [أداة Google Statement List Generator](https://developers.google.com/digital-asset-links/tools/generator) مع نفس النطاق والـ package.

## IIS (Windows)

أضف قاعدة rewrite قبل Front Controller:

```xml
<rule name="Android Asset Links" stopProcessing="true">
  <match url="^\.well-known/assetlinks\.json$" />
  <action type="Rewrite" url=".well-known/assetlinks.php" />
</rule>
```

## سير العمل المختصر

| الخطوة | أين |
|--------|-----|
| إنشاء keystore وبناء AAB | جهاز المطوّر — `android-twa/scripts/build-release.sh` |
| تعيين البصمات في `.env` | سيرفر Portal |
| نشر Portal | `deploy/portal/publish.sh` |
| رفع AAB | Google Play Console |
| سياسة الخصوصية + لقطات | Play Console + صفحة على الموقع |

## روابط مفيدة

- [Trusted Web Activity — Android Developers](https://developer.android.com/develop/ui/views/layout/webapps/trusted-web-activities)
- [Bubblewrap CLI](https://github.com/GoogleChromeLabs/bubblewrap)