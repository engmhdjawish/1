<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\Password;
use Portal\Database;
use PDO;

final class ShareLinkService
{
    /** @param array{active?: string, q?: string, limit?: int} $filters */
    public static function list(array $filters = []): array
    {
        $isActive = trim((string) ($filters['active'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));

        $sql = 'SELECT
                    sl.id,
                    sl.public_token,
                    sl.name_ar,
                    sl.keyword,
                    sl.min_quantity,
                    sl.expires_at,
                    CASE WHEN sl.is_active THEN 1 ELSE 0 END AS is_active,
                    CASE WHEN sl.require_password THEN 1 ELSE 0 END AS require_password,
                    sl.created_at,
                    ap.name_ar AS access_policy_name_ar
                FROM share_links sl
                INNER JOIN access_policies ap ON ap.id = sl.access_policy_id
                WHERE 1 = 1';
        $params = [];

        if ($isActive === '1' || $isActive === '0') {
            $sql .= ' AND sl.is_active = :is_active';
            $params['is_active'] = $isActive === '1';
        }

        if ($search !== '') {
            $sql .= ' AND (sl.name_ar ILIKE :search OR sl.public_token ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY sl.created_at DESC LIMIT :limit';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                sl.id,
                sl.public_token,
                sl.name_ar,
                sl.access_policy_id::text AS access_policy_id,
                CASE WHEN sl.require_password THEN 1 ELSE 0 END AS require_password,
                sl.access_username,
                sl.password_hash,
                sl.keyword,
                sl.min_quantity,
                sl.expires_at,
                CASE WHEN sl.is_active THEN 1 ELSE 0 END AS is_active,
                sl.created_at,
                sl.updated_at
             FROM share_links sl
             WHERE sl.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return array{total: int, active: int, expired: int, protected: int} */
    public static function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active,
                COUNT(*) FILTER (WHERE expires_at IS NOT NULL AND expires_at < NOW())::int AS expired,
                COUNT(*) FILTER (WHERE require_password = TRUE)::int AS protected
             FROM share_links'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
            'protected' => (int) ($row['protected'] ?? 0),
        ];
    }

    /** @return list<array{id: string, code: string, name_ar: string}> */
    public static function listAccessPolicies(): array
    {
        return Database::pdo()->query(
            'SELECT id::text AS id, code, name_ar
             FROM access_policies
             WHERE is_active = TRUE
             ORDER BY name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok: bool, message: string, id?: string} */
    public static function save(
        ?string $id,
        string $name,
        string $accessPolicyId,
        bool $requirePassword,
        ?string $accessUsername,
        ?string $plainPassword,
        ?string $keyword,
        float $minQuantity,
        ?string $expiresAt,
        bool $isActive,
        ?string $userId
    ): array {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'اسم الرابط مطلوب.'];
        }

        $accessPolicyId = trim($accessPolicyId);
        if ($accessPolicyId === '') {
            return ['ok' => false, 'message' => 'سياسة الوصول مطلوبة.'];
        }

        $accessUsername = trim((string) $accessUsername);
        $keyword = trim((string) $keyword);
        $plainPassword = trim((string) $plainPassword);
        $expiresAt = trim((string) $expiresAt);
        $expiresAt = $expiresAt !== '' ? $expiresAt : null;
        $userId = $userId !== null ? trim($userId) : null;
        $userId = $userId !== '' ? $userId : null;

        if ($requirePassword && $accessUsername === '') {
            return ['ok' => false, 'message' => 'اسم مستخدم الوصول مطلوب عند تفعيل كلمة المرور.'];
        }

        $pdo = Database::pdo();
        if ($id === null || trim($id) === '') {
            if ($requirePassword && $plainPassword === '') {
                return ['ok' => false, 'message' => 'كلمة المرور مطلوبة عند إنشاء رابط محمي.'];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO share_links (
                    public_token,
                    name_ar,
                    access_policy_id,
                    require_password,
                    access_username,
                    password_hash,
                    keyword,
                    min_quantity,
                    expires_at,
                    is_active,
                    created_by_web_user_id
                 ) VALUES (
                    :token,
                    :name,
                    :policy_id,
                    CASE WHEN :require_password = 1 THEN TRUE ELSE FALSE END,
                    :access_username,
                    :password_hash,
                    :keyword,
                    :min_quantity,
                    :expires_at,
                    CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    :created_by
                 )
                 RETURNING id::text'
            );
            $stmt->execute([
                'token' => self::generateToken(),
                'name' => $name,
                'policy_id' => $accessPolicyId,
                'require_password' => $requirePassword ? 1 : 0,
                'access_username' => $accessUsername !== '' ? $accessUsername : null,
                'password_hash' => $requirePassword ? Password::hash($plainPassword) : null,
                'keyword' => $keyword !== '' ? $keyword : null,
                'min_quantity' => max(0, $minQuantity),
                'expires_at' => $expiresAt,
                'is_active' => $isActive ? 1 : 0,
                'created_by' => $userId,
            ]);

            return [
                'ok' => true,
                'message' => 'تم إنشاء رابط المشاركة.',
                'id' => (string) $stmt->fetchColumn(),
            ];
        }

        $existing = self::getById($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'الرابط المطلوب غير موجود.'];
        }

        $nextPasswordHash = $existing['require_password'] ? (string) ($existing['password_hash'] ?? '') : null;
        if ($requirePassword) {
            if ($plainPassword !== '') {
                $nextPasswordHash = Password::hash($plainPassword);
            } elseif ($nextPasswordHash === null || $nextPasswordHash === '') {
                return ['ok' => false, 'message' => 'أدخل كلمة مرور جديدة للرابط المحمي.'];
            }
        } else {
            $nextPasswordHash = null;
            $accessUsername = '';
        }

        $update = $pdo->prepare(
            'UPDATE share_links SET
                name_ar = :name,
                access_policy_id = :policy_id,
                require_password = CASE WHEN :require_password = 1 THEN TRUE ELSE FALSE END,
                access_username = :access_username,
                password_hash = :password_hash,
                keyword = :keyword,
                min_quantity = :min_quantity,
                expires_at = :expires_at,
                is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'id' => $id,
            'name' => $name,
            'policy_id' => $accessPolicyId,
            'require_password' => $requirePassword ? 1 : 0,
            'access_username' => $requirePassword ? ($accessUsername !== '' ? $accessUsername : null) : null,
            'password_hash' => $nextPasswordHash,
            'keyword' => $keyword !== '' ? $keyword : null,
            'min_quantity' => max(0, $minQuantity),
            'expires_at' => $expiresAt,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return [
            'ok' => true,
            'message' => 'تم تحديث رابط المشاركة.',
            'id' => $id,
        ];
    }

    public static function setActive(string $id, bool $isActive): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE share_links
             SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function countActive(): int
    {
        $value = Database::pdo()->query(
            'SELECT COUNT(*)::int FROM share_links WHERE is_active = TRUE'
        )->fetchColumn();

        return (int) $value;
    }

    private static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
