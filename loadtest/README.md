# اختبار الضغط (Load / Stress) — محاكاة استخدام الموقع

أدوات جاهزة لضرب الـ API والموقع بعدد زوار افتراضيين متزامنين، بنفس المسارات تقريباً التي يستخدمها المتجر والبوابة.

## ماذا يُحاكى؟

| السيناريو | الهدف | المسارات |
|-----------|--------|----------|
| `api-browse` | ضغط على ASP.NET API مباشرة (مثل حساب الخدمة) | login → materials → filter-options → material detail → images |
| `site-browse` | ضغط على واجهة الزائر (PHP) | الصفحة الرئيسية → المتجر → فلاتر → منتج → صورة → سلة |

## المتطلبات

- Node.js 18+ (مثبت مسبقاً في أغلب البيئات)
- API شغّال (مثلاً `http://127.0.0.1:5249` أو `5000`)
- لسيناريو الموقع: الـ Portal شغّال (مثلاً `http://127.0.0.1:8080`)
- مستخدم API بصلاحية `materials.read` (مثل `portal-service`)

## تشغيل سريع (بدون تثبيت إضافي)

من جذر المستودع:

```bash
# محاكاة ضغط API (افتراضي: 20 مستخدم، 60 ثانية)
node loadtest/run.mjs --scenario api-browse \
  --base-url http://127.0.0.1:5249 \
  --username portal-service \
  --password 'YourApiPassword' \
  --vus 20 \
  --duration 60
```

```bash
# محاكاة زوار الموقع
node loadtest/run.mjs --scenario site-browse \
  --site-url http://127.0.0.1:8080 \
  --vus 30 \
  --duration 90
```

على ويندوز (PowerShell):

```powershell
.\loadtest\run.ps1 -Scenario api-browse -BaseUrl http://127.0.0.1:5249 `
  -Username portal-service -Password 'YourApiPassword' -Vus 20 -Duration 60
```

```powershell
.\loadtest\run.ps1 -Scenario site-browse -SiteUrl http://127.0.0.1:8080 -Vus 30 -Duration 90
```

## مستويات الضغط المقترحة

ابدأ خفيفاً ثم زد تدريجياً:

| المستوى | `--vus` | `--duration` | الغرض |
|---------|---------|--------------|--------|
| دخان (smoke) | 2 | 15 | التأكد أن السكربت يعمل |
| طبيعي | 10–20 | 60 | يوم عمل عادي |
| ضغط | 40–80 | 120 | ذروة متوقعة |
| إجهاد (stress) | 100+ | 180 | أين ينكسر النظام |

راقب أثناء الاختبار:

- زمن الاستجابة p95 / p99
- نسبة الأخطاء (5xx / timeouts)
- CPU / RAM لـ API و SQL Server و PHP
- طوابير IIS FastCGI إن وُجدت

## خيارات مهمة

```text
--scenario api-browse|site-browse   نوع المحاكاة
--base-url URL                      عنوان ASP.NET API
--site-url URL                      عنوان موقع PHP
--username / --password             حساب خدمة API
--vus N                             عدد الزوار الافتراضيين المتزامنين
--duration SEC                      مدة الاختبار بالثواني
--ramp-up SEC                       تصعيد تدريجي قبل الذروة (افتراضي 10)
--think-ms MS                       توقف قصير بين خطوات الزائر (افتراضي 200)
--timeout-ms MS                     مهلة الطلب (افتراضي 30000)
--insecure                          تجاهل أخطاء شهادة TLS
```

كلمة المرور يمكن تمريرها عبر البيئة بدل سطر الأوامر:

```bash
export LOADTEST_USERNAME=portal-service
export LOADTEST_PASSWORD='YourApiPassword'
node loadtest/run.mjs --scenario api-browse --base-url http://127.0.0.1:5249
```

## بديل k6 (اختياري)

إن كان [k6](https://k6.io) أو Docker متاحاً:

```bash
k6 run -e BASE_URL=http://127.0.0.1:5249 \
  -e USERNAME=portal-service \
  -e PASSWORD='YourApiPassword' \
  loadtest/k6/api-browse.js
```

```bash
k6 run -e SITE_URL=http://127.0.0.1:8080 loadtest/k6/site-browse.js
```

أو عبر Docker:

```bash
docker run --rm -i --network host \
  -e BASE_URL=http://127.0.0.1:5249 \
  -e USERNAME=portal-service \
  -e PASSWORD='YourApiPassword' \
  grafana/k6 run - <loadtest/k6/api-browse.js
```

## ملاحظات أمان

- لا تشغّل اختبار ضغط عالي على الإنتاج دون تنسيق.
- لا تضع كلمة المرور داخل Git.
- استخدم بيئة staging / محلية قدر الإمكان.
- اختبار `site-browse` يمر عبر PHP → API، فهو أقرب لمحاكاة الزائر الحقيقي.
