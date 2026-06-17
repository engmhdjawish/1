-- Seed data for portal_db (Arabic labels)
-- Run after portal-db-schema.sql

-- Access policies
INSERT INTO access_policies (code, name_ar, description_ar, show_price, show_quantity, allow_cart, allow_order)
VALUES
    ('guest_browse', 'زائر — تصفح فقط', 'عرض المواد بدون سعر ولا طلب', FALSE, FALSE, FALSE, FALSE),
    ('guest_full', 'زائر — متجر عام', 'عرض السعر والسلة والطلب', TRUE, FALSE, TRUE, TRUE),
    ('share_standard', 'رابط مشاركة — قياسي', 'كما يُعرّف في الرابط (افتراضي)', TRUE, FALSE, TRUE, TRUE),
    ('customer_standard', 'عميل مفعّل — قياسي', 'عميل ويب بعد الموافقة', TRUE, TRUE, TRUE, TRUE),
    ('customer_vip', 'عميل مفعّل — مميز', 'عرض كميات + طلب', TRUE, TRUE, TRUE, TRUE)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    description_ar = EXCLUDED.description_ar,
    show_price = EXCLUDED.show_price,
    show_quantity = EXCLUDED.show_quantity,
    allow_cart = EXCLUDED.allow_cart,
    allow_order = EXCLUDED.allow_order;

-- Web roles
INSERT INTO web_roles (code, name_ar, description_ar, is_system)
VALUES
    ('super_admin', 'مدير النظام', 'صلاحيات كاملة على الموقع', TRUE),
    ('sales', 'مبيعات', 'روابط مشاركة وطلبات وزوّار', TRUE),
    ('content', 'محتوى', 'الصفحة الرئيسية ومعلومات الشركة', TRUE),
    ('customers_admin', 'إدارة العملاء', 'موافقة وتفعيل عملاء الويب', TRUE),
    ('accountant', 'محاسبة', 'الوصول لسجلات الأمين والتقارير المالية', TRUE)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    description_ar = EXCLUDED.description_ar,
    is_system = EXCLUDED.is_system;

-- Web permissions
INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES
    ('dashboard.view', 'عرض لوحة التحكم', 'عام', NULL),
    ('home_sections.manage', 'إدارة أقسام الرئيسية', 'محتوى', NULL),
    ('special_offers.manage', 'إدارة العروض الخاصة', 'محتوى', 'عروض الموقع والحسومات'),
    ('company_settings.manage', 'إعدادات الشركة', 'محتوى', NULL),
    ('store_policy.manage', 'سياسة المتجر العام', 'إعدادات', NULL),
    ('share_links.manage', 'إدارة روابط المشاركة', 'مبيعات', NULL),
    ('orders.view', 'عرض الطلبات', 'مبيعات', NULL),
    ('orders.manage', 'إدارة الطلبات (تغيير الحالة)', 'مبيعات', NULL),
    ('visitors.view', 'عرض سجل الزوّار', 'تحليلات', NULL),
    ('web_customers.view', 'عرض عملاء الويب', 'عملاء', NULL),
    ('web_customers.approve', 'موافقة وتفعيل عملاء الويب', 'عملاء', NULL),
    ('web_customers.manage', 'إنشاء وتعديل عملاء الويب', 'عملاء', NULL),
    ('web_users.manage', 'إدارة موظفي الموقع', 'إدارة', NULL),
    ('images.upload', 'رفع صور المواد', 'مواد', NULL),
    ('site_media.manage', 'مكتبة صور الموقع', 'محتوى', 'رفع وإدارة بنرات وإعلانات وشعارات الموقع'),
    ('access_policies.manage', 'إدارة سياسات الوصول', 'إعدادات', NULL),
    ('accounting.view', 'لوحة المحاسب', 'محاسبة', NULL),
    ('accounting.customers.view', 'عملاء الأمين', 'محاسبة', NULL),
    ('accounting.documents.view', 'الفواتير والسندات', 'محاسبة', NULL),
    ('accounting.statement.view', 'كشف حساب عميل', 'محاسبة', NULL),
    ('accounting.sync.view', 'طابور المزامنة', 'محاسبة', NULL),
    ('accounting.reports.view', 'التقارير المالية', 'محاسبة', NULL)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

-- Role → permission mapping
INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
CROSS JOIN web_permissions p
WHERE r.code = 'super_admin'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view', 'share_links.manage', 'orders.view', 'orders.manage', 'visitors.view'
)
WHERE r.code = 'sales'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view', 'home_sections.manage', 'special_offers.manage', 'company_settings.manage', 'images.upload', 'site_media.manage'
)
WHERE r.code = 'content'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view', 'web_customers.view', 'web_customers.approve', 'web_customers.manage'
)
WHERE r.code = 'customers_admin'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'accounting.view',
    'accounting.customers.view',
    'accounting.documents.view',
    'accounting.statement.view',
    'accounting.sync.view',
    'accounting.reports.view'
)
WHERE r.code = 'accountant'
ON CONFLICT DO NOTHING;

-- Default store guest policy (متجر عام)
INSERT INTO store_guest_settings (id, access_policy_id)
SELECT 1, id FROM access_policies WHERE code = 'guest_full'
ON CONFLICT (id) DO UPDATE SET access_policy_id = EXCLUDED.access_policy_id;

-- Default company settings
INSERT INTO company_settings (key, value_ar)
VALUES
    ('company_name', 'جاويش للتجارة'),
    ('company_phone', '00963-11-2213299'),
    ('company_mobile', '00963932997794'),
    ('company_whatsapp', '+963932997794'),
    ('company_email', ''),
    ('company_address', 'دمشق - حريقة - شارع المأمون'),
    ('company_logo', ''),
    ('about_us_title_ar', 'من نحن'),
    ('about_us_ar', ''),
    ('material_images_dir', ''),
    ('material_thumbnails_dir', '')
ON CONFLICT (key) DO NOTHING;

-- Example homepage sections (inactive until configured)
INSERT INTO home_sections (slug, title_ar, display_mode, max_products, sort_order, is_active)
VALUES
    ('offers', 'عروض', 'filter', 12, 10, FALSE),
    ('women', 'نسواني', 'filter', 12, 20, FALSE),
    ('men', 'رجالي', 'filter', 12, 30, FALSE),
    ('new_arrivals', 'وصلنا حديثاً', 'filter', 12, 40, FALSE)
ON CONFLICT (slug) DO NOTHING;
