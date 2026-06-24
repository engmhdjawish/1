-- Reorganize staff roles and permissions for clearer task distribution.
-- Safe to run multiple times on existing portal_db.

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES
    ('images.view', 'عرض صور المواد', 'مواد', 'تصفح وتحميل الصور دون رفع أو حذف')
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_roles (code, name_ar, description_ar, is_system)
VALUES
    ('order_desk', 'مكتب الطلبات', 'متابعة الطلبات ومزامنتها مع الأمين', TRUE),
    ('catalog_media', 'صور المواد', 'رفع ومزامنة وربط صور المواد', TRUE),
    ('communications', 'التواصل', 'إشعارات الموقع والعملاء', TRUE),
    ('store_admin', 'إعداد المتجر', 'سياسات الزائر والوصول وروابط المشاركة', TRUE)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    description_ar = EXCLUDED.description_ar,
    is_system = EXCLUDED.is_system;

DELETE FROM web_role_permissions rp
USING web_roles r, web_permissions p
WHERE rp.role_id = r.id
  AND rp.permission_id = p.id
  AND r.code = 'content'
  AND p.code IN ('web_users.manage', 'access_policies.manage', 'store_policy.manage', 'images.upload');

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'home_sections.manage',
    'special_offers.manage',
    'company_settings.manage',
    'site_media.manage'
)
WHERE r.code = 'content'
ON CONFLICT DO NOTHING;

DELETE FROM web_role_permissions rp
USING web_roles r, web_permissions p
WHERE rp.role_id = r.id
  AND rp.permission_id = p.id
  AND r.code = 'sales'
  AND p.code IN ('accounting.sync.view', 'accounting.reports.view');

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'share_links.manage',
    'orders.view',
    'orders.manage',
    'visitors.view'
)
WHERE r.code = 'sales'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'orders.view',
    'orders.manage',
    'accounting.sync.view'
)
WHERE r.code = 'order_desk'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'images.view',
    'images.upload'
)
WHERE r.code = 'catalog_media'
ON CONFLICT DO NOTHING;

DELETE FROM web_role_permissions rp
USING web_roles r, web_permissions p
WHERE rp.role_id = r.id
  AND rp.permission_id = p.id
  AND r.code = 'content'
  AND p.code = 'notifications.manage';

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'notifications.manage'
)
WHERE r.code = 'communications'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'store_policy.manage',
    'access_policies.manage',
    'share_links.manage'
)
WHERE r.code = 'store_admin'
ON CONFLICT DO NOTHING;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'images.view'
WHERE r.code IN ('super_admin', 'catalog_media')
ON CONFLICT DO NOTHING;
