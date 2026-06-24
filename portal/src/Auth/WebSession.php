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
            if (\Portal\Support\DashboardHttp::wantsJson()) {
                \Portal\Support\DashboardHttp::json(false, 'انتهت جلسة الدخول. سجّل الدخول مجدداً.', ['login' => true]);
            }
            $returnTo = rawurlencode(\Portal\Support\PortalUrl::currentPathWithQuery());
            header('Location: /login.php?type=staff&redirect=' . $returnTo);
            exit;
        }
    }

    public static function requirePermission(string $permissionCode): void
    {
        self::requireLogin();
        if (!self::hasPermission($permissionCode)) {
            if (\Portal\Support\DashboardHttp::wantsJson()) {
                \Portal\Support\DashboardHttp::json(false, 'غير مصرح لك بهذه العملية.');
            }
            http_response_code(403);
            echo 'غير مصرح لك بهذه العملية.';
            exit;
        }
    }

    /** @param list<string> $permissionCodes */
    public static function requireAnyPermission(array $permissionCodes): void
    {
        self::requireLogin();
        if (!self::hasAnyPermission($permissionCodes)) {
            if (\Portal\Support\DashboardHttp::wantsJson()) {
                \Portal\Support\DashboardHttp::json(false, 'غير مصرح لك بهذه العملية.');
            }
            http_response_code(403);
            echo 'غير مصرح لك بهذه العملية.';
            exit;
        }
    }

    public static function hasPermission(string $permissionCode): bool
    {
        $permissions = self::user()['permissions'] ?? [];

        return in_array('*', $permissions, true) || in_array($permissionCode, $permissions, true);
    }

    /** @param list<string> $permissionCodes */
    public static function hasAnyPermission(array $permissionCodes): bool
    {
        $permissions = self::user()['permissions'] ?? [];
        if (in_array('*', $permissions, true)) {
            return true;
        }

        foreach ($permissionCodes as $permissionCode) {
            if (in_array($permissionCode, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param-out string|null $errorMessage */
    public static function login(string $userName, string $password, ?string &$errorMessage = null): bool
    {
        $errorMessage = null;
        $userName = trim($userName);
        $password = trim($password);

        if ($userName === '' || $password === '') {
            $errorMessage = 'أدخل اسم المستخدم وكلمة المرور.';

            return false;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT
                u.id,
                u.user_name,
                u.display_name_ar,
                u.password_hash,
                CASE WHEN u.is_active THEN 1 ELSE 0 END AS is_active
             FROM web_users u
             WHERE u.user_name ILIKE :user_name
             LIMIT 1'
        );
        $stmt->execute(['user_name' => $userName]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $errorMessage = 'اسم المستخدم غير موجود.';

            return false;
        }

        if ((int) ($user['is_active'] ?? 0) !== 1) {
            $errorMessage = 'الحساب غير نشط. تواصل مع المدير لتفعيله.';

            return false;
        }

        if (!Password::verify($password, (string) ($user['password_hash'] ?? ''))) {
            $errorMessage = 'كلمة المرور غير صحيحة.';

            return false;
        }

        CustomerSession::logout();

        $permissions = self::loadPermissions($user['id']);
        $roles = self::loadRoleLabels($user['id']);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $user['id'],
            'user_name' => $user['user_name'],
            'display_name_ar' => $user['display_name_ar'],
            'permissions' => $permissions,
            'roles' => $roles,
            'role_label' => $roles[0] ?? 'موظف',
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

    /** @return list<string> */
    private static function loadRoleLabels(string $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT r.name_ar
             FROM web_roles r
             INNER JOIN web_user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id
             ORDER BY r.is_system DESC, r.name_ar ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
}
