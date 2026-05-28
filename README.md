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

لا ينشئ المشروع Stored Procedures جديدة. قاعدة `ApiManagementDb` تدار عبر EF Core migrations، أما قاعدة النظام الحالية فتستخدم للقراءة أو لاستدعاء Stored Procedures الموجودة فقط عند الحاجة.

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

خريطة الحقول التجارية:

```text
code                       -> Code        رقم المادة ورمز الباركود
primaryUnit                -> Unity       وحدة القطعة، غالباً زوج
secondaryUnit              -> Unit2       الوحدة الثانية، غالباً طرد
secondUnitConversionFactor -> Unit2Fact   تعبئة الطرد / معامل التحويل
qty                        -> Qty         الكمية الموجودة في المستودعات
origin                     -> Origin      بلد المنشأ
company                    -> Company     الشركة المصنعة
dim                        -> Dim         المقاسات
color                      -> Color       الصنف، مثل PVC / EVA
provenance                 -> Provenance  الفئة العمرية
groupGuid                  -> GroupGUID   مجموعة المادة
pictureGuid                -> PictureGUID صورة المنتج
```

خريطة الأسعار الحالية:

```text
wholesaleSypPrice -> Whole    سعر مبيع الوحدة بالليرة السورية الجديدة
wholesaleUsdPrice -> Half     سعر مبيع الوحدة بالدولار
purchaseUsdPrice  -> EndUser  سعر شراء الوحدة بالدولار
```

سعر شراء الدولار `purchaseUsdPrice` حقل حساس مرتبط بصلاحية الحقل `EndUser`، وافتراضياً لا يظهر إلا لمن يملك صلاحية حقلية مناسبة أو دور Admin.
