<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class OrderService
{
    private const ALLOWED_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled'];

    /**
     * @param list<array{
     *   material_guid: string,
     *   material_code?: string,
     *   material_name_ar: string,
     *   quantity: float,
     *   pcs_per_box?: int,
     *   sale_price_sp?: float,
     *   sale_price_usd?: float,
     *   image_url?: string|null
     * }> $items
     * @return array{
     *   ok: bool,
     *   order?: array{id: string, order_number: string, quote_access_token: string, total_sp: float, total_usd: float},
     *   unavailable_items?: list<array<string, mixed>>,
     *   notices?: list<string>,
     *   message?: string
     * }
     */
    public static function createGuestShareOrder(
        string $shareLinkId,
        string $guestNameAr,
        string $guestPhone,
        ?string $notesAr,
        array $items
    ): array {
        $shareLinkId = trim($shareLinkId);
        $guestNameAr = trim($guestNameAr);
        $guestPhone = trim($guestPhone);
        $notesAr = $notesAr !== null ? trim($notesAr) : null;

        if ($shareLinkId === '' || $guestNameAr === '' || $guestPhone === '' || $items === []) {
            return ['ok' => false, 'message' => 'بيانات الطلب غير مكتملة.'];
        }

        $split = StockReservationService::splitCartByAvailability($items, true);
        $availableItems = $split['available'];
        $unavailableItems = $split['unavailable'];
        $notices = $split['notices'];

        if ($availableItems === []) {
            return [
                'ok' => false,
                'message' => 'لا توجد أصناف متاحة للطلب. راجع قسم «غير المتوفرة» في السلة.',
                'unavailable_items' => $unavailableItems,
                'notices' => $notices,
            ];
        }

        $normalizedItems = [];
        $totalSp = 0.0;
        $totalUsd = 0.0;
        $sortOrder = 0;
        foreach ($availableItems as $item) {
            $materialGuid = trim((string) ($item['material_guid'] ?? ''));
            $name = trim((string) ($item['material_name_ar'] ?? ''));
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($materialGuid === '' || $name === '' || $quantity <= 0) {
                continue;
            }

            $saleSp = (float) ($item['sale_price_sp'] ?? 0);
            $saleUsd = (float) ($item['sale_price_usd'] ?? 0);
            $totalSp += $quantity * $saleSp;
            $totalUsd += $quantity * $saleUsd;

            $normalizedItems[] = [
                'material_guid' => $materialGuid,
                'material_code' => trim((string) ($item['material_code'] ?? '')),
                'material_name_ar' => $name,
                'quantity' => $quantity,
                'pcs_per_box' => max(1, (int) ($item['pcs_per_box'] ?? 1)),
                'sale_price_sp' => $saleSp,
                'sale_price_usd' => $saleUsd,
                'original_sale_price_sp' => isset($item['original_sale_price_sp']) ? (float) $item['original_sale_price_sp'] : null,
                'original_sale_price_usd' => isset($item['original_sale_price_usd']) ? (float) $item['original_sale_price_usd'] : null,
                'special_offer_id' => trim((string) ($item['special_offer_id'] ?? '')) ?: null,
                'image_url' => isset($item['image_url']) && is_string($item['image_url']) ? trim($item['image_url']) : null,
                'sort_order' => $sortOrder++,
            ];
        }

        if ($normalizedItems === []) {
            return ['ok' => false, 'message' => 'لا توجد أصناف صالحة في الطلب.'];
        }

        $pdo = Database::pdo();
        $orderNumber = self::generateOrderNumber();
        $quoteToken = bin2hex(random_bytes(32));

        try {
            $pdo->beginTransaction();

            StockReservationService::lockMaterialsForOrder(
                $pdo,
                array_map(static fn (array $line): string => (string) $line['material_guid'], $normalizedItems)
            );

            $recheck = StockReservationService::splitCartByAvailability($normalizedItems, true);
            if ($recheck['available'] === []) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'message' => 'نفدت كمية أحد الأصناف أثناء إرسال الطلب. راجع السلة وحاول مجدداً.',
                    'unavailable_items' => array_merge($unavailableItems, $recheck['unavailable']),
                    'notices' => array_values(array_unique(array_merge($notices, $recheck['notices']))),
                ];
            }

            $normalizedItems = [];
            $totalSp = 0.0;
            $totalUsd = 0.0;
            $sortOrder = 0;
            foreach ($recheck['available'] as $item) {
                $materialGuid = trim((string) ($item['material_guid'] ?? ''));
                $name = trim((string) ($item['material_name_ar'] ?? ''));
                $quantity = (float) ($item['quantity'] ?? 0);
                if ($materialGuid === '' || $name === '' || $quantity <= 0) {
                    continue;
                }

                $saleSp = (float) ($item['sale_price_sp'] ?? 0);
                $saleUsd = (float) ($item['sale_price_usd'] ?? 0);
                $totalSp += $quantity * $saleSp;
                $totalUsd += $quantity * $saleUsd;

                $normalizedItems[] = [
                    'material_guid' => $materialGuid,
                    'material_code' => trim((string) ($item['material_code'] ?? '')),
                    'material_name_ar' => $name,
                    'quantity' => $quantity,
                    'pcs_per_box' => max(1, (int) ($item['pcs_per_box'] ?? 1)),
                    'sale_price_sp' => $saleSp,
                    'sale_price_usd' => $saleUsd,
                    'original_sale_price_sp' => isset($item['original_sale_price_sp']) ? (float) $item['original_sale_price_sp'] : null,
                    'original_sale_price_usd' => isset($item['original_sale_price_usd']) ? (float) $item['original_sale_price_usd'] : null,
                    'special_offer_id' => trim((string) ($item['special_offer_id'] ?? '')) ?: null,
                    'image_url' => isset($item['image_url']) && is_string($item['image_url']) ? trim($item['image_url']) : null,
                    'sort_order' => $sortOrder++,
                ];
            }

            $unavailableItems = array_merge($unavailableItems, $recheck['unavailable']);
            $notices = array_values(array_unique(array_merge($notices, $recheck['notices'])));

            $orderStmt = $pdo->prepare(
                'INSERT INTO orders (
                    order_number,
                    share_link_id,
                    guest_name_ar,
                    guest_phone,
                    status,
                    total_sp,
                    total_usd,
                    notes_ar,
                    quote_access_token
                 ) VALUES (
                    :order_number,
                    :share_link_id,
                    :guest_name_ar,
                    :guest_phone,
                    :status,
                    :total_sp,
                    :total_usd,
                    :notes_ar,
                    :quote_access_token
                 )
                 RETURNING id::text'
            );
            $orderStmt->execute([
                'order_number' => $orderNumber,
                'share_link_id' => $shareLinkId,
                'guest_name_ar' => $guestNameAr,
                'guest_phone' => $guestPhone,
                'status' => 'pending',
                'total_sp' => $totalSp,
                'total_usd' => $totalUsd,
                'notes_ar' => $notesAr !== '' ? $notesAr : null,
                'quote_access_token' => $quoteToken,
            ]);
            $orderId = (string) $orderStmt->fetchColumn();
            if ($orderId === '') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'تعذر إنشاء الطلب.'];
            }

            $itemStmt = $pdo->prepare(
                'INSERT INTO order_items (
                    order_id,
                    material_guid,
                    material_code,
                    material_name_ar,
                    quantity,
                    pcs_per_box,
                    sale_price_sp,
                    sale_price_usd,
                    original_sale_price_sp,
                    original_sale_price_usd,
                    special_offer_id,
                    image_url,
                    sort_order
                 ) VALUES (
                    :order_id,
                    :material_guid,
                    :material_code,
                    :material_name_ar,
                    :quantity,
                    :pcs_per_box,
                    :sale_price_sp,
                    :sale_price_usd,
                    :original_sale_price_sp,
                    :original_sale_price_usd,
                    :special_offer_id,
                    :image_url,
                    :sort_order
                 )'
            );

            foreach ($normalizedItems as $line) {
                $itemStmt->execute([
                    'order_id' => $orderId,
                    'material_guid' => $line['material_guid'],
                    'material_code' => $line['material_code'] !== '' ? $line['material_code'] : null,
                    'material_name_ar' => $line['material_name_ar'],
                    'quantity' => $line['quantity'],
                    'pcs_per_box' => $line['pcs_per_box'],
                    'sale_price_sp' => $line['sale_price_sp'],
                    'sale_price_usd' => $line['sale_price_usd'],
                    'original_sale_price_sp' => $line['original_sale_price_sp'],
                    'original_sale_price_usd' => $line['original_sale_price_usd'],
                    'special_offer_id' => $line['special_offer_id'],
                    'image_url' => $line['image_url'],
                    'sort_order' => $line['sort_order'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر حفظ الطلب. حاول مرة أخرى.'];
        }

        return [
            'ok' => true,
            'order' => [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'quote_access_token' => $quoteToken,
                'total_sp' => $totalSp,
                'total_usd' => $totalUsd,
            ],
            'submitted_material_guids' => array_map(
                static fn (array $line): string => (string) $line['material_guid'],
                $normalizedItems
            ),
            'unavailable_items' => $unavailableItems,
            'notices' => $notices,
        ];
    }

    private static function generateOrderNumber(): string
    {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

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
                    o.notes_ar,
                    wc.name_ar AS customer_name_ar,
                    wc.phone AS customer_phone,
                    o.guest_name_ar,
                    o.guest_phone,
                    sl.name_ar AS share_link_name,
                    COALESCE(items.items_count, 0) AS items_count,
                    COALESCE(items.packages_count, 0)::float8 AS packages_count
                FROM orders o
                LEFT JOIN web_customers wc ON wc.id = o.web_customer_id
                LEFT JOIN share_links sl ON sl.id = o.share_link_id
                LEFT JOIN (
                    SELECT
                        order_id,
                        COUNT(*)::int AS items_count,
                        COALESCE(SUM(quantity), 0)::float8 AS packages_count
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

        $totalPackages = 0.0;
        $totalPieces = 0.0;
        foreach ($items as &$item) {
            $quantity = max(0.0, (float) ($item['quantity'] ?? 0));
            $packaging = max(1, (int) ($item['pcs_per_box'] ?? 1));
            $packagePriceUsd = max(0.0, (float) ($item['sale_price_usd'] ?? 0));
            $packagePriceSp = max(0.0, (float) ($item['sale_price_sp'] ?? 0));

            $item['packages_count'] = $quantity;
            $item['packaging'] = $packaging;
            $item['unit_sale_price_usd'] = $packaging > 0 ? $packagePriceUsd / $packaging : 0.0;
            $item['unit_sale_price_sp'] = $packaging > 0 ? $packagePriceSp / $packaging : 0.0;
            $item['line_total_usd'] = $quantity * $packagePriceUsd;
            $item['line_total_sp'] = $quantity * $packagePriceSp;
            $item['pieces_count'] = $quantity * $packaging;

            $totalPackages += $quantity;
            $totalPieces += $quantity * $packaging;
        }
        unset($item);

        $order['summary'] = [
            'items_count' => count($items),
            'packages_count' => $totalPackages,
            'pieces_count' => $totalPieces,
        ];
        $order['display_name'] = trim((string) ($order['customer_name_ar'] ?? '')) !== ''
            ? (string) $order['customer_name_ar']
            : (trim((string) ($order['guest_name_ar'] ?? '')) !== '' ? (string) $order['guest_name_ar'] : '—');
        $order['display_phone'] = trim((string) ($order['customer_phone'] ?? '')) !== ''
            ? (string) $order['customer_phone']
            : (trim((string) ($order['guest_phone'] ?? '')) !== '' ? (string) $order['guest_phone'] : '—');

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
