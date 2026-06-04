<?php

declare(strict_types=1);

namespace Portal\Services;

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
                    sl.is_active,
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

    public static function countActive(): int
    {
        $value = Database::pdo()->query(
            'SELECT COUNT(*)::int FROM share_links WHERE is_active = TRUE'
        )->fetchColumn();

        return (int) $value;
    }
}
