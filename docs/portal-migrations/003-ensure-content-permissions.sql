-- Ensure dashboard content permissions exist and are granted to system roles.
-- Safe to run multiple times on existing portal_db databases.

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES
    ('home_sections.manage', 'إدارة أقسام الرئيسية', 'محتوى', NULL),
    ('special_offers.manage', 'إدارة العروض الخاصة', 'محتوى', 'عروض الموقع والحسومات'),
    ('company_settings.manage', 'إعدادات الشركة', 'محتوى', NULL),
    ('site_media.manage', 'مكتبة صور الموقع', 'محتوى', 'رفع وإدارة بنرات وإعلانات وشعارات الموقع'),
    ('access_policies.manage', 'إدارة سياسات الوصول', 'إعدادات', NULL),
    ('store_policy.manage', 'سياسة المتجر العام', 'إعدادات', NULL),
    ('images.upload', 'رفع صور المواد', 'مواد', NULL),
    ('web_users.manage', 'إدارة موظفي الموقع', 'إدارة', NULL)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    category_ar = EXCLUDED.category_ar,
    description_ar = EXCLUDED.description_ar;

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code IN (
    'dashboard.view',
    'home_sections.manage',
    'special_offers.manage',
    'company_settings.manage',
    'site_media.manage',
    'images.upload',
    'store_policy.manage',
    'access_policies.manage',
    'web_users.manage'
)
WHERE r.code IN ('super_admin', 'content')
ON CONFLICT DO NOTHING;
