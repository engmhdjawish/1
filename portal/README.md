# بوابة جاويش (PHP + PostgreSQL)

تطوير محلي أولاً، ثم نشر على سيرفر خارجي. قاعدة بيانات **منفصلة** عن الأمين وعن `ApiManagementDb`.

## المتطلبات

- PHP 8.2+ (`pdo_pgsql`, `curl`, `mbstring`)
- PostgreSQL 16+
- Composer
- تشغيل **ExistingDb.Api** محلياً + حساب خدمة (`portal-service`)

## 1) قاعدة PostgreSQL

### باستخدام Docker (موصى به)

```bash
cd portal
docker compose up -d
```

### إنشاء القاعدة يدوياً (Windows / محلي)

```sql
CREATE USER portal WITH PASSWORD 'portal';
CREATE DATABASE portal_db OWNER portal;
```

## 2) إعداد المشروع

```bash
cd portal
copy .env.example .env
composer install
```

عدّل `.env`:

```env
PORTAL_DB_HOST=127.0.0.1
PORTAL_DB_PORT=5432
PORTAL_DB_NAME=portal_db
PORTAL_DB_USER=portal
PORTAL_DB_PASSWORD=portal

AMINE_API_BASE_URL=http://127.0.0.1:5000
AMINE_API_USERNAME=portal-service
AMINE_API_PASSWORD=YourApiPassword

PORTAL_APP_URL=http://127.0.0.1:8080
```

## 3) إنشاء الجداول

```bash
php scripts/setup-database.php
php scripts/run-migrations.php
```

أو يدوياً:

```bash
psql -U portal -d portal_db -f ../docs/portal-db-schema.sql
psql -U portal -d portal_db -f ../docs/portal-db-seed.sql
php scripts/run-migrations.php
```

## النشر للإنتاج

راجع **[deploy/README.md](../deploy/README.md)** — معالج تفاعلي.

**نفس سيرفر الأمين + IIS (بدون PostgreSQL جاهز):**  
راجع **[deploy/WINDOWS-IIS-LOCAL.md](../deploy/WINDOWS-IIS-LOCAL.md)** — نسخ PostgreSQL من جهاز آخر، IIS، وترتيب النشر.

```powershell
.\deploy\wizard.ps1
```

```bash
./deploy/wizard.sh
```

## 4) أول مستخدم لوحة (موظّف)

```bash
php scripts/create-admin.php admin Admin@123 "مدير النظام"
```

## 5) حساب خدمة API

أنشئ مستخدم `portal-service` في ApiManagementDb بصلاحية `materials.read` (ورفع صور لاحقاً).

## 6) تشغيل الموقع

```bash
cd public
php -S 127.0.0.1:8080
```

افتح: http://127.0.0.1:8080/index.php

## الصفحات

| المسار | الوصف |
|--------|--------|
| `/index.php` | رئيسية (أقسام `home_sections` النشطة) |
| `/store.php` | متجر عام |
| `/register.php` | تسجيل عميل → `pending` |
| `/login.php` | دخول موظّف أو عميل مفعّل |
| `/share.php?token=...` | صفحة رابط مشاركة عام بقيود العرض/الفلاتر |
| `/dashboard/index.php` | لوحة الإدارة الرئيسية |
| `/dashboard/customers.php` | إدارة العملاء (عرض/إضافة/تعديل/موافقة) |
| `/dashboard/orders.php` | إدارة الطلبات + تغيير الحالة |
| `/dashboard/share-links.php` | إنشاء/تعديل روابط مشاركة بقيود وفلاتر وخيارات عرض |
| `/dashboard/home-sections.php` | إدارة أقسام الرئيسية |
| `/dashboard/users.php` | إدارة المستخدمين والأدوار |
| `/dashboard/settings.php` | إعدادات الشركة وسياسة المتجر |
| `/store.php` | المتجر العام (بحث، فلاتر، صور، ترقيم) |
| `/product.php?guid=…` | تفاصيل مادة |
| `/dashboard/accounting.php` | لوحة المحاسب (يتطلب `accounting.view`) |
| `/dashboard/accounting-sync.php` | طابور مزامنة الأمين |
| `/dashboard/accounting-reports.php` | تقرير مالي أولي |
| `/dashboard/accounting-statement.php` | كشف حساب (بحث عميل/حساب تلقائي + نافذة تفاصيل الفاتورة/السند) |
| `/dashboard/accounting-statement-api.php` | بروكسي JSON لكشف الحساب والعملاء والحسابات والفواتير/السندات |
| `/dashboard/accounting-customers.php` | عملاء الأمين |
| `/dashboard/accounting-documents.php` | الفواتير والسندات |
| `/dashboard/material-images.php` | مخزون صور الموقع + رفع متسلسل مع استئناف (IndexedDB) |
| `/dashboard/material-images-api.php` | API رفع صورة واحدة + قائمة الملفات المحلية |
| `/api/image.php?id=...` | عرض صورة مادة من مجلد الموقع فقط (GUID → ملف محلي، بدون بروكسي API) |
| `/media/material.php?file=...` | عرض ملف صورة مادة محلي بالاسم |
| `/api/proxy.php` | بروكسي JSON للـ API |

## تفعيل أقسام الرئيسية

```sql
UPDATE home_sections SET is_active = TRUE WHERE slug IN ('offers','women','men','new_arrivals');
```

أضف فلاتر في `home_section_filters` حسب الحاجة.

## صلاحيات الموظفين

بعد `php scripts/run-migrations.php` (يشمل `007-staff-roles-reorganization.sql`) تتوفر أدوار مهام جاهزة من `/dashboard/users.php`:

| الدور | المهمة |
|--------|--------|
| `order_desk` | طلبات + مزامنة الأمين |
| `sales` | مبيعات، روابط مشاركة، نشاط الزوار |
| `catalog_media` | رفع ومزامنة صور المواد |
| `customers_admin` | موافقة وإدارة عملاء الموقع |
| `content` | محتوى الرئيسية والعروض والوسائط |
| `communications` | إشعارات الموقع |
| `store_admin` | سياسات المتجر والوصول |
| `accountant` | محاسبة أمين كاملة |
| `super_admin` | كل الصلاحيات |

**فصل مهم:** صلاحية `orders.view` لا تفتح لوحة المحاسبة. كل صفحة محاسبة تتطلب صلاحيتها (`accounting.*`). صلاحية `images.view` للتصفح والتحميل فقط؛ `images.upload` للرفع والربط والمزامنة.

## هيكل المجلدات

```text
portal/
  public/          ← جذر الويب
  src/             ← PHP classes
  views/           ← قوالب عربية
  scripts/         ← إعداد DB + إنشاء admin
  storage/         ← توكن API (لا يُرفع لـ Git)
```
