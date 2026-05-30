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

واجهة تشغيلية متكاملة (Portal):

```text
http://localhost:5249/portal
```

الواجهة توفر تجربة قريبة من المنتج النهائي (نمط محاسبي عملي):

- تسجيل دخول JWT وإدارة جلسة المستخدم.
- لوحة تحكم بمؤشرات رئيسية (health + أعداد العملاء/المواد/الفواتير/السندات).
- شاشة مستندات (فواتير/سندات) بنمط master-detail مع فلترة سريعة وأنواع ديناميكية ونافذة تفاصيل.
- شاشة مواد عملية بجدول كثيف + تفاصيل وصور.
- شاشة عملاء مرتبطة بملخص الحساب وكشف الحساب.
- الواجهة موجهة للمستخدم النهائي ولا تعرض JSON داخل الشاشة التشغيلية.

### تجاوز المصادقة مؤقتًا أثناء التطوير

للتطوير المحلي فقط يمكنك تجاوز تسجيل الدخول في Swagger عبر:

```json
"DevelopmentAuth": {
  "BypassSwaggerAuth": true,
  "UserId": "11111111-1111-1111-1111-111111111111",
  "UserName": "dev-admin",
  "Role": "Admin"
}
```

- يعمل فقط في بيئة `Development`.
- عند تفعيله لا تحتاج Login/Token في Swagger.
- أعده إلى `false` بعد الانتهاء من الاختبارات المحلية.

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

GET  /api/accounts
GET  /api/accounts/{guid}
GET  /api/customers/{guid}/account/summary
GET  /api/customers/{guid}/account/statement

GET  /api/materials
GET  /api/materials/{guid}
GET  /api/materials/filter-options
GET  /api/materials/stores

GET    /api/material-images/settings
PUT    /api/material-images/settings
GET    /api/material-images
POST   /api/material-images
GET    /api/material-images/{id}
GET    /api/material-images/{id}/file
GET    /api/material-images/{id}/thumbnail
PUT    /api/material-images/links/materials/{materialGuid}/images/{imageGuid}
POST   /api/material-images/unlink
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
files[]       مجموعة صور (مطلوب)
materialGuid  اختياري للربط المباشر عند رفع صورة واحدة
```

عند رفع صورة:

- تحفظ في مجلد الصور من الإعدادات.
- إذا كان الاسم موجوداً، يتم تعديل الاسم تلقائياً مثل `image_1.jpg`.
- يتم توليد thumbnail داخل مجلد الثامبنيل.
- يُنشأ سجل في `bm000` (GUID جديد + المسار الكامل للصورة).
- كل صورة ترتبط بمادة واحدة كحد أعلى عبر `mt000.PictureGUID`.
- إذا تم رفع أكثر من صورة، يتم تجاهل `materialGuid` تلقائياً وتُحفظ الصور كغير مرتبطة حتى يتم ربطها لاحقاً.

إدارة الربط:

```http
PUT    /api/material-images/links/materials/{materialGuid}/images/{imageGuid}
POST   /api/material-images/unlink
```

Body:

```json
{
  "materialGuid": "00000000-0000-0000-0000-000000000000",
  "imageGuid": "00000000-0000-0000-0000-000000000000"
}
```

ملاحظة:

- عند ربط صورة بمادة، يتم فك أي ربط سابق لنفس الصورة من مواد أخرى لضمان علاقة (صورة واحدة ↔ مادة واحدة).
- عند فك الربط عبر `POST /api/material-images/unlink` يكفي إرسال `materialGuid` أو `imageGuid` أو كليهما، ولا يشترط إرسال الاثنين معًا.

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

## Accounts Directory API

أول تكامل مع قاعدة النظام الحالية هو جدول العملاء:

```text
cu000 -> GET /api/accounts
cu000 -> GET /api/accounts/{guid}
cu000 -> GET /api/accounts?keyword=محمد 1001
```

يدعم `keyword` بنفس مبدأ المواد: تقسيم النص إلى كلمات (space-separated) وتطبيق AND بين الكلمات على الحقول النصية ذات الصلة.

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

## Customer Accounts API

ملخص حساب العميل:

```http
GET /api/accounts/summary?accountGuid={accountGuid}
GET /api/accounts/summary?customerGuid={customerGuid}
GET /api/accounts/summary?accountGuid={accountGuid}&customerGuid={customerGuid}
GET /api/customers/{guid}/account/summary
```

> المسار تحت `/api/accounts` هو المسار المرن الأساسي الجديد.
> المسار `/api/customers/{guid}/account/*` بقي مدعومًا للتوافق الخلفي.

الاستجابة تعرض (بعملة الحساب):

- عملة الحساب (`accountCurrencyGuid`)
- سعر التعادل المعتمد (`accountCurrencyRate`)
- اسم/كود/رمز عملة الحساب (`accountCurrencyName`, `accountCurrencyCode`, `accountCurrencySymbol`)
- الرصيد الحالي (`currentBalance`)
- آخر حركة دائن (`lastCreditorMovement`) مع التاريخ وسبب الحركة ونوع السند
- آخر حركة مدين (`lastDebtorMovement`) مع التاريخ وسبب الحركة ونوع السند

سبب الحركة (`reasonType`) يكون:

- `invoice` = فاتورة
- `payment` = دفعة
- `discount` = حسم/إشعار
- `opening` = قيد افتتاحي
- `unknown` = غير مصنف

ونوع السند النصي يعاد في الحقل:

- `reasonDocumentType`

وعند غياب ربط مباشر في جداول العلاقات، يحاول النظام استنتاج النوع من `vwER.erParentType` والبيانات النصية في الملاحظات (مثل: قبض/دفع/مبيع/شراء) مع إرجاع رقم المرجع من `ParentNumber` أو رقم القيد.

كشف حساب تفصيلي:

```http
GET /api/accounts/statement?accountGuid={accountGuid}
GET /api/accounts/statement?customerGuid={customerGuid}
GET /api/accounts/statement?accountGuid={accountGuid}&customerGuid={customerGuid}
GET /api/accounts/statement?accountGuid={accountGuid}&fromDate=2026-01-01&toDate=2026-01-31&page=1&pageSize=100
GET /api/customers/{guid}/account/statement
GET /api/customers/{guid}/account/statement?fromDate=2026-01-01&toDate=2026-01-31&page=1&pageSize=100
```

ملاحظة مهمة:

- يجب تمرير `accountGuid` أو `customerGuid` (يكفي أحدهما).
- إذا كان `customerGuid` غير مرتبط بحساب في بطاقة العميل، يجب تمرير `accountGuid` صراحة.
- عند تمرير الحقلين معًا، يتم تطبيق الفلترة على القيود بكليهما (حساب + زبون).

يتطلب:

```text
accounts.read
entries.read   (لكشف الحساب التفصيلي)
```

يرجع القيود مرتبة زمنيًا مع:

- قيمة المدين والدائن بعملة الحساب
- قيمة المدين والدائن بالعملة الرئيسية (`debitMainCurrency` / `creditMainCurrency`)
- بيانات عملة الحساب في رأس الاستجابة (`accountCurrencyName`, `accountCurrencyCode`, `accountCurrencySymbol`)
- التحويل لعملة الحساب يتم بسعر التعادل الخاص بكل قيد (`currencyRateUsed`) مع اعتماد سعر الحساب كبديل عند غيابه
- الإشارة الصافية للحركة (`signedAmount`)
- الرصيد التراكمي (`runningBalance`)
- نوع المرجع (فاتورة/دفعة/حسم) مع نوع السند الفعلي (`reasonDocumentType`) ورقم/تاريخ المرجع عند توفرها
- تفاصيل الحساب المقابل لكل سطر: `contraAccountGuid`, `contraAccountNumber`, `contraAccountCode`, `contraAccountName`
- عند غياب تصنيف مرجع مباشر، يتم استنتاج نوع العملية من الحساب المقابل واتجاه القيد (مثل: `قبض` / `دفع` / `مبيع`) مع الاعتماد على اختصارات الأنواع من `vwEt/vwBt/vwNt` عند توفرها.

## Bills & Vouchers Browse API

للتصفح المباشر للفواتير والسندات بكل أنواعها:

```http
GET /api/bills/invoices?page=1&pageSize=100
GET /api/bills/invoices?typeGuid={typeGuid}&fromDate=2026-01-01&toDate=2026-01-31&keyword=1254
GET /api/bills/invoices?type=مبيع
GET /api/bills/invoices/{guid}

GET /api/bills/vouchers?page=1&pageSize=100
GET /api/bills/vouchers?typeGuid={typeGuid}&fromDate=2026-01-01&toDate=2026-01-31&keyword=قبض
GET /api/bills/vouchers?type=قبض
GET /api/bills/vouchers/{guid}
```

بحث `keyword` في الفواتير/السندات يدعم تقسيم الكلمات وتطبيق AND بينها (أي كل كلمة يجب أن تحقق مطابقة).

خيارات الأنواع (للقوائم المنسدلة/الفلاتر):

```http
GET /api/bills/invoice-types
GET /api/bills/voucher-types
```

المسارات تعيد:

- رقم المستند وتاريخه وملاحظاته.
- `typeGuid` ونوع المستند النصي (`typeName`) والاختصار (`typeCode`) عند توفره.
- لتصفّح أسلس حسب النوع: يمكن إرسال `type` كنص مباشر (اسم/اختصار) بدل الحاجة إلى `typeGuid`.
- نوع التسوية (`settlementTypeCode`, `settlementTypeName`) مثل `cash/credit` أو `قبض/دفع` حسب نوع السند.
- بيانات العميل والحساب المرتبطين بالمستند عند توفرها:
  - `customerGuid`, `customerName`
  - `accountGuid`, `accountNumber`, `accountCode`, `accountName`
- بيانات العملة من ربط `bu000/py000.CurrencyGUID` مع `my000`:
  - `currencyGuid`, `currencyName`, `currencyCode`, `currencySymbol`
  - `currencyRate` (مأخوذ من `CurrencyVal` في الفاتورة/السند نفسه، ويُستخدم لقسمة القيم)
- المجاميع عند توفرها من جداول الأمين:
  - `totalAmount`
  - `totalDiscount`
  - `totalAdditions`
  - `netAmount`
- حسابات المقابل للحسم/الإضافة (عند توفرها):
  - `discountAccountGuid`, `discountAccountNumber`, `discountAccountCode`, `discountAccountName`
  - `additionAccountGuid`, `additionAccountNumber`, `additionAccountCode`, `additionAccountName`
- Pagination قياسية: `page`, `pageSize`, `totalCount`.

استجابة التفاصيل (`GET /api/bills/invoices/{guid}`) تضيف:

- عناصر الفاتورة (`items`) مع:
  - المادة: `materialGuid`, `materialNumber`, `materialCode`, `materialName`
  - الكميات: `quantityUnit1`, `quantityUnit2`, `quantity`
  - السعر: `unitPriceUnit1` و `price` (بعد التحويل بعملة البيع)
  - القيم: `discount`, `additions`, `lineTotal`
- مجاميع إضافية في التفاصيل:
  - `linesCount`, `totalQuantity`, `totalPairs`, `totalPens`

الصلاحية المطلوبة:

```text
bills.read
```

## Materials Read API

تكامل المواد يقرأ من جدول:

```text
mt000 -> GET /api/materials
mt000 -> GET /api/materials/{guid}
```

يدعم البحث:

```text
GET /api/materials?keyword=اسم-او-كود-او-باركود
GET /api/materials?code=MAT-1001
GET /api/materials?hasImage=true
GET /api/materials?withoutImage=true
```

- الحقل `code` مخصص للمطابقة التامة لكود المادة.
- الحقل `keyword` يقسم عبارة البحث إلى كلمات (حسب الفراغات)، ويشترط ظهور **كل الكلمات** في النتيجة (بأي ترتيب).
- يمكن استخدامه مع `code`، وعندها تكون النتيجة تقاطع الفلترين.
- الحقل `hasImage=true` يجلب المواد التي تحتوي صورة مرتبطة.
- الحقل `withoutImage=true` يجلب المواد التي لا تحتوي صورة مرتبطة.

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
  ]
}
```

قوائم `groups` و `stores` تعاد مرتبة أبجديًا.

حاليًا لا يتم إرجاع `priceRanges` ضمن `GET /api/materials/filter-options`.

يمكن ضبط المطابقة التامة للكود عبر `code`، بينما `keyword` مخصص للبحث المرن متعدد الكلمات.

ويدعم فلترة الكمية حسب مستودع واحد أو عدة مستودعات:

```text
GET /api/materials?storeGuids=STORE_GUID_1,STORE_GUID_2
GET /api/materials/{guid}?storeGuids=STORE_GUID_1,STORE_GUID_2
```

ويتوفر الآن نمط عرض الكميات:

```text
GET /api/materials?detailedQuantity=false
GET /api/materials?detailedQuantity=true
GET /api/materials?storeGuids=STORE_GUID_1,STORE_GUID_2&detailedQuantity=true
GET /api/materials/{guid}?storeGuids=STORE_GUID_1,STORE_GUID_2&detailedQuantity=true
```

- `detailedQuantity=false` (الافتراضي): يعيد `warehouseQuantity` كمجموع الكمية ضمن المستودعات الممررة (أو `mt000.Qty` عند عدم تمرير مستودعات).
- `detailedQuantity=true`: يعيد أيضًا `storeQuantities` (قائمة كميات مفصلة حسب المستودع: `storeGuid`, `storeName`, `quantity`).
- قراءة الكميات حسب المستودعات تتطلب صلاحية:

```text
inventory.read
```

ولعرض قائمة المستودعات باسمائها وGUID:

```text
GET /api/materials/stores
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

حقول مضافة في الاستجابة:

```text
groupName         -> اسم الجروب المرتبط بـ groupGuid
productImageTitle -> عنوان/اسم ملف الصورة المرتبط بـ productImageGuid (من bm000.Name)
storeQuantities   -> الكميات حسب المستودعات عند detailedQuantity=true
```

تم تبسيط الاستجابة بإزالة الحقول غير الضرورية تشغيليًا مثل:

```text
recordNumber, latinName, barcode, isPackageConversionFixed,
averagePrice, lastPrice, classificationType, securityLevel, usageFlag
```

خريطة الأسعار الحالية:

```text
unitSalePriceSyp     -> Whole    سعر مبيع الوحدة بالليرة السورية الجديدة
unitSalePriceUsd     -> Half     سعر مبيع الوحدة بالدولار
unitPurchasePriceUsd -> EndUser  سعر شراء الوحدة بالدولار
```

سعر شراء الدولار `unitPurchasePriceUsd` حقل حساس مرتبط بصلاحية الحقل `EndUser`، وافتراضياً لا يظهر إلا لمن يملك صلاحية حقلية مناسبة أو دور Admin.
