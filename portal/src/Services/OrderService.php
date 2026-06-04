<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class OrderService
{
    private const ALLOWED_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled'];

    /** @param array{status?: string, q?: string, sync?: string, fromDate?: string, toDate?: string, limit?: int} $filters */
    public static function list(array $filters = []): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));
        $sync = trim((string) ($filters['sync'] ?? ''));
        $fromDate = trim((string) ($filters['fromDate'] ?? ''));
        $toDate = trim((string) ($filters['toDate'] ?? ''));
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

        if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) === 1) {
            $sql .= ' AND o.created_at::date >= :from_date';
            $params['from_date'] = $fromDate;
        }

        if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) === 1) {
            $sql .= ' AND o.created_at::date <= :to_date';
            $params['to_date'] = $toDate;
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

    /** @return array<string, mixed>|null */
    public static function getOrderDetails(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT
                o.id::text AS id,
                o.order_number,
                o.status::text AS status,
                o.amine_sync_status::text AS amine_sync_status,
                o.total_sp,
                o.total_usd,
                o.notes_ar,
                o.reservation_notes_ar,
                o.amine_sync_error_ar,
                o.created_at,
                o.updated_at,
                o.amine_synced_at,
                o.quote_access_token,
                wc.name_ar AS customer_name_ar,
                wc.phone AS customer_phone,
                o.guest_name_ar,
                o.guest_phone,
                sl.name_ar AS share_link_name
             FROM orders o
             LEFT JOIN web_customers wc ON wc.id = o.web_customer_id
             LEFT JOIN share_links sl ON sl.id = o.share_link_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            return null;
        }

        $itemsStmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                material_guid::text AS material_guid,
                material_code,
                material_name_ar,
                quantity,
                pcs_per_box,
                sale_price_sp,
                sale_price_usd,
                image_url,
                sort_order
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY sort_order ASC, material_name_ar ASC'
        );
        $itemsStmt->execute(['order_id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $timeline = [];
        $timeline[] = [
            'label' => 'إنشاء الطلب',
            'at' => (string) ($order['created_at'] ?? ''),
        ];
        if ((string) ($order['updated_at'] ?? '') !== '' && ($order['updated_at'] ?? '') !== ($order['created_at'] ?? '')) {
            $timeline[] = [
                'label' => 'آخر تحديث',
                'at' => (string) ($order['updated_at'] ?? ''),
            ];
        }
        if ((string) ($order['amine_synced_at'] ?? '') !== '') {
            $timeline[] = [
                'label' => 'تمت مزامنة الأمين',
                'at' => (string) ($order['amine_synced_at'] ?? ''),
            ];
        }

        $order['items'] = $items;
        $order['timeline'] = $timeline;
        return $order;
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
