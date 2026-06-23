<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class OrderService
{
    private const ALLOWED_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled'];

    private static ?bool $hasItemEditSchema = null;

    public static function hasItemEditSchema(): bool
    {
        if (self::$hasItemEditSchema !== null) {
            return self::$hasItemEditSchema;
        }

        try {
            $pdo = Database::pdo();
            $changesTable = (bool) $pdo->query(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'order_item_changes'
                 LIMIT 1"
            )->fetchColumn();
            $statusColumn = (bool) $pdo->query(
                "SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'order_items' AND column_name = 'status'
                 LIMIT 1"
            )->fetchColumn();
            self::$hasItemEditSchema = $changesTable && $statusColumn;
        } catch (\Throwable) {
            self::$hasItemEditSchema = false;
        }

        return self::$hasItemEditSchema;
    }

    public static function ensureItemEditSchema(): bool
    {
        if (self::hasItemEditSchema()) {
            return true;
        }

        $migrationPath = dirname(__DIR__, 3) . '/docs/portal-migration-order-item-edits.sql';
        if (!is_file($migrationPath)) {
            return false;
        }

        try {
            $sql = file_get_contents($migrationPath);
            if (!is_string($sql) || trim($sql) === '') {
                return false;
            }

            Database::pdo()->exec($sql);
            self::$hasItemEditSchema = null;
        } catch (\Throwable) {
            return false;
        }

        return self::hasItemEditSchema();
    }

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
        array $items,
        ?string $webCustomerId = null
    ): array {
        $shareLinkId = trim($shareLinkId);
        $guestNameAr = trim($guestNameAr);
        $guestPhone = trim($guestPhone);
        $notesAr = $notesAr !== null ? trim($notesAr) : null;

        if ($guestNameAr === '' || $guestPhone === '' || $items === []) {
            return ['ok' => false, 'message' => 'بيانات الطلب غير مكتملة.'];
        }

        $shareLinkId = trim($shareLinkId);

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
                    web_customer_id,
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
                    :web_customer_id,
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
                'share_link_id' => $shareLinkId !== '' ? $shareLinkId : null,
                'web_customer_id' => $webCustomerId !== null && trim($webCustomerId) !== '' ? trim($webCustomerId) : null,
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
                    o.web_customer_id::text AS web_customer_id,
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

        $webCustomerId = trim((string) ($filters['web_customer_id'] ?? ''));
        if ($webCustomerId !== '') {
            $sql .= ' AND o.web_customer_id::text = :web_customer_id';
            $params['web_customer_id'] = $webCustomerId;
        }

        $origin = trim((string) ($filters['origin'] ?? ''));
        if ($origin === 'guest') {
            $sql .= ' AND o.web_customer_id IS NULL';
        } elseif ($origin === 'registered') {
            $sql .= ' AND o.web_customer_id IS NOT NULL';
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

    /** @param array{status?: string, limit?: int} $filters */
    public static function listForCustomer(string $customerId, string $phone, array $filters = []): array
    {
        $customerId = trim($customerId);
        $phone = trim($phone);
        if ($customerId === '' && $phone === '') {
            return [];
        }

        $status = trim((string) ($filters['status'] ?? ''));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));

        $sql = 'SELECT
                    o.id::text AS id,
                    o.order_number,
                    o.status::text AS status,
                    o.total_sp,
                    o.total_usd,
                    o.created_at,
                    o.updated_at,
                    o.notes_ar,
                    sl.name_ar AS share_link_name,
                    COALESCE(items.items_count, 0) AS items_count
                FROM orders o
                LEFT JOIN share_links sl ON sl.id = o.share_link_id
                LEFT JOIN (
                    SELECT order_id, COUNT(*)::int AS items_count
                    FROM order_items
                    GROUP BY order_id
                ) items ON items.order_id = o.id
                WHERE (
                    (:customer_id <> \'\' AND o.web_customer_id::text = :customer_id)
                    OR (
                        :phone <> \'\'
                        AND o.guest_phone = :phone
                    )
                )';
        $params = [
            'customer_id' => $customerId,
            'phone' => $phone,
        ];

        if ($status !== '' && in_array($status, self::ALLOWED_STATUSES, true)) {
            $sql .= ' AND o.status = :status';
            $params['status'] = $status;
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

    /** @return array<string, mixed>|null */
    public static function getOrderByQuoteToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id::text FROM orders WHERE quote_access_token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $orderId = (string) $stmt->fetchColumn();
        if ($orderId === '') {
            return null;
        }

        return self::getOrderDetails($orderId);
    }

    public static function getOrderForCustomer(string $orderId, string $customerId, string $phone): ?array
    {
        $order = self::getOrderDetails($orderId);
        if ($order === null) {
            return null;
        }

        $customerId = trim($customerId);
        $phone = trim($phone);
        $orderCustomerId = trim((string) ($order['web_customer_id'] ?? ''));
        $guestPhone = trim((string) ($order['guest_phone'] ?? ''));

        $allowed = ($customerId !== '' && $orderCustomerId === $customerId)
            || ($phone !== '' && $guestPhone === $phone);
        if (!$allowed) {
            return null;
        }

        return $order;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'قيد المراجعة',
            'confirmed' => 'مؤكد',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغى',
            default => $status,
        };
    }

    /** @param array<string, mixed> $row */
    public static function isRegisteredCustomerOrder(array $row): bool
    {
        return trim((string) ($row['web_customer_id'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $row */
    public static function orderOriginLabel(array $row): string
    {
        return self::isRegisteredCustomerOrder($row) ? 'عميل مسجّل' : 'زائر';
    }

    /** @param array{status?: string, limit?: int} $filters */
    public static function listForWebCustomer(string $webCustomerId, array $filters = []): array
    {
        $webCustomerId = trim($webCustomerId);
        if ($webCustomerId === '') {
            return [];
        }

        $filters['web_customer_id'] = $webCustomerId;

        return self::list($filters);
    }

    public static function countForWebCustomer(string $webCustomerId): int
    {
        $webCustomerId = trim($webCustomerId);
        if ($webCustomerId === '') {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)::int FROM orders WHERE web_customer_id::text = :id'
        );
        $stmt->execute(['id' => $webCustomerId]);

        return (int) $stmt->fetchColumn();
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
                o.web_customer_id::text AS web_customer_id,
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

        $statusSelect = self::hasItemEditSchema() ? 'oi.status::text AS item_status,' : '\'active\'::text AS item_status,';

        $itemsStmt = Database::pdo()->prepare(
            'SELECT
                oi.id::text AS id,
                oi.material_guid::text AS material_guid,
                oi.material_code,
                oi.material_name_ar,
                oi.quantity,
                oi.pcs_per_box,
                oi.sale_price_sp,
                oi.sale_price_usd,
                oi.original_sale_price_sp,
                oi.original_sale_price_usd,
                oi.special_offer_id::text AS special_offer_id,
                oi.image_url,
                oi.sort_order,
                ' . $statusSelect . '
                so.badge_text_ar AS offer_badge,
                so.title_ar AS offer_title_ar
             FROM order_items oi
             LEFT JOIN special_offers so ON so.id = oi.special_offer_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.sort_order ASC, oi.material_name_ar ASC'
        );
        $itemsStmt->execute(['order_id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPackages = 0.0;
        $totalPieces = 0.0;
        $activeCount = 0;
        foreach ($items as &$item) {
            $itemStatus = (string) ($item['item_status'] ?? 'active');
            $isCancelled = $itemStatus === 'cancelled';
            $quantity = $isCancelled ? 0.0 : max(0.0, (float) ($item['quantity'] ?? 0));
            $packaging = max(1, (int) ($item['pcs_per_box'] ?? 1));
            $packagePriceUsd = max(0.0, (float) ($item['sale_price_usd'] ?? 0));
            $packagePriceSp = max(0.0, (float) ($item['sale_price_sp'] ?? 0));

            $item['status'] = $itemStatus;
            $item['is_cancelled'] = $isCancelled;
            $item['packages_count'] = $quantity;
            $item['packaging'] = $packaging;
            $item['unit_sale_price_usd'] = $packaging > 0 ? $packagePriceUsd / $packaging : 0.0;
            $item['unit_sale_price_sp'] = $packaging > 0 ? $packagePriceSp / $packaging : 0.0;
            $item['line_total_usd'] = $quantity * $packagePriceUsd;
            $item['line_total_sp'] = $quantity * $packagePriceSp;
            $item['pieces_count'] = $quantity * $packaging;
            $item['has_offer'] = !empty($item['special_offer_id'])
                || (
                    (float) ($item['original_sale_price_sp'] ?? 0) > 0
                    && (float) ($item['original_sale_price_sp'] ?? 0) > $packagePriceSp + 0.009
                );
            $item['primary_unit'] = 'زوج';
            $item['package_unit'] = 'طرد';

            if (!$isCancelled) {
                $activeCount++;
                $totalPackages += $quantity;
                $totalPieces += $quantity * $packaging;
            }
        }
        unset($item);

        $order['summary'] = [
            'items_count' => $activeCount,
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

        $changes = self::listItemChanges($orderId, true);
        foreach ($changes as $change) {
            $timeline[] = [
                'label' => (string) ($change['label_ar'] ?? ''),
                'at' => (string) ($change['created_at'] ?? ''),
                'detail' => (string) ($change['reason_ar'] ?? ''),
                'type' => 'staff_edit',
            ];
        }
        usort($timeline, static function (array $a, array $b): int {
            return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        });

        $order['item_changes'] = $changes;
        $order['can_staff_edit'] = self::canStaffEditOrder($order);
        $order['can_customer_cancel'] = self::canCustomerCancelOrder($order);
        $order['customer_cancel_block_reason'] = self::customerCancelBlockReason($order);
        $order['items'] = $items;
        $order['timeline'] = $timeline;
        return $order;
    }

    /** @param array<string, mixed> $order */
    public static function canCustomerCancelOrder(array $order): bool
    {
        return self::customerCancelBlockReason($order) === '';
    }

    /** @param array<string, mixed> $order */
    public static function customerCancelBlockReason(array $order): string
    {
        $status = (string) ($order['status'] ?? '');
        if ($status === 'cancelled') {
            return 'هذا الطلب ملغى مسبقاً.';
        }
        if ($status !== 'pending') {
            return 'لا يمكن الإلغاء بعد أن راجع فريقنا الطلب أو غيّرت حالته.';
        }

        $syncStatus = (string) ($order['amine_sync_status'] ?? 'none');
        if ($syncStatus !== 'none') {
            return 'لا يمكن الإلغاء لأن الطلب قيد المزامنة مع النظام المحاسبي.';
        }
        if (trim((string) ($order['amine_synced_at'] ?? '')) !== '') {
            return 'لا يمكن الإلغاء بعد مزامنة الطلب.';
        }

        if (self::hasStaffItemChanges((string) ($order['id'] ?? ''))) {
            return 'لا يمكن الإلغاء بعد أن عدّل فريقنا الطلب.';
        }

        return '';
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function cancelOrderByCustomer(
        string $orderId,
        ?string $customerId = null,
        ?string $phone = null,
        ?string $quoteToken = null,
        string $reasonAr = ''
    ): array {
        $order = self::resolveCustomerOrderAccess($orderId, $customerId, $phone, $quoteToken);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود أو لا يخص حسابك.'];
        }

        $blockReason = self::customerCancelBlockReason($order);
        if ($blockReason !== '') {
            return ['ok' => false, 'message' => $blockReason];
        }

        $reasonAr = trim($reasonAr);
        if ($reasonAr === '') {
            $reasonAr = 'إلغاء من العميل';
        }

        $orderId = (string) ($order['id'] ?? '');
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            if (self::hasItemEditSchema()) {
                foreach ($order['items'] as $item) {
                    if (!empty($item['is_cancelled'])) {
                        continue;
                    }
                    $itemId = (string) ($item['id'] ?? '');
                    if ($itemId === '') {
                        continue;
                    }
                    $pdo->prepare(
                        'UPDATE order_items SET status = \'cancelled\' WHERE id = :id AND order_id = :order_id'
                    )->execute(['id' => $itemId, 'order_id' => $orderId]);
                    self::logItemChange(
                        $pdo,
                        $orderId,
                        $itemId,
                        'cancel',
                        (string) ($item['material_name_ar'] ?? ''),
                        'cancelled',
                        $reasonAr,
                        null
                    );
                }
                self::recalculateOrderTotals($pdo, $orderId);
            }
            $pdo->prepare(
                'UPDATE orders SET status = \'cancelled\', updated_at = NOW() WHERE id = :id'
            )->execute(['id' => $orderId]);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر إلغاء الطلب. حاول مجدداً أو تواصل معنا.'];
        }

        return ['ok' => true, 'message' => 'تم إلغاء الطلب بالكامل.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function cancelOrderItemByCustomer(
        string $orderId,
        string $itemId,
        ?string $customerId = null,
        ?string $phone = null,
        ?string $quoteToken = null,
        string $reasonAr = ''
    ): array {
        if (!self::hasItemEditSchema()) {
            return ['ok' => false, 'message' => 'إلغاء الأصناف غير متاح حالياً. يمكنك إلغاء الطلب بالكامل إن كان مسموحاً.'];
        }

        $order = self::resolveCustomerOrderAccess($orderId, $customerId, $phone, $quoteToken);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود أو لا يخص حسابك.'];
        }

        $blockReason = self::customerCancelBlockReason($order);
        if ($blockReason !== '') {
            return ['ok' => false, 'message' => $blockReason];
        }

        $item = self::findOrderItem($order, $itemId);
        if ($item === null) {
            return ['ok' => false, 'message' => 'الصنف غير موجود.'];
        }
        if (!empty($item['is_cancelled'])) {
            return ['ok' => false, 'message' => 'الصنف ملغى مسبقاً.'];
        }

        $activeCount = 0;
        foreach ($order['items'] as $row) {
            if (empty($row['is_cancelled'])) {
                $activeCount++;
            }
        }
        if ($activeCount <= 1) {
            return ['ok' => false, 'message' => 'لا يمكن إلغاء آخر صنف. استخدم «إلغاء الطلب بالكامل» بدلاً من ذلك.'];
        }

        $reasonAr = trim($reasonAr);
        if ($reasonAr === '') {
            $reasonAr = 'إلغاء صنف من العميل';
        }

        $orderId = (string) ($order['id'] ?? '');
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE order_items SET status = \'cancelled\' WHERE id = :id AND order_id = :order_id'
            )->execute(['id' => $itemId, 'order_id' => $orderId]);
            self::logItemChange(
                $pdo,
                $orderId,
                $itemId,
                'cancel',
                (string) ($item['material_name_ar'] ?? ''),
                'cancelled',
                $reasonAr,
                null
            );
            self::recalculateOrderTotals($pdo, $orderId);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر إلغاء الصنف.'];
        }

        return ['ok' => true, 'message' => 'تم إلغاء الصنف من الطلب.'];
    }

    /** @return array<string, mixed>|null */
    private static function resolveCustomerOrderAccess(
        string $orderId,
        ?string $customerId,
        ?string $phone,
        ?string $quoteToken
    ): ?array {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $customerId = trim((string) $customerId);
        $phone = trim((string) $phone);
        if ($customerId !== '' || $phone !== '') {
            $order = self::getOrderForCustomer($orderId, $customerId, $phone);
            if ($order !== null) {
                return $order;
            }
        }

        $quoteToken = trim((string) $quoteToken);
        if ($quoteToken !== '') {
            $order = self::getOrderByQuoteToken($quoteToken);
            if ($order !== null && (string) ($order['id'] ?? '') === $orderId) {
                return $order;
            }
        }

        return null;
    }

    private static function hasStaffItemChanges(string $orderId): bool
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return false;
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT 1
                 FROM order_item_changes
                 WHERE order_id = :order_id AND changed_by_web_user_id IS NOT NULL
                 LIMIT 1'
            );
            $stmt->execute(['order_id' => $orderId]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $order */
    public static function canStaffEditOrder(array $order): bool
    {
        if (!self::hasItemEditSchema()) {
            return false;
        }

        $status = (string) ($order['status'] ?? '');

        return in_array($status, ['pending', 'confirmed'], true);
    }

    /** @param array<string, mixed> $order */
    public static function staffEditBlockReason(array $order): string
    {
        if (!self::hasItemEditSchema()) {
            return 'تعذر تفعيل تعديل الأصناف. شغّل ملف الترحيل docs/portal-migration-order-item-edits.sql على قاعدة PostgreSQL.';
        }

        $status = (string) ($order['status'] ?? '');
        if ($status === 'completed') {
            return 'لا يمكن تعديل الأصناف لأن الطلب مكتمل.';
        }
        if ($status === 'cancelled') {
            return 'لا يمكن تعديل الأصناف لأن الطلب ملغى.';
        }
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            return 'لا يمكن تعديل الأصناف في حالة الطلب الحالية.';
        }

        return '';
    }

    /**
     * سعر الطرد بالدولار المُرسل لمزامنة الأمين (دائماً USD).
     *
     * @param array<string, mixed> $item
     */
    public static function amineSyncPackagePriceUsd(array $item): float
    {
        $usd = (float) ($item['sale_price_usd'] ?? 0);
        if ($usd > 0) {
            return $usd;
        }

        return 0.0;
    }

    /**
     * @return array{material_guid: string, material_code: string, quantity: float, sale_price_usd: float}
     *
     * @param array<string, mixed> $item
     */
    public static function amineSyncLinePayload(array $item): array
    {
        return [
            'material_guid' => (string) ($item['material_guid'] ?? ''),
            'material_code' => (string) ($item['material_code'] ?? ''),
            'quantity' => (float) ($item['quantity'] ?? 0),
            'sale_price_usd' => self::amineSyncPackagePriceUsd($item),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function updateItemQuantity(
        string $orderId,
        string $itemId,
        float $quantity,
        string $reasonAr,
        ?string $staffUserId = null
    ): array {
        if (!self::hasItemEditSchema()) {
            return ['ok' => false, 'message' => 'شغّل ملف الترحيل docs/portal-migration-order-item-edits.sql على قاعدة البيانات.'];
        }

        $reasonAr = trim($reasonAr);
        if ($reasonAr === '') {
            return ['ok' => false, 'message' => 'يرجى ذكر سبب تعديل الكمية.'];
        }
        if ($quantity <= 0) {
            return ['ok' => false, 'message' => 'الكمية يجب أن تكون أكبر من صفر.'];
        }

        $order = self::getOrderDetails($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }
        if (!self::canStaffEditOrder($order)) {
            return ['ok' => false, 'message' => self::staffEditBlockReason($order) ?: 'لا يمكن تعديل هذا الطلب.'];
        }

        $item = self::findOrderItem($order, $itemId);
        if ($item === null) {
            return ['ok' => false, 'message' => 'الصنف غير موجود في الطلب.'];
        }
        if (!empty($item['is_cancelled'])) {
            return ['ok' => false, 'message' => 'هذا الصنف ملغى ولا يمكن تعديله.'];
        }

        $oldQty = (float) ($item['quantity'] ?? 0);
        if (abs($oldQty - $quantity) < 0.0001) {
            return ['ok' => false, 'message' => 'الكمية لم تتغير.'];
        }

        if ($quantity > $oldQty) {
            $materialGuid = (string) ($item['material_guid'] ?? '');
            $packaging = max(1, (int) ($item['pcs_per_box'] ?? 1));
            $warehouse = StockReservationService::fetchWarehousePrimary($materialGuid);
            if ($warehouse !== null) {
                $reserved = StockReservationService::reservedPrimaryFor($materialGuid);
                $currentHold = $oldQty * $packaging;
                $available = max(0.0, $warehouse - $reserved + $currentHold);
                $maxPackages = $packaging > 0 ? $available / $packaging : 0.0;
                if ($quantity > $maxPackages + 0.0001) {
                    return [
                        'ok' => false,
                        'message' => 'الكمية المطلوبة تتجاوز المتوفر في المخزون.',
                    ];
                }
            }
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE order_items SET quantity = :qty WHERE id = :id AND order_id = :order_id'
            )->execute([
                'qty' => $quantity,
                'id' => $itemId,
                'order_id' => $orderId,
            ]);
            self::logItemChange(
                $pdo,
                $orderId,
                $itemId,
                'quantity',
                (string) $oldQty,
                (string) $quantity,
                $reasonAr,
                $staffUserId
            );
            self::recalculateOrderTotals($pdo, $orderId);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر حفظ تعديل الكمية.'];
        }

        return ['ok' => true, 'message' => 'تم تحديث كمية الصنف.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function updateItemPrice(
        string $orderId,
        string $itemId,
        ?float $salePriceSp,
        ?float $salePriceUsd,
        string $reasonAr,
        ?string $staffUserId = null
    ): array {
        if (!self::hasItemEditSchema()) {
            return ['ok' => false, 'message' => 'شغّل ملف الترحيل docs/portal-migration-order-item-edits.sql على قاعدة البيانات.'];
        }

        $reasonAr = trim($reasonAr);
        if ($reasonAr === '') {
            return ['ok' => false, 'message' => 'يرجى ذكر سبب تعديل السعر.'];
        }

        $order = self::getOrderDetails($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }
        if (!self::canStaffEditOrder($order)) {
            return ['ok' => false, 'message' => self::staffEditBlockReason($order) ?: 'لا يمكن تعديل هذا الطلب.'];
        }

        $item = self::findOrderItem($order, $itemId);
        if ($item === null || !empty($item['is_cancelled'])) {
            return ['ok' => false, 'message' => 'الصنف غير قابل للتعديل.'];
        }

        $oldSp = (float) ($item['sale_price_sp'] ?? 0);
        $oldUsd = (float) ($item['sale_price_usd'] ?? 0);
        $newSp = $salePriceSp !== null ? max(0.0, $salePriceSp) : $oldSp;
        $newUsd = $salePriceUsd !== null ? max(0.0, $salePriceUsd) : $oldUsd;

        if (abs($oldSp - $newSp) < 0.009 && abs($oldUsd - $newUsd) < 0.0001) {
            return ['ok' => false, 'message' => 'السعر لم يتغير.'];
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE order_items
                 SET sale_price_sp = :sp, sale_price_usd = :usd
                 WHERE id = :id AND order_id = :order_id'
            )->execute([
                'sp' => $newSp,
                'usd' => $newUsd,
                'id' => $itemId,
                'order_id' => $orderId,
            ]);
            if (abs($oldSp - $newSp) >= 0.009) {
                self::logItemChange($pdo, $orderId, $itemId, 'price_sp', (string) $oldSp, (string) $newSp, $reasonAr, $staffUserId);
            }
            if (abs($oldUsd - $newUsd) >= 0.0001) {
                self::logItemChange($pdo, $orderId, $itemId, 'price_usd', (string) $oldUsd, (string) $newUsd, $reasonAr, $staffUserId);
            }
            self::recalculateOrderTotals($pdo, $orderId);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر حفظ تعديل السعر.'];
        }

        return ['ok' => true, 'message' => 'تم تحديث سعر الصنف.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function cancelOrderItem(
        string $orderId,
        string $itemId,
        string $reasonAr,
        ?string $staffUserId = null
    ): array {
        if (!self::hasItemEditSchema()) {
            return ['ok' => false, 'message' => 'شغّل ملف الترحيل docs/portal-migration-order-item-edits.sql على قاعدة البيانات.'];
        }

        $reasonAr = trim($reasonAr);
        if ($reasonAr === '') {
            return ['ok' => false, 'message' => 'يرجى ذكر سبب إلغاء الصنف.'];
        }

        $order = self::getOrderDetails($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }
        if (!self::canStaffEditOrder($order)) {
            return ['ok' => false, 'message' => self::staffEditBlockReason($order) ?: 'لا يمكن تعديل هذا الطلب.'];
        }

        $item = self::findOrderItem($order, $itemId);
        if ($item === null) {
            return ['ok' => false, 'message' => 'الصنف غير موجود.'];
        }
        if (!empty($item['is_cancelled'])) {
            return ['ok' => false, 'message' => 'الصنف ملغى مسبقاً.'];
        }

        $activeCount = 0;
        foreach ($order['items'] as $row) {
            if (empty($row['is_cancelled'])) {
                $activeCount++;
            }
        }
        if ($activeCount <= 1) {
            return ['ok' => false, 'message' => 'لا يمكن إلغاء آخر صنف في الطلب.'];
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE order_items SET status = \'cancelled\' WHERE id = :id AND order_id = :order_id'
            )->execute(['id' => $itemId, 'order_id' => $orderId]);
            self::logItemChange(
                $pdo,
                $orderId,
                $itemId,
                'cancel',
                (string) ($item['material_name_ar'] ?? ''),
                'cancelled',
                $reasonAr,
                $staffUserId
            );
            self::recalculateOrderTotals($pdo, $orderId);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر إلغاء الصنف.'];
        }

        return ['ok' => true, 'message' => 'تم إلغاء الصنف من الطلب.'];
    }

    /** @return list<array<string, mixed>> */
    public static function listItemChanges(string $orderId, bool $customerVisibleOnly = false): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return [];
        }

        try {
            $sql = 'SELECT
                        c.id::text AS id,
                        c.order_item_id::text AS order_item_id,
                        c.change_type::text AS change_type,
                        c.old_value,
                        c.new_value,
                        c.reason_ar,
                        c.created_at,
                        oi.material_name_ar
                    FROM order_item_changes c
                    INNER JOIN order_items oi ON oi.id = c.order_item_id
                    WHERE c.order_id = :order_id';
            if ($customerVisibleOnly) {
                $sql .= ' AND c.visible_to_customer = TRUE';
            }
            $sql .= ' ORDER BY c.created_at ASC';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute(['order_id' => $orderId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(static function (array $row): array {
            $row['label_ar'] = self::changeLabel($row);

            return $row;
        }, $rows);
    }

    /** @param array<string, mixed> $change */
    private static function changeLabel(array $change): string
    {
        $name = trim((string) ($change['material_name_ar'] ?? 'الصنف'));
        $type = (string) ($change['change_type'] ?? '');
        $old = (string) ($change['old_value'] ?? '');
        $new = (string) ($change['new_value'] ?? '');

        return match ($type) {
            'quantity' => 'تعديل كمية «' . $name . '»: ' . $old . ' ← ' . $new . ' طرد',
            'price_sp' => 'تعديل سعر «' . $name . '»: ' . number_format((float) $old, 0, '.', ',') . ' ← ' . number_format((float) $new, 0, '.', ',') . ' ل.س',
            'price_usd' => 'تعديل سعر «' . $name . '»: $' . number_format((float) $old, 2, '.', ',') . ' ← $' . number_format((float) $new, 2, '.', ','),
            'cancel' => 'إلغاء الصنف «' . $name . '»',
            default => 'تحديث على «' . $name . '»',
        };
    }

    /** @param array<string, mixed> $order @return array<string, mixed>|null */
    private static function findOrderItem(array $order, string $itemId): ?array
    {
        $itemId = trim($itemId);
        foreach ($order['items'] as $item) {
            if ((string) ($item['id'] ?? '') === $itemId) {
                return $item;
            }
        }

        return null;
    }

    private static function logItemChange(
        PDO $pdo,
        string $orderId,
        string $itemId,
        string $changeType,
        ?string $oldValue,
        ?string $newValue,
        string $reasonAr,
        ?string $staffUserId
    ): void {
        $pdo->prepare(
            'INSERT INTO order_item_changes (
                order_id, order_item_id, change_type, old_value, new_value, reason_ar, changed_by_web_user_id
             ) VALUES (
                :order_id, :item_id, :change_type, :old_value, :new_value, :reason, :user_id
             )'
        )->execute([
            'order_id' => $orderId,
            'item_id' => $itemId,
            'change_type' => $changeType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $reasonAr,
            'user_id' => $staffUserId !== null && $staffUserId !== '' ? $staffUserId : null,
        ]);

        $pdo->prepare('UPDATE orders SET updated_at = NOW() WHERE id = :id')->execute(['id' => $orderId]);
    }

    private static function recalculateOrderTotals(PDO $pdo, string $orderId): void
    {
        if (self::hasItemEditSchema()) {
            $stmt = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(CASE WHEN status = \'active\' THEN quantity * sale_price_sp ELSE 0 END), 0)::float8 AS total_sp,
                    COALESCE(SUM(CASE WHEN status = \'active\' THEN quantity * sale_price_usd ELSE 0 END), 0)::float8 AS total_usd
                 FROM order_items
                 WHERE order_id = :order_id'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(quantity * sale_price_sp), 0)::float8 AS total_sp,
                    COALESCE(SUM(quantity * sale_price_usd), 0)::float8 AS total_usd
                 FROM order_items
                 WHERE order_id = :order_id'
            );
        }
        $stmt->execute(['order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sp' => 0, 'total_usd' => 0];

        $pdo->prepare(
            'UPDATE orders SET total_sp = :sp, total_usd = :usd, updated_at = NOW() WHERE id = :id'
        )->execute([
            'sp' => (float) ($row['total_sp'] ?? 0),
            'usd' => (float) ($row['total_usd'] ?? 0),
            'id' => $orderId,
        ]);
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
