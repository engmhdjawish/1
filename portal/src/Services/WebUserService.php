<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\Password;
use Portal\Auth\WebSession;
use Portal\Database;
use PDO;

final class WebUserService
{
    /** @return list<array<string, mixed>> */
    public static function listUsers(string $search = '', string $roleId = '', string $active = ''): array
    {
        $search = trim($search);
        $roleId = trim($roleId);
        $active = trim($active);

        $sql = 'SELECT
                    u.id::text AS id,
                    u.user_name,
                    u.display_name_ar,
                    u.email,
                    CASE WHEN u.is_active THEN 1 ELSE 0 END AS is_active,
                    u.last_login_at,
                    u.created_at,
                    COALESCE(STRING_AGG(r.name_ar, \', \' ORDER BY r.name_ar), \'\') AS roles_label
                FROM web_users u
                LEFT JOIN web_user_roles ur ON ur.user_id = u.id
                LEFT JOIN web_roles r ON r.id = ur.role_id
                WHERE 1 = 1';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (
                u.user_name ILIKE :search
                OR u.display_name_ar ILIKE :search
                OR u.email ILIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        if ($roleId !== '') {
            $sql .= ' AND EXISTS (
                SELECT 1
                FROM web_user_roles ur2
                WHERE ur2.user_id = u.id
                  AND ur2.role_id::text = :role_id
            )';
            $params['role_id'] = $roleId;
        }

        if ($active === '1' || $active === '0') {
            $sql .= ' AND u.is_active = CASE WHEN :active = 1 THEN TRUE ELSE FALSE END';
            $params['active'] = (int) $active;
        }

        $sql .= ' GROUP BY u.id, u.user_name, u.display_name_ar, u.email, u.is_active, u.last_login_at, u.created_at
                  ORDER BY u.created_at DESC';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{id: string, code: string, name_ar: string, description_ar: string, is_system: int, permissions_count: int, users_count: int}> */
    public static function listRoles(): array
    {
        return Database::pdo()->query(
            'SELECT
                r.id::text AS id,
                r.code,
                r.name_ar,
                COALESCE(r.description_ar, \'\') AS description_ar,
                CASE WHEN r.is_system THEN 1 ELSE 0 END AS is_system,
                COALESCE(perms.permissions_count, 0)::int AS permissions_count,
                COALESCE(users.users_count, 0)::int AS users_count
             FROM web_roles r
             LEFT JOIN (
                SELECT role_id, COUNT(*)::int AS permissions_count
                FROM web_role_permissions
                GROUP BY role_id
             ) perms ON perms.role_id = r.id
             LEFT JOIN (
                SELECT role_id, COUNT(DISTINCT user_id)::int AS users_count
                FROM web_user_roles
                GROUP BY role_id
             ) users ON users.role_id = r.id
             ORDER BY r.is_system DESC, r.name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{id: string, code: string, name_ar: string, category_ar: string, description_ar: string}> */
    public static function listPermissions(): array
    {
        return Database::pdo()->query(
            'SELECT
                id::text AS id,
                code,
                name_ar,
                category_ar,
                COALESCE(description_ar, \'\') AS description_ar
             FROM web_permissions
             ORDER BY category_ar, name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getRoleById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                code,
                name_ar,
                COALESCE(description_ar, \'\') AS description_ar,
                CASE WHEN is_system THEN 1 ELSE 0 END AS is_system
             FROM web_roles
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row['permission_ids'] = self::rolePermissionIds($id);

        return $row;
    }

    /** @return list<string> */
    public static function rolePermissionIds(string $roleId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT permission_id::text
             FROM web_role_permissions
             WHERE role_id = :role_id'
        );
        $stmt->execute(['role_id' => $roleId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{total: int, active: int, inactive: int, admins: int} */
    public static function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active,
                COUNT(*) FILTER (WHERE is_active = FALSE)::int AS inactive
             FROM web_users'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $admins = Database::pdo()->query(
            'SELECT COUNT(DISTINCT ur.user_id)::int
             FROM web_user_roles ur
             INNER JOIN web_roles r ON r.id = ur.role_id
             WHERE r.code = \'super_admin\''
        )->fetchColumn();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'admins' => (int) $admins,
        ];
    }

    public static function getUserById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                user_name,
                display_name_ar,
                email,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
             FROM web_users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row['role_ids'] = self::userRoleIds($id);
        return $row;
    }

    /** @return array{ok: bool, message: string} */
    public static function changeOwnPassword(string $userId, string $currentPassword, string $newPassword): array
    {
        $userId = trim($userId);
        $currentPassword = trim($currentPassword);
        $newPassword = trim($newPassword);

        if ($userId === '' || $currentPassword === '' || $newPassword === '') {
            return ['ok' => false, 'message' => 'جميع حقول كلمة المرور مطلوبة.'];
        }
        if (strlen($newPassword) < 6) {
            return ['ok' => false, 'message' => 'كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل.'];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT password_hash FROM web_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || $hash === '') {
            return ['ok' => false, 'message' => 'تعذر العثور على الحساب.'];
        }
        if (!Password::verify($currentPassword, (string) $hash)) {
            return ['ok' => false, 'message' => 'كلمة المرور الحالية غير صحيحة.'];
        }

        try {
            $newHash = Password::hash($newPassword);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $update = $pdo->prepare(
            'UPDATE web_users SET password_hash = :hash, updated_at = NOW() WHERE id = :id'
        );
        $update->execute(['hash' => $newHash, 'id' => $userId]);

        return ['ok' => true, 'message' => 'تم تحديث كلمة المرور بنجاح.'];
    }

    /** @return list<string> */
    public static function userRoleIds(string $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT role_id::text
             FROM web_user_roles
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $roleIds
     *  @return array{ok: bool, message: string, id?: string}
     */
    public static function saveUser(
        ?string $id,
        string $userName,
        string $displayNameAr,
        ?string $email,
        ?string $plainPassword,
        bool $isActive,
        array $roleIds
    ): array {
        $userName = trim($userName);
        $displayNameAr = trim($displayNameAr);
        $email = trim((string) $email);
        $plainPassword = trim((string) $plainPassword);
        $roleIds = array_values(array_unique(array_filter(array_map('trim', $roleIds))));

        if ($userName === '' || $displayNameAr === '') {
            return ['ok' => false, 'message' => 'اسم المستخدم والاسم المعروض مطلوبان.'];
        }
        if ($id === null || trim($id) === '') {
            if ($plainPassword === '') {
                return ['ok' => false, 'message' => 'كلمة المرور مطلوبة لإنشاء المستخدم.'];
            }
        }
        if ($roleIds === []) {
            return ['ok' => false, 'message' => 'اختر دورًا واحدًا على الأقل.'];
        }

        $pdo = Database::pdo();
        $duplicate = $pdo->prepare(
            'SELECT 1
             FROM web_users
             WHERE user_name = :user_name
               AND (:exclude_id_is_empty = \'\' OR id::text <> :exclude_id_value)
             LIMIT 1'
        );
        $excludeId = $id !== null ? trim($id) : '';
        $duplicate->execute([
            'user_name' => $userName,
            'exclude_id_is_empty' => $excludeId,
            'exclude_id_value' => $excludeId,
        ]);
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'اسم المستخدم مستخدم مسبقًا.'];
        }

        try {
            $pdo->beginTransaction();

            $passwordHash = null;
            if ($plainPassword !== '') {
                try {
                    $passwordHash = Password::hash($plainPassword);
                } catch (\Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    return ['ok' => false, 'message' => $exception->getMessage()];
                }
            }

            $userId = $id !== null ? trim($id) : '';
            if ($userId === '') {
                $insert = $pdo->prepare(
                    'INSERT INTO web_users (
                        user_name,
                        email,
                        display_name_ar,
                        password_hash,
                        is_active
                     ) VALUES (
                        :user_name,
                        :email,
                        :display_name_ar,
                        :password_hash,
                        CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END
                     )
                     RETURNING id::text'
                );
                $insert->execute([
                    'user_name' => $userName,
                    'email' => $email !== '' ? $email : null,
                    'display_name_ar' => $displayNameAr,
                    'password_hash' => $passwordHash,
                    'is_active' => $isActive ? 1 : 0,
                ]);
                $userId = (string) $insert->fetchColumn();
            } else {
                if ($passwordHash !== null) {
                    $update = $pdo->prepare(
                        'UPDATE web_users SET
                            user_name = :user_name,
                            email = :email,
                            display_name_ar = :display_name_ar,
                            password_hash = :password_hash,
                            is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                            updated_at = NOW()
                         WHERE id = :id'
                    );
                    $update->execute([
                        'id' => $userId,
                        'user_name' => $userName,
                        'email' => $email !== '' ? $email : null,
                        'display_name_ar' => $displayNameAr,
                        'password_hash' => $passwordHash,
                        'is_active' => $isActive ? 1 : 0,
                    ]);
                } else {
                    $update = $pdo->prepare(
                        'UPDATE web_users SET
                            user_name = :user_name,
                            email = :email,
                            display_name_ar = :display_name_ar,
                            is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                            updated_at = NOW()
                         WHERE id = :id'
                    );
                    $update->execute([
                        'id' => $userId,
                        'user_name' => $userName,
                        'email' => $email !== '' ? $email : null,
                        'display_name_ar' => $displayNameAr,
                        'is_active' => $isActive ? 1 : 0,
                    ]);
                }
            }

            $pdo->prepare('DELETE FROM web_user_roles WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);

            $insertRole = $pdo->prepare(
                'INSERT INTO web_user_roles (user_id, role_id)
                 SELECT :user_id, id
                 FROM web_roles
                 WHERE id::text = :role_id'
            );
            foreach ($roleIds as $roleId) {
                $insertRole->execute([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                ]);
            }

            $pdo->commit();
            WebSession::refreshPermissions();

            return [
                'ok' => true,
                'message' => $id ? 'تم تحديث المستخدم.' : 'تم إنشاء المستخدم.',
                'id' => $userId,
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'تعذر حفظ المستخدم: ' . $exception->getMessage()];
        }
    }

    public static function setActive(string $id, bool $isActive): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE web_users
             SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<string> $permissionIds
     * @return array{ok: bool, message: string, id?: string}
     */
    public static function saveRole(
        ?string $id,
        string $code,
        string $nameAr,
        ?string $descriptionAr,
        array $permissionIds
    ): array {
        $code = strtolower(trim($code));
        $nameAr = trim($nameAr);
        $descriptionAr = trim((string) $descriptionAr);
        $permissionIds = array_values(array_unique(array_filter(array_map('trim', $permissionIds))));
        $roleId = $id !== null ? trim($id) : '';

        if ($nameAr === '') {
            return ['ok' => false, 'message' => 'اسم الدور مطلوب.'];
        }
        if ($permissionIds === []) {
            return ['ok' => false, 'message' => 'اختر صلاحية واحدة على الأقل.'];
        }

        $pdo = Database::pdo();
        $existingRole = null;
        if ($roleId !== '') {
            $existingRole = self::getRoleById($roleId);
            if ($existingRole === null) {
                return ['ok' => false, 'message' => 'الدور غير موجود.'];
            }
            if ((int) ($existingRole['is_system'] ?? 0) === 1) {
                $code = (string) ($existingRole['code'] ?? $code);
            }
        }

        if ($roleId === '') {
            if ($code === '') {
                return ['ok' => false, 'message' => 'رمز الدور مطلوب.'];
            }
            if (!preg_match('/^[a-z][a-z0-9_]{2,79}$/', $code)) {
                return ['ok' => false, 'message' => 'رمز الدور يجب أن يبدأ بحرف إنجليزي ويحتوي أحرفًا وأرقامًا وشرطة سفلية فقط.'];
            }
        }

        if ($code !== '') {
            $duplicate = $pdo->prepare(
                'SELECT 1
                 FROM web_roles
                 WHERE code = :code
                   AND (:exclude_id_is_empty = \'\' OR id::text <> :exclude_id_value)
                 LIMIT 1'
            );
            $duplicate->execute([
                'code' => $code,
                'exclude_id_is_empty' => $roleId,
                'exclude_id_value' => $roleId,
            ]);
            if ($duplicate->fetchColumn()) {
                return ['ok' => false, 'message' => 'رمز الدور مستخدم مسبقًا.'];
            }
        }

        try {
            $pdo->beginTransaction();

            if ($roleId === '') {
                $insert = $pdo->prepare(
                    'INSERT INTO web_roles (code, name_ar, description_ar, is_system)
                     VALUES (:code, :name_ar, :description_ar, FALSE)
                     RETURNING id::text'
                );
                $insert->execute([
                    'code' => $code,
                    'name_ar' => $nameAr,
                    'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
                ]);
                $roleId = (string) $insert->fetchColumn();
            } else {
                $update = $pdo->prepare(
                    'UPDATE web_roles SET
                        name_ar = :name_ar,
                        description_ar = :description_ar
                     WHERE id = :id'
                );
                $update->execute([
                    'id' => $roleId,
                    'name_ar' => $nameAr,
                    'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
                ]);
            }

            $pdo->prepare('DELETE FROM web_role_permissions WHERE role_id = :role_id')
                ->execute(['role_id' => $roleId]);

            $insertPermission = $pdo->prepare(
                'INSERT INTO web_role_permissions (role_id, permission_id)
                 SELECT :role_id, id
                 FROM web_permissions
                 WHERE id::text = :permission_id'
            );
            foreach ($permissionIds as $permissionId) {
                $insertPermission->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }

            $pdo->commit();
            WebSession::refreshPermissions();

            return [
                'ok' => true,
                'message' => $id ? 'تم تحديث الدور.' : 'تم إنشاء الدور.',
                'id' => $roleId,
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر حفظ الدور: ' . $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteRole(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'message' => 'معرّف الدور غير صالح.'];
        }

        $role = self::getRoleById($id);
        if ($role === null) {
            return ['ok' => false, 'message' => 'الدور غير موجود.'];
        }
        if ((int) ($role['is_system'] ?? 0) === 1) {
            return ['ok' => false, 'message' => 'لا يمكن حذف أدوار النظام الافتراضية.'];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(DISTINCT user_id)::int
             FROM web_user_roles
             WHERE role_id = :role_id'
        );
        $stmt->execute(['role_id' => $id]);
        $usersCount = (int) $stmt->fetchColumn();
        if ($usersCount > 0) {
            return ['ok' => false, 'message' => 'لا يمكن حذف الدور لأنه مُعيَّن لـ ' . $usersCount . ' مستخدم. أزل الدور عنهم أولاً.'];
        }

        $delete = Database::pdo()->prepare('DELETE FROM web_roles WHERE id = :id');
        $delete->execute(['id' => $id]);

        if ($delete->rowCount() <= 0) {
            return ['ok' => false, 'message' => 'تعذر حذف الدور.'];
        }

        return ['ok' => true, 'message' => 'تم حذف الدور.'];
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteUser(string $id, string $currentUserId): array
    {
        $id = trim($id);
        $currentUserId = trim($currentUserId);
        if ($id === '') {
            return ['ok' => false, 'message' => 'معرّف المستخدم غير صالح.'];
        }
        if ($id === $currentUserId) {
            return ['ok' => false, 'message' => 'لا يمكن حذف الحساب الحالي أثناء تسجيل الدخول.'];
        }

        $target = self::getUserById($id);
        if ($target === null) {
            return ['ok' => false, 'message' => 'المستخدم غير موجود.'];
        }

        if (self::userHasRoleCode($id, 'super_admin') && self::countUsersWithRoleCode('super_admin') <= 1) {
            return ['ok' => false, 'message' => 'لا يمكن حذف آخر مدير نظام في البوابة.'];
        }

        $delete = Database::pdo()->prepare('DELETE FROM web_users WHERE id = :id');
        $delete->execute(['id' => $id]);
        if ($delete->rowCount() <= 0) {
            return ['ok' => false, 'message' => 'تعذر حذف المستخدم.'];
        }

        return ['ok' => true, 'message' => 'تم حذف المستخدم.'];
    }

    private static function userHasRoleCode(string $userId, string $roleCode): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1
             FROM web_user_roles ur
             INNER JOIN web_roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
               AND r.code = :role_code
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'role_code' => $roleCode,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private static function countUsersWithRoleCode(string $roleCode): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(DISTINCT ur.user_id)::int
             FROM web_user_roles ur
             INNER JOIN web_roles r ON r.id = ur.role_id
             WHERE r.code = :role_code'
        );
        $stmt->execute(['role_code' => $roleCode]);

        return (int) $stmt->fetchColumn();
    }
}
