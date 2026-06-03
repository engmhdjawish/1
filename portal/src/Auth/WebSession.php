<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Database;
use PDO;

final class WebSession
{
    private const SESSION_KEY = 'web_user';

    public static function user(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login.php?type=staff');
            exit;
        }
    }

    public static function requirePermission(string $permissionCode): void
    {
        self::requireLogin();
        $permissions = self::user()['permissions'] ?? [];
        if (!in_array($permissionCode, $permissions, true) && !in_array('*', $permissions, true)) {
            http_response_code(403);
            echo 'غير مصرح لك بهذه العملية.';
            exit;
        }
    }

    public static function login(string $userName, string $password): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.user_name, u.display_name_ar, u.password_hash, u.is_active
             FROM web_users u WHERE u.user_name = :user_name LIMIT 1'
        );
        $stmt->execute(['user_name' => $userName]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['is_active'] || !Password::verify($password, $user['password_hash'])) {
            return false;
        }

        $permissions = self::loadPermissions($user['id']);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $user['id'],
            'user_name' => $user['user_name'],
            'display_name_ar' => $user['display_name_ar'],
            'permissions' => $permissions,
        ];

        $pdo->prepare('UPDATE web_users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @return list<string> */
    private static function loadPermissions(string $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT p.code
             FROM web_permissions p
             INNER JOIN web_role_permissions rp ON rp.permission_id = p.id
             INNER JOIN web_user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('dashboard.view', $codes, true)) {
            foreach ($pdo->query('SELECT code FROM web_permissions')->fetchAll(PDO::FETCH_COLUMN) as $code) {
                if ($code && str_starts_with($code, 'super_')) {
                    continue;
                }
            }
        }

        $roleStmt = $pdo->prepare(
            'SELECT r.code FROM web_roles r
             INNER JOIN web_user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id'
        );
        $roleStmt->execute(['user_id' => $userId]);
        $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('super_admin', $roles, true)) {
            return ['*'];
        }

        return array_values(array_filter($codes));
    }
}
