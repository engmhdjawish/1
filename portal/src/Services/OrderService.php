<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class OrderService
{
    private const ALLOWED_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled'];

    /** @param array{status?: string, q?: string, sync?: string, limit?: int} $filters */
    public static function list(array $filters = []): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));
        $sync = trim((string) ($filters['sync'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));

        $sql = 'SELECT
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_sp,
                    o.total_usd,
                    o.amine_sync_status,
                    o.created_at,
                    o.updated_at,
                    o.quote_access_token,
                    wc.name_ar AS customer_name_ar,
                    o.guest_name_ar,
                    sl.name_ar AS share_link_name,
                    COALESCE(items.items_count, 0) AS items_count
                FROM orders o
                LEFT JOIN web_customers wc ON wc.id = o.web_customer_id
                LEFT JOIN share_links sl ON sl.id = o.share_link_id
                LEFT JOIN (
                    SELECT order_id, COUNT(*)::int AS items_count
                    FROM order_items
                    GROUP BY order_id
                ) items ON items.order_id = o.id
                WHERE 1 = 1';
        $params = [];

        if ($status !== '' && in_array($status, self::ALLOWED_STATUSES, true)) {
            $sql .= ' AND o.status = :status';
            $params['status'] = $status;
        }

        if ($sync !== '') {
            $sql .= ' AND o.amine_sync_status = :sync_status';
            $params['sync_status'] = $sync;
        }

        if ($search !== '') {
            $sql .= ' AND (
                o.order_number ILIKE :search
                OR wc.name_ar ILIKE :search
                OR o.guest_name_ar ILIKE :search
                OR o.guest_phone ILIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY o.created_at DESC LIMIT :limit';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updateStatus(string $orderId, string $nextStatus): bool
    {
        if (!in_array($nextStatus, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE orders
             SET status = :status, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $orderId,
            'status' => $nextStatus,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function statusCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT status::text AS status, COUNT(*)::int AS count
             FROM orders
             GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach (self::ALLOWED_STATUSES as $status) {
            $counts[$status] = 0;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    public static function syncCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT amine_sync_status::text AS status, COUNT(*)::int AS count
             FROM orders
             GROUP BY amine_sync_status'
        )->fetchAll(PDO::FETCH_ASSOC);

        $counts = ['none' => 0, 'pending' => 0, 'synced' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /** @return list<array{status: string, orders_count: int, total_sp: float, total_usd: float}> */
    public static function financialSummary(): array
    {
        $rows = Database::pdo()->query(
            'SELECT
                status::text AS status,
                COUNT(*)::int AS orders_count,
                COALESCE(SUM(total_sp), 0)::float8 AS total_sp,
                COALESCE(SUM(total_usd), 0)::float8 AS total_usd
             FROM orders
             GROUP BY status
             ORDER BY status'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'status' => (string) $row['status'],
                'orders_count' => (int) $row['orders_count'],
                'total_sp' => (float) $row['total_sp'],
                'total_usd' => (float) $row['total_usd'],
            ],
            $rows
        );
    }
}
