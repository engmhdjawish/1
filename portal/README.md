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
```

أو يدوياً:

```bash
psql -U portal -d portal_db -f ../docs/portal-db-schema.sql
psql -U portal -d portal_db -f ../docs/portal-db-seed.sql
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
| `/dashboard/accounting.php` | لوحة المحاسب |
| `/dashboard/accounting-sync.php` | طابور مزامنة الأمين |
| `/dashboard/accounting-reports.php` | تقرير مالي أولي |
| `/dashboard/accounting-statement.php` | كشف حساب عميل عبر API |
| `/dashboard/material-images.php` | رفع صور المواد محلياً بنفس أسماء الأمين + توليد thumbnail |
| `/api/image.php?id=...` | عرض صورة مادة (محلي أولاً ثم API كاحتياط) |
| `/media/material.php?file=...` | عرض ملف صورة مادة محلي بالاسم |
| `/api/proxy.php` | بروكسي JSON للـ API |

## تفعيل أقسام الرئيسية

```sql
UPDATE home_sections SET is_active = TRUE WHERE slug IN ('offers','women','men','new_arrivals');
```

أضف فلاتر في `home_section_filters` حسب الحاجة.

## هيكل المجلدات

```text
portal/
  public/          ← جذر الويب
  src/             ← PHP classes
  views/           ← قوالب عربية
  scripts/         ← إعداد DB + إنشاء admin
  storage/         ← توكن API (لا يُرفع لـ Git)
```
