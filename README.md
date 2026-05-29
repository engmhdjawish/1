# Existing DB Web API

مشروع ASP.NET Core Web API بلغة C# لاستضافة REST API فوق قاعدة SQL Server موجودة، مع قاعدة منفصلة لإدارة الأمان:

- JWT authentication
- Refresh tokens
- Users / roles / permissions
- Field-level permissions
- Audit logs
- قاعدة النظام الحالية `MainDb` للقراءة والتكامل لاحقاً
- قاعدة مستقلة `ApiManagementDb` للأمان والتدقيق

## المتطلبات على ويندوز

- .NET SDK 9.0.305 أو أحدث ضمن نفس major version
- SQL Server أو SQL Server Express / LocalDB
- SQL Server Management Studio
- Cursor أو Visual Studio Code

تحقق من SDK:

```powershell
dotnet --version
```

## تشغيل المشروع محلياً

```powershell
git clone <repo-url>
cd <repo-folder>
git checkout cursor/web-api-existing-db-f03f
dotnet restore ExistingDbWebApi.sln
dotnet build ExistingDbWebApi.sln
```

## إعداد الأسرار محلياً

لا تضع كلمات مرور SQL Server أو مفتاح JWT داخل Git. استخدم User Secrets:

```powershell
cd src/ExistingDb.Api
dotnet user-secrets init
dotnet user-secrets set "ConnectionStrings:ApiManagementDb" "Server=YOUR_SQL_SERVER;Database=ApiManagementDb;Trusted_Connection=True;TrustServerCertificate=True;"
dotnet user-secrets set "ConnectionStrings:MainDb" "Server=YOUR_SQL_SERVER;Database=YOUR_EXISTING_DB;Trusted_Connection=True;TrustServerCertificate=True;"
dotnet user-secrets set "Jwt:SigningKey" "REPLACE_WITH_A_LONG_RANDOM_SECRET_KEY_32_BYTES_OR_MORE"
```

إذا كنت تستخدم SQL Login:

```powershell
dotnet user-secrets set "ConnectionStrings:ApiManagementDb" "Server=YOUR_SQL_SERVER;Database=ApiManagementDb;User Id=YOUR_USER;Password=YOUR_PASSWORD;TrustServerCertificate=True;"
```

## إنشاء مستخدم Admin أولي

فعّل seed لأول تشغيل فقط:

```powershell
dotnet user-secrets set "SeedAdmin:Enabled" "true"
dotnet user-secrets set "SeedAdmin:UserName" "admin"
dotnet user-secrets set "SeedAdmin:Email" "admin@example.local"
dotnet user-secrets set "SeedAdmin:Password" "ChangeThisPassword123!"
dotnet user-secrets set "SeedAdmin:DisplayName" "API Administrator"
```

بعد إنشاء المستخدم، يمكنك تعطيله:

```powershell
dotnet user-secrets set "SeedAdmin:Enabled" "false"
```

## إنشاء قاعدة ApiManagementDb

التطبيق يطبق EF Core migrations تلقائياً عند التشغيل. ويمكنك تطبيقها يدوياً:

```powershell
dotnet tool install --global dotnet-ef --version 9.0.16
dotnet ef database update --project src/ExistingDb.Api --startup-project src/ExistingDb.Api --context ApiManagementDbContext
```

## التشغيل

```powershell
dotnet run --project src/ExistingDb.Api
```

ثم افتح Swagger:

```text
http://localhost:5249/swagger
```

## Endpoints المرحلة الأولى

```text
POST /api/auth/login
POST /api/auth/refresh
POST /api/auth/logout
POST /api/auth/change-password
GET  /api/auth/me

GET  /api/admin/users
POST /api/admin/users
POST /api/admin/users/{userId}/reset-password
GET  /api/admin/roles
PUT  /api/admin/roles/{roleId}/permissions
PUT  /api/admin/roles/{roleId}/field-permissions/{resourceFieldId}
GET  /api/admin/permissions
GET  /api/admin/resources

GET  /api/audit
GET  /api/health

GET  /api/customers
GET  /api/customers/{guid}

GET  /api/materials
GET  /api/materials/{guid}
GET  /api/materials/filter-options

GET    /api/material-images/settings
PUT    /api/material-images/settings
GET    /api/material-images
POST   /api/material-images
GET    /api/material-images/{id}
GET    /api/material-images/{id}/file
GET    /api/material-images/{id}/thumbnail
PUT    /api/material-images/{id}/material
DELETE /api/material-images/{id}/material
DELETE /api/material-images/{id}
GET    /api/materials/{materialGuid}/images
GET    /api/material-images/download
GET    /api/material-images/download/materials
GET    /api/material-images/download/bills/{billGuid}
```

## إدارة كلمات المرور

المستخدم يستطيع تغيير كلمة سره بعد تسجيل الدخول:

```http
POST /api/auth/change-password
```

```json
{
  "currentPassword": "OldPassword123!",
  "newPassword": "NewPassword123!"
}
```

الأدمن يستطيع إعادة تعيين كلمة سر مستخدم:

```http
POST /api/admin/users/{userId}/reset-password
```

```json
{
  "newPassword": "NewPassword123!"
}
```

عند تغيير أو إعادة تعيين كلمة السر، يتم إلغاء refresh tokens النشطة لذلك المستخدم.

## قرار التصميم

لا ينشئ المشروع Stored Procedures جديدة. قاعدة `ApiManagementDb` تدار عبر EF Core migrations. قاعدة النظام الحالية تستخدم أساسًا للقراءة، مع تحديثات صور المواد فقط (`bm000` و`mt000.PictureGUID`) عند استخدام واجهات الصور.

## Material Images API

إدارة الصور تعتمد على جداول قاعدة النظام الرئيسية:

- `bm000` لسجل الصورة (الاسم + GUID)
- `mt000.PictureGUID` لربط الصورة مع المادة

قاعدة `ApiManagementDb` تحتفظ فقط بجدول الإعدادات `ApiSettings` لمسارات الملفات.

الجداول:

```text
ApiSettings
```

الإعدادات الافتراضية:

```text
Images:Directory           = C:\images
Images:ThumbnailsDirectory = C:\images\thumbnails
```

يمكن قراءة وتعديل الإعدادات:

```http
GET /api/material-images/settings
PUT /api/material-images/settings
```

Body:

```json
{
  "imagesDirectory": "C:\\images",
  "thumbnailsDirectory": "C:\\images\\thumbnails"
}
```

رفع صورة واحدة أو مجموعة صور:

```http
POST /api/material-images
Content-Type: multipart/form-data
```

Form fields:

```text
file          صورة واحدة (اختياري إذا أرسلت files)
files         مجموعة صور (اختياري إذا أرسلت file)
materialGuid  اختياري لربط مباشر، ويُسمح به فقط مع صورة واحدة
```

عند رفع صورة:

- تحفظ في مجلد الصور من الإعدادات.
- إذا كان الاسم موجوداً، يتم تعديل الاسم تلقائياً مثل `image_1.jpg`.
- يتم توليد thumbnail داخل مجلد الثامبنيل.
- يُنشأ سجل في `bm000` (GUID جديد + المسار الكامل للصورة).
- كل صورة ترتبط بمادة واحدة كحد أعلى عبر `mt000.PictureGUID`.
- إذا تم رفع مجموعة صور، تُحفظ كصور غير مرتبطة حتى يتم ربطها لاحقاً.

إدارة الربط:

```http
PUT    /api/material-images/{id}/material
DELETE /api/material-images/{id}/material
```

Body:

```json
{
  "materialGuid": "00000000-0000-0000-0000-000000000000"
}
```

ملاحظة:

- عند ربط صورة بمادة، يتم فك أي ربط سابق لنفس الصورة من مواد أخرى لضمان علاقة (صورة واحدة ↔ مادة واحدة).

الاستعلام:

```http
GET /api/material-images
GET /api/material-images?linked=true
GET /api/material-images?linked=false
GET /api/material-images?materialGuid=MATERIAL_GUID
GET /api/materials/{materialGuid}/images
```

جلب الملف:

```http
GET /api/material-images/{id}/file
GET /api/material-images/{id}/thumbnail
```

تحميل الصور كملف ZIP:

```http
GET /api/material-images/download
GET /api/material-images/download?linked=true
GET /api/material-images/download?linked=false
GET /api/material-images/download?materialGuid=MATERIAL_GUID
GET /api/material-images/download/materials?search=...&groupGuids=...&storeGuids=...
GET /api/material-images/download/bills/{billGuid}
```

حذف الصورة:

```http
DELETE /api/material-images/{id}
```

يحذف السجل من `bm000`، ويفك الربط عبر `mt000.PictureGUID` إن وجد، ويحاول حذف ملف الصورة والثامبنيل من السيرفر.

## Customers Read API

أول تكامل مع قاعدة النظام الحالية هو جدول العملاء:

```text
cu000 -> GET /api/customers
cu000 -> GET /api/customers/{guid}
```

يتطلب:

```text
customers.read
```

وتطبق صلاحيات الحقول على:

```text
Phone1
Phone2
Mobile
EMail
AccountGUID
```

## Materials Read API

تكامل المواد يقرأ من جدول:

```text
mt000 -> GET /api/materials
mt000 -> GET /api/materials/{guid}
```

يدعم البحث:

```text
GET /api/materials?search=اسم-او-كود-او-باركود
```

ولبناء واجهة فلاتر في موقع أو تطبيق، استخدم:

```text
GET /api/materials/filter-options
```

يرجع خيارات جاهزة للقوائم مثل:

```json
{
  "countryOfOrigins": ["صيني", "وطني"],
  "manufacturers": ["شركة1", "شركة2"],
  "sizeRanges": ["سيري", "نمر كبار"],
  "materialTypes": ["PVC", "EVA"],
  "ageCategories": ["رجالي", "نسواني"],
  "groups": [
    {
      "guid": "00000000-0000-0000-0000-000000000000",
      "code": "SUMMER",
      "name": "صيفي",
      "latinName": "Summer"
    }
  ],
  "stores": [
    {
      "guid": "00000000-0000-0000-0000-000000000000",
      "code": "MAIN",
      "name": "المستودع الرئيسي",
      "latinName": "Main Store"
    }
  ],
  "priceRanges": {
    "unitSalePriceSyp": {
      "min": 100000,
      "max": 500000
    },
    "unitSalePriceUsd": {
      "min": 5,
      "max": 30
    },
    "unitPurchasePriceUsd": null
  }
}
```

نطاقات الأسعار تراعي الصلاحيات. إذا لم يكن المستخدم يملك صلاحية قراءة سعر الشراء `EndUser`، يرجع:

```json
"unitPurchasePriceUsd": null
```

إذا كانت قيمة البحث تطابق `Code` بشكل كامل، يعيد الـ API المادة المطابقة فقط. مثال: البحث عن `100` لا يعيد المادة ذات الكود `1000` إذا كان هناك كود مطابق تماماً `100`.

ويدعم فلترة الكمية حسب مستودع واحد أو عدة مستودعات:

```text
GET /api/materials?storeGuid=STORE_GUID
GET /api/materials?storeGuids=STORE_GUID_1,STORE_GUID_2
GET /api/materials/{guid}?storeGuid=STORE_GUID
```

كما يدعم فلاتر وصفية:

```text
GET /api/materials?countryOfOrigin=صيني
GET /api/materials?countryOfOrigins=صيني,وطني
GET /api/materials?manufacturer=اسم-الشركة
GET /api/materials?manufacturers=شركة1,شركة2
GET /api/materials?sizeRange=نمر-كبار
GET /api/materials?sizeRanges=سيري,نمر-كبار
GET /api/materials?materialType=PVC
GET /api/materials?materialTypes=PVC,EVA
GET /api/materials?ageCategory=رجالي
GET /api/materials?ageCategories=رجالي,نسواني
GET /api/materials?groupGuid=GROUP_GUID
GET /api/materials?groupGuids=GROUP_GUID_1,GROUP_GUID_2
```

القيم المفصولة بفواصل داخل نفس الفلتر تعمل كـ OR. مثال:

```text
GET /api/materials?materialTypes=PVC,EVA&ageCategories=رجالي,نسواني
```

يعني:

```text
(materialType contains PVC OR EVA)
AND
(ageCategory contains رجالي OR نسواني)
```

وفلاتر كمية وتوفر:

```text
GET /api/materials?isAvailable=true
GET /api/materials?isAvailable=false
GET /api/materials?minWarehouseQuantity=1
GET /api/materials?maxWarehouseQuantity=10
```

قاعدة التوفر:

```text
available     = warehouseQuantity > 0
not available = warehouseQuantity <= 0
```

أي كمية سالبة تعتبر غير متوفرة لأنها غالباً ناتجة عن خطأ جرد.

وفلاتر أسعار:

```text
GET /api/materials?minUnitSalePriceSyp=100000&maxUnitSalePriceSyp=200000
GET /api/materials?minUnitSalePriceUsd=10&maxUnitSalePriceUsd=20
GET /api/materials?minUnitPurchasePriceUsd=5&maxUnitPurchasePriceUsd=9
```

فلاتر الأسعار تخضع لصلاحيات الحقول. مثلاً فلتر `unitPurchasePriceUsd` يتطلب صلاحية قراءة حقل `EndUser`.

عند استخدام فلتر المستودعات تصبح:

```text
warehouseQuantity
```

هي مجموع كمية المادة في المستودع أو المستودعات المحددة، اعتماداً على:

```text
vwMaterialInventory -> MaterialGuid, StoreGuid, Qty
```

بدون فلتر مستودعات، تبقى `warehouseQuantity` قادمة من `mt000.Qty`، وهي كمية المادة في جميع المستودعات.

خريطة الحقول التجارية:

```text
materialCode             -> Code        رقم المادة ورمز الباركود
primaryUnit              -> Unity       وحدة القطعة، غالباً زوج
packageUnit              -> Unit2       الوحدة الثانية، غالباً طرد
packageConversionFactor  -> Unit2Fact   تعبئة الطرد / معامل التحويل
warehouseQuantity        -> Qty         الكمية الموجودة في المستودعات
countryOfOrigin          -> Origin      بلد المنشأ
manufacturer             -> Company     الشركة المصنعة
sizeRange                -> Dim         المقاسات
materialType             -> Color       الصنف، مثل PVC / EVA
ageCategory              -> Provenance  الفئة العمرية
groupGuid                -> GroupGUID   مجموعة المادة
productImageGuid         -> PictureGUID صورة المنتج
```

خريطة الأسعار الحالية:

```text
unitSalePriceSyp     -> Whole    سعر مبيع الوحدة بالليرة السورية الجديدة
unitSalePriceUsd     -> Half     سعر مبيع الوحدة بالدولار
unitPurchasePriceUsd -> EndUser  سعر شراء الوحدة بالدولار
```

سعر شراء الدولار `unitPurchasePriceUsd` حقل حساس مرتبط بصلاحية الحقل `EndUser`، وافتراضياً لا يظهر إلا لمن يملك صلاحية حقلية مناسبة أو دور Admin.
