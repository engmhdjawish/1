INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
SELECT 'sessions.manage', 'الجلسات النشطة', 'إدارة', 'متابعة المتصلين وإنهاء الجلسات'
WHERE NOT EXISTS (
    SELECT 1 FROM web_permissions WHERE code = 'sessions.manage'
);

INSERT INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
CROSS JOIN web_permissions p
WHERE r.code = 'super_admin'
  AND p.code = 'sessions.manage'
  AND NOT EXISTS (
      SELECT 1 FROM web_role_permissions rp
      WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
