<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class VisitorLogService
{
    private static ?bool $hasSchema = null;

    private static ?bool $ordersHaveVisitorSession = null;

  /** @var array<string, string> */
    private const ACTION_LABELS = [
        'page_view' => 'زيارة صفحة',
        'product_view' => 'عرض صنف',
        'product_quick_view' => 'معاينة سريعة',
        'add_to_cart' => 'إضافة للسلة',
        'remove_from_cart' => 'إزالة من السلة',
        'cart_view' => 'عرض السلة',
        'store_search' => 'بحث في المتجر',
        'store_filter' => 'تصفية المتجر',
        'order_start' => 'بدء طلب',
        'order_placed' => 'إرسال طلب',
        'login' => 'تسجيل دخول',
    ];

    public static function hasSchema(): bool
    {
        if (self::$hasSchema !== null) {
            return self::$hasSchema;
        }

        try {
            self::$hasSchema = (bool) Database::pdo()->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'visitor_logs'
                 LIMIT 1"
            )->fetchColumn();
        } catch (\Throwable) {
            self::$hasSchema = false;
        }

        return self::$hasSchema;
    }

    public static function actionLabel(string $action): string
    {
        $action = trim($action);

        return self::ACTION_LABELS[$action] ?? $action;
    }

    /**
     * @param array<string, mixed>|null $customer
     * @param array<string, mixed>|null $meta
     * @return array{ok: bool, message?: string}
     */
    public static function recordEvent(
        string $sessionId,
        string $action,
        string $path,
        string $title,
        string $referer,
        ?array $customer = null,
        ?array $meta = null
    ): array {
        if (!self::hasSchema()) {
            return ['ok' => false, 'message' => 'analytics_unavailable'];
        }

        $sessionId = trim($sessionId);
        $action = trim($action);
        if ($sessionId === '' || $action === '') {
            return ['ok' => false, 'message' => 'invalid'];
        }

        $path = trim($path);
        if (strlen($path) > 500) {
            $path = substr($path, 0, 500);
        }

        $payload = [
            'path' => $path,
            'title' => trim($title),
        ];
        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_scalar($value)) {
                    $payload[(string) $key] = $value;
                }
            }
        }

        if (!isset($payload['label_ar']) || trim((string) $payload['label_ar']) === '') {
            $payload['label_ar'] = self::buildLabelAr($action, $payload);
        }

        $ip = self::clientIp();
        $gps = self::parseGpsFromMeta($meta);
        $geo = [];

        if ($gps !== null) {
            $payload['location_source'] = 'gps';
            if ($gps['accuracy'] !== null) {
                $payload['gps_accuracy_m'] = round($gps['accuracy'], 1);
            }

            $geo = self::cachedReverseGeo($gps['latitude'], $gps['longitude']) ?? [];
            if ($geo === []) {
                $geo = self::fetchReverseGeo($gps['latitude'], $gps['longitude']);
            }
            $geo['latitude'] = $gps['latitude'];
            $geo['longitude'] = $gps['longitude'];
        } else {
            $payload['location_source'] = 'ip';

            $geo = self::cachedGeo($ip) ?? [];
            if ($geo === [] && $ip !== '' && !self::isPrivateIp($ip)) {
                $geo = self::fetchGeo($ip);
            }
        }

        $details = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($details === false) {
            $details = '{}';
        }

        $webCustomerId = $customer !== null ? trim((string) ($customer['id'] ?? '')) : '';

        $params = [
            'session_id' => substr($sessionId, 0, 120),
            'action' => substr($action, 0, 80),
            'ip' => $ip !== '' ? substr($ip, 0, 45) : null,
            'country' => self::nullableString($geo['country_ar'] ?? null),
            'city' => self::nullableString($geo['city_ar'] ?? null),
            'lat' => isset($geo['latitude']) ? (float) $geo['latitude'] : null,
            'lng' => isset($geo['longitude']) ? (float) $geo['longitude'] : null,
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'referer' => $referer !== '' ? substr($referer, 0, 1000) : null,
            'details' => substr($details, 0, 2000),
        ];

        $customerSql = 'NULL';
        if ($webCustomerId !== '') {
            $customerSql = ':customer_id';
            $params['customer_id'] = $webCustomerId;
        }

        try {
            Database::pdo()->prepare(
                'INSERT INTO visitor_logs (
                    session_id, action, visitor_ip, country_ar, city_ar,
                    latitude, longitude, user_agent, referer, details_ar, web_customer_id
                 ) VALUES (
                    :session_id, :action, :ip, :country, :city,
                    :lat, :lng, :ua, :referer, :details,
                    ' . $customerSql . '
                 )'
            )->execute($params);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'db_error'];
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed>|null $customer
     */
    public static function recordOrderPlaced(
        string $sessionId,
        string $orderId,
        string $orderNumber,
        ?string $webCustomerId,
        string $guestNameAr,
        string $guestPhone,
        ?array $customer = null
    ): void {
        if (!self::hasSchema() || trim($sessionId) === '' || trim($orderId) === '') {
            return;
        }

        $guestNameAr = trim($guestNameAr);
        $guestPhone = trim($guestPhone);
        $label = $guestNameAr !== ''
            ? ('طلب جديد: ' . $guestNameAr . ($orderNumber !== '' ? ' (' . $orderNumber . ')' : ''))
            : ('طلب جديد' . ($orderNumber !== '' ? ': ' . $orderNumber : ''));

        self::recordEvent(
            $sessionId,
            'order_placed',
            '/order',
            'إرسال طلب',
            '',
            $customer,
            [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'guest_name' => $guestNameAr,
                'guest_phone' => $guestPhone,
                'label_ar' => $label,
            ]
        );
    }

    public static function mapExternalUrl(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }
        if (!self::isValidCoordinate($latitude, $longitude)) {
            return null;
        }

        return 'https://www.google.com/maps?q='
            . rawurlencode(number_format($latitude, 6, '.', '') . ',' . number_format($longitude, 6, '.', ''));
    }

    public static function ordersHaveVisitorSessionColumn(): bool
    {
        if (self::$ordersHaveVisitorSession !== null) {
            return self::$ordersHaveVisitorSession;
        }

        try {
            self::$ordersHaveVisitorSession = (bool) Database::pdo()->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public'
                   AND table_name = 'orders'
                   AND column_name = 'visitor_session_id'
                 LIMIT 1"
            )->fetchColumn();
        } catch (\Throwable) {
            self::$ordersHaveVisitorSession = false;
        }

        return self::$ordersHaveVisitorSession;
    }

    /**
     * @param list<string> $sessionIds
     * @return array<string, array<string, mixed>>
     */
    public static function resolveIdentitiesForSessions(array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $sessionIds
        ))));
        if ($sessionIds === [] || !self::hasSchema()) {
            return [];
        }

        $identities = [];

        try {
            $placeholders = implode(', ', array_fill(0, count($sessionIds), '?'));
            $stmt = Database::pdo()->prepare(
                "SELECT DISTINCT ON (vl.session_id)
                    vl.session_id,
                    vl.web_customer_id::text AS web_customer_id,
                    wc.name_ar,
                    wc.phone
                 FROM visitor_logs vl
                 INNER JOIN web_customers wc ON wc.id = vl.web_customer_id
                 WHERE vl.session_id IN ($placeholders)
                 ORDER BY vl.session_id, vl.created_at DESC"
            );
            $stmt->execute($sessionIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $sid = (string) ($row['session_id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                $identities[$sid] = self::identityFromParts(
                    'customer',
                    'registered',
                    (string) ($row['name_ar'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (string) ($row['web_customer_id'] ?? ''),
                    null,
                    null
                );
            }

            $stmt = Database::pdo()->prepare(
                "SELECT DISTINCT ON (session_id)
                    session_id,
                    details_ar::jsonb->>'guest_name' AS guest_name,
                    details_ar::jsonb->>'guest_phone' AS guest_phone,
                    details_ar::jsonb->>'order_id' AS order_id,
                    details_ar::jsonb->>'order_number' AS order_number,
                    web_customer_id::text AS web_customer_id
                 FROM visitor_logs
                 WHERE session_id IN ($placeholders)
                   AND action = 'order_placed'
                 ORDER BY session_id, created_at DESC"
            );
            $stmt->execute($sessionIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $sid = (string) ($row['session_id'] ?? '');
                if ($sid === '' || isset($identities[$sid])) {
                    continue;
                }
                $customerId = trim((string) ($row['web_customer_id'] ?? ''));
                $name = trim((string) ($row['guest_name'] ?? ''));
                $phone = trim((string) ($row['guest_phone'] ?? ''));
                if ($customerId !== '') {
                    continue;
                }
                if ($name === '') {
                    continue;
                }
                $identities[$sid] = self::identityFromParts(
                    'guest_order',
                    'order_event',
                    $name,
                    $phone,
                    null,
                    trim((string) ($row['order_id'] ?? '')),
                    trim((string) ($row['order_number'] ?? ''))
                );
            }

            if (self::ordersHaveVisitorSessionColumn()) {
                $stmt = Database::pdo()->prepare(
                    "SELECT DISTINCT ON (o.visitor_session_id)
                        o.visitor_session_id AS session_id,
                        o.web_customer_id::text AS web_customer_id,
                        COALESCE(NULLIF(TRIM(wc.name_ar), ''), NULLIF(TRIM(o.guest_name_ar), '')) AS name_ar,
                        COALESCE(NULLIF(TRIM(wc.phone), ''), NULLIF(TRIM(o.guest_phone), '')) AS phone,
                        o.id::text AS order_id,
                        o.order_number
                     FROM orders o
                     LEFT JOIN web_customers wc ON wc.id = o.web_customer_id
                     WHERE o.visitor_session_id IN ($placeholders)
                     ORDER BY o.visitor_session_id, o.created_at DESC"
                );
                $stmt->execute($sessionIds);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $sid = (string) ($row['session_id'] ?? '');
                    if ($sid === '' || isset($identities[$sid])) {
                        continue;
                    }
                    $customerId = trim((string) ($row['web_customer_id'] ?? ''));
                    $name = trim((string) ($row['name_ar'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $identities[$sid] = self::identityFromParts(
                        $customerId !== '' ? 'customer' : 'guest_order',
                        'last_order',
                        $name,
                        (string) ($row['phone'] ?? ''),
                        $customerId !== '' ? $customerId : null,
                        trim((string) ($row['order_id'] ?? '')),
                        trim((string) ($row['order_number'] ?? ''))
                    );
                }
            }
        } catch (\Throwable) {
            return $identities;
        }

        return $identities;
    }

    /** @return array<string, mixed>|null */
    public static function resolveIdentityForIp(string $ip): ?array
    {
        $ip = trim($ip);
        if ($ip === '' || !self::hasSchema()) {
            return null;
        }

        try {
            if (self::ordersHaveVisitorSessionColumn()) {
                $stmt = Database::pdo()->prepare(
                    "SELECT
                        o.web_customer_id::text AS web_customer_id,
                        COALESCE(NULLIF(TRIM(wc.name_ar), ''), NULLIF(TRIM(o.guest_name_ar), '')) AS name_ar,
                        COALESCE(NULLIF(TRIM(wc.phone), ''), NULLIF(TRIM(o.guest_phone), '')) AS phone,
                        o.id::text AS order_id,
                        o.order_number
                     FROM orders o
                     LEFT JOIN web_customers wc ON wc.id = o.web_customer_id
                     WHERE o.visitor_session_id IN (
                         SELECT DISTINCT session_id
                         FROM visitor_logs
                         WHERE visitor_ip = :ip AND session_id <> ''
                     )
                     ORDER BY o.created_at DESC
                     LIMIT 1"
                );
                $stmt->execute(['ip' => $ip]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $name = trim((string) ($row['name_ar'] ?? ''));
                    if ($name !== '') {
                        $customerId = trim((string) ($row['web_customer_id'] ?? ''));

                        return self::identityFromParts(
                            $customerId !== '' ? 'customer' : 'guest_order',
                            'last_order',
                            $name,
                            (string) ($row['phone'] ?? ''),
                            $customerId !== '' ? $customerId : null,
                            trim((string) ($row['order_id'] ?? '')),
                            trim((string) ($row['order_number'] ?? ''))
                        );
                    }
                }
            }

            $stmt = Database::pdo()->prepare(
                "SELECT DISTINCT ON (vl.session_id)
                    vl.session_id,
                    vl.web_customer_id::text AS web_customer_id,
                    wc.name_ar,
                    wc.phone
                 FROM visitor_logs vl
                 INNER JOIN web_customers wc ON wc.id = vl.web_customer_id
                 WHERE vl.visitor_ip = :ip
                 ORDER BY vl.session_id, vl.created_at DESC
                 LIMIT 1"
            );
            $stmt->execute(['ip' => $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && trim((string) ($row['name_ar'] ?? '')) !== '') {
                return self::identityFromParts(
                    'customer',
                    'registered',
                    (string) ($row['name_ar'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (string) ($row['web_customer_id'] ?? ''),
                    null,
                    null
                );
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $identities
     * @return array<string, mixed>
     */
    public static function applyIdentity(array $row, array $identities): array
    {
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        $identity = $sessionId !== '' && isset($identities[$sessionId])
            ? $identities[$sessionId]
            : null;

        if ($identity === null) {
            $customerId = trim((string) ($row['web_customer_id'] ?? ''));
            if ($customerId !== '') {
                $names = self::lookupCustomerNames([$customerId]);
                if (isset($names[$customerId])) {
                    $identity = self::identityFromParts(
                        'customer',
                        'registered',
                        (string) ($names[$customerId]['name_ar'] ?? ''),
                        (string) ($names[$customerId]['phone'] ?? ''),
                        $customerId,
                        null,
                        null
                    );
                }
            } else {
                $ip = trim((string) ($row['visitor_ip'] ?? ''));
                if ($ip !== '') {
                    $identity = self::resolveIdentityForIp($ip);
                }
            }
        }

        if ($identity === null) {
            $row['display_name'] = 'زائر';
            $row['identity_kind'] = 'guest';
            $row['identity_subtitle'] = '';
            $row['identity_source'] = null;

            return $row;
        }

        $row['display_name'] = (string) ($identity['display_name'] ?? 'زائر');
        $row['identity_kind'] = (string) ($identity['identity_kind'] ?? 'guest');
        $row['identity_subtitle'] = (string) ($identity['identity_subtitle'] ?? '');
        $row['identity_source'] = $identity['identity_source'] ?? null;
        $row['identity_phone'] = (string) ($identity['phone'] ?? '');
        if (!empty($identity['web_customer_id'])) {
            $row['web_customer_id'] = (string) $identity['web_customer_id'];
        }
        if (!empty($identity['order_id'])) {
            $row['last_order_id'] = (string) $identity['order_id'];
        }
        if (!empty($identity['order_number'])) {
            $row['last_order_number'] = (string) $identity['order_number'];
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function identityFromParts(
        string $kind,
        ?string $source,
        string $name,
        string $phone,
        ?string $webCustomerId,
        ?string $orderId,
        ?string $orderNumber
    ): array {
        $name = trim($name);
        $phone = trim($phone);
        $webCustomerId = $webCustomerId !== null && trim($webCustomerId) !== '' ? trim($webCustomerId) : null;

        $displayName = $name !== '' ? $name : ($kind === 'customer' ? 'عميل' : 'زائر');
        $subtitle = '';
        if ($kind === 'guest_order' && $source === 'last_order') {
            $subtitle = 'آخر طلب';
        } elseif ($kind === 'guest_order' && $orderNumber !== null && trim($orderNumber) !== '') {
            $subtitle = trim($orderNumber);
        }

        return [
            'display_name' => $displayName,
            'identity_kind' => $kind,
            'identity_subtitle' => $subtitle,
            'identity_source' => $source,
            'phone' => $phone,
            'web_customer_id' => $webCustomerId,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
        ];
    }

    /** @param array<string, mixed> $filters */
    private static function sessionMatchesSearch(array $row, string $search): bool
    {
        $search = mb_strtolower(trim($search));
        if ($search === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($row['display_name'] ?? ''),
            (string) ($row['identity_subtitle'] ?? ''),
            (string) ($row['identity_phone'] ?? ''),
            (string) ($row['session_id'] ?? ''),
            (string) ($row['visitor_ip'] ?? ''),
            (string) ($row['last_order_number'] ?? ''),
        ])));

        return str_contains($haystack, $search);
    }

    /** @param array<string, mixed> $payload */
    private static function buildLabelAr(string $action, array $payload): string
    {
        $productName = trim((string) ($payload['product_name'] ?? ''));
        $productCode = trim((string) ($payload['product_code'] ?? ''));
        $searchQ = trim((string) ($payload['search_q'] ?? ''));
        $path = trim((string) ($payload['path'] ?? ''));

        return match ($action) {
            'product_view', 'product_quick_view' => $productName !== ''
                ? (self::actionLabel($action) . ': ' . $productName . ($productCode !== '' ? ' (' . $productCode . ')' : ''))
                : self::actionLabel($action),
            'add_to_cart', 'remove_from_cart' => $productName !== ''
                ? (self::actionLabel($action) . ': ' . $productName)
                : self::actionLabel($action),
            'store_search' => $searchQ !== '' ? 'بحث: ' . $searchQ : self::actionLabel($action),
            'store_filter' => trim((string) ($payload['filter_summary'] ?? '')) !== ''
                ? 'تصفية: ' . (string) $payload['filter_summary']
                : self::actionLabel($action),
            'page_view' => $path !== '' ? 'زيارة: ' . $path : self::actionLabel($action),
            default => self::actionLabel($action),
        };
    }

    /** @return array<string, int> */
    public static function summaryForDays(int $days = 7): array
    {
        if (!self::hasSchema()) {
            return self::emptySummary();
        }

        $days = max(1, min(365, $days));
        $stmt = Database::pdo()->prepare(
            "SELECT
                COUNT(*)::int AS total_events,
                COUNT(*) FILTER (WHERE action = 'page_view')::int AS page_views,
                COUNT(*) FILTER (WHERE action IN ('product_view', 'product_quick_view'))::int AS product_views,
                COUNT(*) FILTER (WHERE action = 'add_to_cart')::int AS cart_adds,
                COUNT(DISTINCT session_id)::int AS unique_sessions,
                COUNT(DISTINCT visitor_ip) FILTER (
                    WHERE visitor_ip IS NOT NULL AND visitor_ip <> ''
                )::int AS unique_ips,
                COUNT(*) FILTER (WHERE web_customer_id IS NOT NULL)::int AS registered_hits
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval"
        );
        $stmt->execute(['days' => (string) $days]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_events' => (int) ($row['total_events'] ?? 0),
            'page_views' => (int) ($row['page_views'] ?? 0),
            'product_views' => (int) ($row['product_views'] ?? 0),
            'cart_adds' => (int) ($row['cart_adds'] ?? 0),
            'unique_sessions' => (int) ($row['unique_sessions'] ?? 0),
            'unique_ips' => (int) ($row['unique_ips'] ?? 0),
            'registered_hits' => (int) ($row['registered_hits'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private static function emptySummary(): array
    {
        return [
            'total_events' => 0,
            'page_views' => 0,
            'product_views' => 0,
            'cart_adds' => 0,
            'unique_sessions' => 0,
            'unique_ips' => 0,
            'registered_hits' => 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 100, ?string $action = null, ?int $days = null, array $filters = []): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $customerId = trim((string) ($filters['customer_id'] ?? ''));
        $sql = 'SELECT
                    id::text AS id,
                    session_id,
                    action,
                    visitor_ip,
                    country_ar,
                    city_ar,
                    latitude,
                    longitude,
                    user_agent,
                    referer,
                    details_ar,
                    web_customer_id::text AS web_customer_id,
                    created_at
                FROM visitor_logs
                WHERE 1 = 1';
        $params = [];
        if ($action !== null && trim($action) !== '') {
            $sql .= ' AND action = :action';
            $params['action'] = trim($action);
        }
        if ($days !== null && $days > 0) {
            $sql .= " AND created_at >= NOW() - (:days || ' days')::interval";
            $params['days'] = (string) max(1, min(365, $days));
        }
        if ($customerId !== '') {
            $sql .= ' AND web_customer_id::text = :customer_id';
            $params['customer_id'] = $customerId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'enrichRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $identities = self::resolveIdentitiesForSessions(array_column($rows, 'session_id'));

        return array_map(static function (array $row) use ($identities): array {
            return self::applyIdentity($row, $identities);
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    public static function topPages(int $days = 7, int $limit = 12): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(NULLIF(details_ar::jsonb->>'path', ''), '—') AS page_path,
                COALESCE(NULLIF(details_ar::jsonb->>'title', ''), NULLIF(details_ar::jsonb->>'path', ''), '—') AS page_title,
                COUNT(*)::int AS hits
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval
               AND action = 'page_view'
               AND details_ar LIKE '{%'
             GROUP BY page_path, page_title
             ORDER BY hits DESC, page_path ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':days', (string) $days);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function topProducts(int $days = 7, int $limit = 15): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(NULLIF(details_ar::jsonb->>'product_guid', ''), '—') AS product_guid,
                MAX(NULLIF(details_ar::jsonb->>'product_name', '')) AS product_name,
                MAX(NULLIF(details_ar::jsonb->>'product_code', '')) AS product_code,
                COUNT(*) FILTER (
                    WHERE action IN ('product_view', 'product_quick_view')
                )::int AS views,
                COUNT(*) FILTER (WHERE action = 'add_to_cart')::int AS cart_adds,
                COUNT(*)::int AS total_interest
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval
               AND action IN ('product_view', 'product_quick_view', 'add_to_cart')
               AND details_ar LIKE '{%'
               AND COALESCE(details_ar::jsonb->>'product_guid', '') <> ''
             GROUP BY product_guid
             ORDER BY total_interest DESC, views DESC, cart_adds DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':days', (string) $days);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function actionBreakdown(int $days = 7): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $stmt = Database::pdo()->prepare(
            "SELECT action, COUNT(*)::int AS hits
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval
             GROUP BY action
             ORDER BY hits DESC, action ASC"
        );
        $stmt->execute(['days' => (string) $days]);

        return array_map(static function (array $row): array {
            $action = (string) ($row['action'] ?? '');

            return [
                'action' => $action,
                'label_ar' => self::actionLabel($action),
                'hits' => (int) ($row['hits'] ?? 0),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string, mixed>> */
    public static function topReferrers(int $days = 7, int $limit = 10): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $limit = max(1, min(30, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(NULLIF(referer, ''), 'مباشر / داخلي') AS referer,
                COUNT(*)::int AS hits
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval
             GROUP BY referer
             ORDER BY hits DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':days', (string) $days);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array{customer_id?: string, q?: string} $filters @return list<array<string, mixed>> */
    public static function sessionSummaries(int $days = 7, int $limit = 25, array $filters = []): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $limit = max(1, min(100, $limit));
        $customerId = trim((string) ($filters['customer_id'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));

        $sql = "SELECT
                session_id,
                COUNT(*)::int AS events,
                COUNT(*) FILTER (WHERE action = 'page_view')::int AS page_views,
                COUNT(*) FILTER (
                    WHERE action IN ('product_view', 'product_quick_view')
                )::int AS product_views,
                COUNT(*) FILTER (WHERE action = 'add_to_cart')::int AS cart_adds,
                MAX(web_customer_id::text) AS web_customer_id,
                MAX(visitor_ip) AS visitor_ip,
                MAX(country_ar) AS country_ar,
                MAX(city_ar) AS city_ar,
                MAX(latitude) AS latitude,
                MAX(longitude) AS longitude,
                MIN(created_at) AS first_seen,
                MAX(created_at) AS last_seen
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval";
        $params = ['days' => (string) $days];

        if ($customerId !== '') {
            $sessionFilter = 'web_customer_id::text = :customer_id';
            if (self::ordersHaveVisitorSessionColumn()) {
                $sessionFilter .= " OR session_id IN (
                    SELECT DISTINCT visitor_session_id
                    FROM orders
                    WHERE web_customer_id::text = :customer_id
                      AND visitor_session_id IS NOT NULL
                      AND visitor_session_id <> ''
                )";
            }
            $sql .= " AND ($sessionFilter)";
            $params['customer_id'] = $customerId;
        }

        $sql .= ' GROUP BY session_id ORDER BY last_seen DESC LIMIT :limit';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $fetchLimit = $search !== '' ? min(500, $limit * 4) : $limit;
        $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $identities = self::resolveIdentitiesForSessions(array_column($rawRows, 'session_id'));
        $customerNames = self::lookupCustomerNames(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['web_customer_id'] ?? '')),
            $rawRows
        )));

        $rows = [];
        foreach ($rawRows as $row) {
            $row['events'] = (int) ($row['events'] ?? 0);
            $row['page_views'] = (int) ($row['page_views'] ?? 0);
            $row['product_views'] = (int) ($row['product_views'] ?? 0);
            $row['cart_adds'] = (int) ($row['cart_adds'] ?? 0);
            $row['first_seen_fmt'] = self::formatTimestamp($row['first_seen'] ?? null);
            $row['last_seen_fmt'] = self::formatTimestamp($row['last_seen'] ?? null);
            $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
            $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
            $row['map_url'] = self::mapExternalUrl($lat, $lng);

            $cid = trim((string) ($row['web_customer_id'] ?? ''));
            if ($cid !== '' && isset($customerNames[$cid])) {
                $identities[(string) $row['session_id']] = self::identityFromParts(
                    'customer',
                    'registered',
                    (string) ($customerNames[$cid]['name_ar'] ?? ''),
                    (string) ($customerNames[$cid]['phone'] ?? ''),
                    $cid,
                    null,
                    null
                );
            }

            $row = self::applyIdentity($row, $identities);
            if (!self::sessionMatchesSearch($row, $search)) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function sessionEvents(string $sessionId, int $limit = 80): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                session_id,
                action,
                visitor_ip,
                country_ar,
                city_ar,
                latitude,
                longitude,
                referer,
                details_ar,
                web_customer_id::text AS web_customer_id,
                created_at
             FROM visitor_logs
             WHERE session_id = :session_id
             ORDER BY created_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':session_id', substr($sessionId, 0, 120));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([self::class, 'enrichRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $identities = self::resolveIdentitiesForSessions([$sessionId]);

        return array_map(static function (array $row) use ($identities): array {
            $row = self::applyIdentity($row, $identities);
            $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
            $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
            $row['map_url'] = self::mapExternalUrl($lat, $lng);

            return $row;
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    public static function mapPoints(int $days = 30, int $limit = 200): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $limit = max(1, min(1000, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT
                country_ar AS country,
                city_ar AS city,
                latitude,
                longitude,
                COUNT(*)::int AS hits,
                BOOL_OR(COALESCE(details_ar::jsonb->>'location_source', 'ip') = 'gps') AS is_gps
             FROM visitor_logs
             WHERE latitude IS NOT NULL
               AND longitude IS NOT NULL
               AND created_at >= NOW() - (:days || ' days')::interval
             GROUP BY country_ar, city_ar, latitude, longitude
             ORDER BY hits DESC, MAX(created_at) DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':days', (string) $days);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function locationStats(int $days = 7, int $limit = 15): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $days = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(NULLIF(TRIM(country_ar), ''), 'غير معروف') AS country,
                COALESCE(NULLIF(TRIM(city_ar), ''), '—') AS city,
                COUNT(DISTINCT session_id)::int AS sessions,
                COUNT(DISTINCT visitor_ip) FILTER (
                    WHERE visitor_ip IS NOT NULL AND visitor_ip <> ''
                )::int AS ips,
                COUNT(*)::int AS hits
             FROM visitor_logs
             WHERE created_at >= NOW() - (:days || ' days')::interval
             GROUP BY 1, 2
             ORDER BY sessions DESC, hits DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':days', (string) $days);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{today: int, week: int, sessions: int} */
    public static function summary(): array
    {
        if (!self::hasSchema()) {
            return ['today' => 0, 'week' => 0, 'sessions' => 0];
        }

        $row = Database::pdo()->query(
            "SELECT
                COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE)::int AS today,
                COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days')::int AS week,
                COUNT(DISTINCT session_id) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days')::int AS sessions
             FROM visitor_logs
             WHERE action = 'page_view'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'today' => (int) ($row['today'] ?? 0),
            'week' => (int) ($row['week'] ?? 0),
            'sessions' => (int) ($row['sessions'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function enrichRow(array $row): array
    {
        $meta = self::parseDetails((string) ($row['details_ar'] ?? ''));
        $action = (string) ($row['action'] ?? '');
        $row['meta'] = $meta;
        $row['label_ar'] = trim((string) ($meta['label_ar'] ?? '')) !== ''
            ? (string) $meta['label_ar']
            : self::buildLabelAr($action, $meta);
        $row['page_path'] = (string) ($meta['path'] ?? '');
        $row['page_title'] = (string) ($meta['title'] ?? '');
        $row['product_name'] = (string) ($meta['product_name'] ?? '');
        $row['product_code'] = (string) ($meta['product_code'] ?? '');
        $row['product_guid'] = (string) ($meta['product_guid'] ?? '');
        $row['action_label_ar'] = self::actionLabel($action);
        $row['referer_short'] = self::shortReferer((string) ($row['referer'] ?? ''));
        $row['created_at_fmt'] = self::formatTimestamp($row['created_at'] ?? null);
        $row['location_source'] = (string) ($meta['location_source'] ?? 'ip');
        $row['location_source_label'] = $row['location_source'] === 'gps' ? 'GPS' : 'IP';
        $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
        $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
        $row['map_url'] = self::mapExternalUrl($lat, $lng);

        return $row;
    }

    /**
     * @param list<string> $customerIds
     * @return array<string, array{name_ar: string, phone: string}>
     */
    private static function lookupCustomerNames(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $customerIds
        ))));
        if ($customerIds === []) {
            return [];
        }

        try {
            $placeholders = implode(', ', array_fill(0, count($customerIds), '?'));
            $stmt = Database::pdo()->prepare(
                "SELECT id::text AS id, name_ar, phone
                 FROM web_customers
                 WHERE id::text IN ($placeholders)"
            );
            $stmt->execute($customerIds);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $map[$id] = [
                    'name_ar' => (string) ($row['name_ar'] ?? ''),
                    'phone' => (string) ($row['phone'] ?? ''),
                ];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed>|null $meta
     * @return array{latitude: float, longitude: float, accuracy: ?float}|null
     */
    private static function parseGpsFromMeta(?array $meta): ?array
    {
        if (!is_array($meta)) {
            return null;
        }

        if (!isset($meta['gps_latitude'], $meta['gps_longitude'])) {
            return null;
        }

        $latitude = (float) $meta['gps_latitude'];
        $longitude = (float) $meta['gps_longitude'];
        if (!self::isValidCoordinate($latitude, $longitude)) {
            return null;
        }

        $accuracy = isset($meta['gps_accuracy']) && is_numeric($meta['gps_accuracy'])
            ? (float) $meta['gps_accuracy']
            : null;

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
        ];
    }

    private static function isValidCoordinate(float $latitude, float $longitude): bool
    {
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return false;
        }

        return abs($latitude) > 0.000001 || abs($longitude) > 0.000001;
    }

    /** @return array{country_ar?: string, city_ar?: string} */
    private static function fetchReverseGeo(float $latitude, float $longitude): array
    {
        $query = http_build_query([
            'format' => 'json',
            'lat' => number_format($latitude, 6, '.', ''),
            'lon' => number_format($longitude, 6, '.', ''),
            'accept-language' => 'ar',
            'zoom' => '10',
        ]);
        $url = 'https://nominatim.openstreetmap.org/reverse?' . $query;
        $context = stream_context_create([
            'http' => [
                'timeout' => 2.0,
                'header' => "User-Agent: JawishPortal/1.0 (visitor-analytics)\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];

        return [
            'country_ar' => trim((string) ($address['country'] ?? '')),
            'city_ar' => trim((string) (
                $address['city']
                ?? $address['town']
                ?? $address['village']
                ?? $address['municipality']
                ?? $address['state']
                ?? ''
            )),
        ];
    }

    /** @return array{country_ar?: string, city_ar?: string}|null */
    private static function cachedReverseGeo(float $latitude, float $longitude): ?array
    {
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT country_ar, city_ar
                 FROM visitor_logs
                 WHERE details_ar LIKE '{%'
                   AND COALESCE(details_ar::jsonb->>'location_source', '') = 'gps'
                   AND latitude BETWEEN :lat_min AND :lat_max
                   AND longitude BETWEEN :lng_min AND :lng_max
                   AND (
                        NULLIF(TRIM(country_ar), '') IS NOT NULL
                        OR NULLIF(TRIM(city_ar), '') IS NOT NULL
                   )
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([
                'lat_min' => $latitude - 0.05,
                'lat_max' => $latitude + 0.05,
                'lng_min' => $longitude - 0.05,
                'lng_max' => $longitude + 0.05,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            return [
                'country_ar' => (string) ($row['country_ar'] ?? ''),
                'city_ar' => (string) ($row['city_ar'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public static function parseDetails(string $details): array
    {
        $details = trim($details);
        if ($details === '' || !str_starts_with($details, '{')) {
            return $details !== '' ? ['label_ar' => $details] : [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function shortReferer(string $referer): string
    {
        $referer = trim($referer);
        if ($referer === '') {
            return '—';
        }

        $host = (string) (parse_url($referer, PHP_URL_HOST) ?: '');
        if ($host !== '') {
            return $host;
        }

        return strlen($referer) > 48 ? substr($referer, 0, 45) . '...' : $referer;
    }

    private static function formatTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? (string) $value : date('Y-m-d H:i', $timestamp);
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** @return array{country_ar?: string, city_ar?: string, latitude?: float, longitude?: float} */
    private static function fetchGeo(string $ip): array
    {
        if ($ip === '' || self::isPrivateIp($ip)) {
            return [];
        }

        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,city,lat,lon&lang=ar';
        $context = stream_context_create(['http' => ['timeout' => 1.5]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return [];
        }

        return [
            'country_ar' => trim((string) ($data['country'] ?? '')),
            'city_ar' => trim((string) ($data['city'] ?? '')),
            'latitude' => isset($data['lat']) ? (float) $data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float) $data['lon'] : null,
        ];
    }

    /** @return array{country_ar?: string, city_ar?: string, latitude?: float, longitude?: float}|null */
    private static function cachedGeo(string $ip): ?array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT country_ar, city_ar, latitude, longitude
                 FROM visitor_logs
                 WHERE visitor_ip = :ip AND latitude IS NOT NULL
                 ORDER BY created_at DESC
                 LIMIT 1'
            );
            $stmt->execute(['ip' => $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            return [
                'country_ar' => (string) ($row['country_ar'] ?? ''),
                'city_ar' => (string) ($row['city_ar'] ?? ''),
                'latitude' => (float) ($row['latitude'] ?? 0),
                'longitude' => (float) ($row['longitude'] ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function clientIp(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    private static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
