<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\Password;
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

    /** @return list<array{id: string, code: string, name_ar: string, is_system: int, permissions_count: int}> */
    public static function listRoles(): array
    {
        return Database::pdo()->query(
            'SELECT
                r.id::text AS id,
                r.code,
                r.name_ar,
                CASE WHEN r.is_system THEN 1 ELSE 0 END AS is_system,
                COALESCE(perms.permissions_count, 0)::int AS permissions_count
             FROM web_roles r
             LEFT JOIN (
                SELECT role_id, COUNT(*)::int AS permissions_count
                FROM web_role_permissions
                GROUP BY role_id
             ) perms ON perms.role_id = r.id
             ORDER BY r.name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
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
               AND (:exclude_id = \'\' OR id::text <> :exclude_id)
             LIMIT 1'
        );
        $duplicate->execute([
            'user_name' => $userName,
            'exclude_id' => $id !== null ? trim($id) : '',
        ]);
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'اسم المستخدم مستخدم مسبقًا.'];
        }

        try {
            $pdo->beginTransaction();

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
                    'password_hash' => Password::hash($plainPassword),
                    'is_active' => $isActive ? 1 : 0,
                ]);
                $userId = (string) $insert->fetchColumn();
            } else {
                if ($plainPassword !== '') {
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
                        'password_hash' => Password::hash($plainPassword),
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
}
