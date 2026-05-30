# حساب خدمة API للموقع (Portal Service Account)

الموقع (PHP) يتصل بـ API الأمين عبر `api_proxy.php` باستخدام JWT — **ليس** بيانات اعتماد الزائر.

## إنشاء الحساب في ApiManagementDb

1. أنشئ مستخدماً في `ApiUsers`، مثلاً:
   - `UserName`: `portal-service`
   - `DisplayName`: `موقع الويب — بروكسي`
2. عيّن دوراً بصلاحيات قراءة (كحد أدنى):
   - `materials.read`
   - `material-images.read`
   - `material-images` (رفع/ربط) إن كانت لوحة الموقع ترفع صوراً
3. **لا** تمنح `bills.read` أو `accounts.read` إلا إذا احتجتم لاحقاً.

## إعدادات PHP (سيرفر خارجي — `.env`)

```env
PORTAL_API_BASE_URL=https://your-tunnel-or-vpn-to-local/api
PORTAL_API_USERNAME=portal-service
PORTAL_API_PASSWORD=<strong-password>
PORTAL_DB_DSN=pgsql:host=127.0.0.1;dbname=portal_db
PORTAL_DB_USER=portal_app
PORTAL_DB_PASSWORD=<db-password>
```

## تدفق البروكسي

1. عند أول طلب (أو انتهاء التوكن): `POST /api/auth/login`
2. تخزين `accessToken` + `refreshToken` في ملف cache أو Redis (ليس في Git)
3. كل طلب للمواد: `Authorization: Bearer {accessToken}`
4. عند 401: تجديد عبر `POST /api/auth/refresh`

## مسارات API الجديدة (بديل V1)

| القديم (V1) | الجديد |
|-------------|--------|
| `POST /api/V1/Material/filter` | `GET /api/materials?search=&page=&pageSize=` + فلاتر |
| `GET /api/V1/Material/filter-options` | `GET /api/materials/filter-options` |
| `POST /api/V1/Image/upload` | `POST /api/material-images` (multipart) |
| `GET /api/V1/Image/...` | `GET /api/material-images/{guid}/file` |

خريطة تفصيلية تُكمَّل عند تنفيذ `api_proxy.php`.
