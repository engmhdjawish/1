# تطبيق أندرويد (Trusted Web Activity) — بوابة جاويش

غلاف **Trusted Web Activity** يفتح الموقع المنشور على `https://www.jawishco.sy` بدون شريط عنوان المتصفح، مناسب لنشر التطبيق على **Google Play**.

| الحقل | القيمة |
|--------|--------|
| Package ID | `sy.jawishco.portal` |
| النطاق | `www.jawishco.sy` |
| المصدر عند الفتح | `?source=playstore` |

الموقع نفسه يبقى **PWA**؛ تحديثات الواجهة تصل عبر نشر الـ Portal كالعادة. رفع إصدار جديد على Play مطلوب عند تغيير `appVersionCode` أو صلاحيات أندرويد.

## المتطلبات على جهاز البناء

- **Node.js** (لـ Bubblewrap)
- **JDK 17+**
- **Android SDK** (يمكن تثبيته عبر `npx @bubblewrap/cli doctor` ثم قبول التراخيص)
- ملف `local.properties` يحوي `sdk.dir=...` (انظر `local.properties.example`)

## 1) إنشاء مفتاح التوقيع

```bash
cd android-twa
keytool -genkeypair -v -keystore android.keystore -alias portal -keyalg RSA -keysize 2048 -validity 10000
```

احفظ كلمة المرور في مدير كلمات مرور — لا تُرفع `android.keystore` إلى Git.

## 2) Digital Asset Links على السيرفر

1. اطبع البصمة SHA-256:

```bash
./scripts/print-signing-fingerprint.sh
```

2. على سيرفر الإنتاج، في `.env` للبوابة:

```env
PORTAL_ANDROID_PACKAGE_ID=sy.jawishco.portal
PORTAL_ANDROID_SHA256_FINGERPRINTS=XX:XX:...   # بصمة upload key
```

إذا فعّلت **Play App Signing**، أضف أيضاً بصمة **App signing certificate** من Play Console (مفصولة بفاصلة).

3. تحقق:

```bash
curl -sS https://www.jawishco.sy/.well-known/assetlinks.json | jq .
```

يجب أن يظهر `package_name` و`sha256_cert_fingerprints`.

4. راجع **deploy/PLAYSTORE-ANDROID.md** لإعداد nginx / IIS.

## 3) بناء حزمة Play (AAB)

```bash
cd android-twa
cp local.properties.example local.properties   # ثم عدّل sdk.dir
./scripts/build-release.sh
```

الناتج: `app/build/outputs/bundle/release/app-release.aab`

بعد تغيير `twa-manifest.json` (أيقونة، اختصارات، إصدار):

```bash
npx @bubblewrap/cli update --skipVersionUpgrade
./scripts/build-release.sh
```

## 4) Google Play Console

1. أنشئ حساب مطوّر ([Google Play Console](https://play.google.com/console)) — رسوم تسجيل لمرة واحدة.
2. تطبيق جديد → رفع **AAB**.
3. أكمل: سياسة الخصوصية (رابط على موقعكم)، لقطات شاشة، أيقونة 512، تصنيف المحتوى، نموذج البيانات.
4. اختبر **Internal testing** قبل الإنتاج؛ تأكد أن التطبيق يفتح بدون شريط Chrome (TWA وليس Custom Tab).

## 5) تحديث الإصدار

في `twa-manifest.json` زِد `appVersionCode` و`appVersionName`، ثم:

```bash
npx @bubblewrap/cli update --skipVersionUpgrade
./scripts/build-release.sh
```

ارفع AAB الجديد على نفس التطبيق في Play Console.