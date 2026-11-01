-- Grant images.view to floor roles that download ZIP from the store (existing deployments).
INSERT IGNORE INTO web_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM web_roles r
JOIN web_permissions p ON p.code = 'images.view'
WHERE r.code IN ('order_desk', 'sales');
