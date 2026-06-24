<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Database;
use PDO;

/**
 * Ensures staff task roles and permissions exist in portal_db (idempotent).
 */
final class StaffRoleProvisioner
{
    public static function ensureTaskRoles(): void
    {
        $pdo = Database::pdo();

        self::ensurePermissions($pdo);

        $upsertRole = $pdo->prepare(
            'INSERT INTO web_roles (code, name_ar, description_ar, is_system)
             VALUES (:code, :name_ar, :description_ar, TRUE)
             ON CONFLICT (code) DO UPDATE SET
                name_ar = EXCLUDED.name_ar,
                description_ar = EXCLUDED.description_ar,
                is_system = TRUE
             RETURNING id'
        );

        $insertRolePermission = $pdo->prepare(
            'INSERT INTO web_role_permissions (role_id, permission_id)
             SELECT :role_id, id
             FROM web_permissions
             WHERE code = :permission_code
             ON CONFLICT DO NOTHING'
        );

        foreach (StaffPermissions::taskRoles() as $task) {
            $code = trim((string) ($task['role_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $upsertRole->execute([
                'code' => $code,
                'name_ar' => (string) ($task['name_ar'] ?? $code),
                'description_ar' => (string) ($task['description_ar'] ?? ''),
            ]);
            $roleId = $upsertRole->fetchColumn();
            if ($roleId === false) {
                continue;
            }

            foreach ($task['permissions'] ?? [] as $permissionCode) {
                $permissionCode = trim((string) $permissionCode);
                if ($permissionCode === '') {
                    continue;
                }
                $insertRolePermission->execute([
                    'role_id' => $roleId,
                    'permission_code' => $permissionCode,
                ]);
            }
        }

        $pdo->prepare(
            'INSERT INTO web_role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM web_roles r
             JOIN web_permissions p ON p.code = \'images.view\'
             WHERE r.code = \'super_admin\'
             ON CONFLICT DO NOTHING'
        )->execute();
    }

    private static function ensurePermissions(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
             VALUES (:code, :name_ar, :category_ar, :description_ar)
             ON CONFLICT (code) DO UPDATE SET
                name_ar = EXCLUDED.name_ar,
                category_ar = EXCLUDED.category_ar,
                description_ar = EXCLUDED.description_ar'
        );

        foreach (StaffPermissions::catalog() as $item) {
            $code = trim((string) ($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $stmt->execute([
                'code' => $code,
                'name_ar' => (string) ($item['name_ar'] ?? $code),
                'category_ar' => (string) ($item['category_ar'] ?? 'عام'),
                'description_ar' => (string) ($item['description_ar'] ?? ''),
            ]);
        }
    }
}
