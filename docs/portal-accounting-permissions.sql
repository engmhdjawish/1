-- Add accounting permissions and accountant role to an existing portal_db.
-- Run once after deploying the navigation/permissions update.

INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
VALUES
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

INSERT INTO web_roles (code, name_ar, description_ar, is_system)
VALUES ('accountant', 'محاسبة', 'الوصول لسجلات الأمين والتقارير المالية', TRUE)
ON CONFLICT (code) DO UPDATE SET
    name_ar = EXCLUDED.name_ar,
    description_ar = EXCLUDED.description_ar,
    is_system = EXCLUDED.is_system;

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
