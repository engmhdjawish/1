<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\StaffDevice;
use PDO;

final class VisitorLogService
{
    private static ?bool $hasSchema = null;

    private static ?bool $ordersHaveVisitorSession = null;

    /** @var array<string, string> */
    private const PAGE_NAMES = [
        '' => 'الرئيسية',
        '/' => 'الرئيسية',
        'index.php' => 'الرئيسية',
        'store.php' => 'المتجر',
        'product.php' => 'صفحة صنف',
        'cart.php' => 'السلة',
        'store-cart.php' => 'السلة',
        'customer-login.php' => 'دخول العملاء',
        'staff-login.php' => 'دخول الموظفين',
        'login.php' => 'تسجيل الدخول',
        'logout.php' => 'تسجيل الخروج',
        'register.php' => 'إنشاء حساب',
        'my-orders.php' => 'طلباتي',
        'my-profile.php' => 'ملفي',
        'account.php' => 'الحساب',
        'about.php' => 'من نحن',
        'track-order.php' => 'تتبع الطلب',
        'price-checker.php' => 'فاحص الأسعار',
        'pc1.php' => 'فاحص الأسعار',
        'share.php' => 'رابط مشاركة',
        's.php' => 'رابط مشاركة',
        'order-confirmation.php' => 'تأكيد الطلب',
        'store-order-confirmation.php' => 'تأكيد الطلب',
        'notifications.php' => 'الإشعارات',
        'guide.php' => 'دليل الاستخدام',
        'install.php' => 'تثبيت التطبيق',
        'offline.html' => 'بدون اتصال',
        'store-easy.php' => 'التسوق السهل',
        'store-lab.php' => 'المختبر',
    ];

    /** @var array<string, string> */
    private const DASHBOARD_PAGE_NAMES = [
        'index.php' => 'لوحة التحكم',
        'visitor-analytics.php' => 'سجل الزوار',
        'orders.php' => 'الطلبات',
        'customers.php' => 'العملاء',
        'sessions.php' => 'الجلسات',
        'users.php' => 'المستخدمون',
        'notifications.php' => 'التنبيهات',
        'settings.php' => 'الإعدادات',
        'share-links.php' => 'روابط المشاركة',
        'material-images.php' => 'صور المواد',
        'special-offers.php' => 'العروض',
        'home-sections.php' => 'أقسام الرئيسية',
        'price-checker.php' => 'فاحص الأسعار',
        'profile.php' => 'الملف الشخصي',
        'about.php' => 'من نحن',
        'accounting-statement.php' => 'كشف الحساب',
        'accounting-customers.php' => 'عملاء المحاسبة',
        'accounting-documents.php' => 'المستندات',
    ];

    /** @var array<string, string> */
    private const QUERY_LABELS = [
        'q' => 'بحث',
        'keyword' => 'بحث',
        'search' => 'بحث',
        'materialtypes' => 'نوع المادة',
        'agecategories' => 'الفئة',
        'manufacturers' => 'الشركة',
        'sizeranges' => 'القياس',
        'countryoforigins' => 'المنشأ',
        'section' => 'قسم',
        'offer' => 'عرض',
        'isavailable' => 'التوفر',
    ];

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

        unset($payload['staff_user_id'], $payload['staff_name'], $payload['customer_account']);
        $actor = StaffDevice::current($sessionId);
        if ($actor !== null) {
            self::claimSessionForStaff($sessionId, $actor['id'], $actor['name']);
            $payload['staff_user_id'] = $actor['id'];
            $payload['staff_name'] = $actor['name'];
            if ($customer !== null) {
                $accountName = trim((string) ($customer['name_ar'] ?? ''));
                if ($accountName !== '') {
                    $payload['customer_account'] = $accountName;
                }
            }
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
                    wc.phone,
                    CASE
                        WHEN COALESCE(vl.details_ar, '') LIKE '%\"staff_user_id\"%'
                        THEN vl.details_ar::jsonb->>'staff_user_id'
                        ELSE NULL
                    END AS staff_user_id,
                    CASE
                        WHEN COALESCE(vl.details_ar, '') LIKE '%\"staff_name\"%'
                        THEN vl.details_ar::jsonb->>'staff_name'
                        ELSE NULL
                    END AS staff_name,
                    CASE
                        WHEN COALESCE(vl.details_ar, '') LIKE '%\"customer_account\"%'
                        THEN vl.details_ar::jsonb->>'customer_account'
                        ELSE NULL
                    END AS customer_account
                 FROM visitor_logs vl
                 LEFT JOIN web_customers wc ON wc.id = vl.web_customer_id
                 WHERE vl.session_id IN ($placeholders)
                 ORDER BY vl.session_id, vl.created_at DESC"
            );
            $stmt->execute($sessionIds);
            $closedSessions = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $sid = (string) ($row['session_id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                $staffId = trim((string) ($row['staff_user_id'] ?? ''));
                if ($staffId !== '') {
                    $accountName = trim((string) ($row['customer_account'] ?? ''));
                    if ($accountName === '') {
                        $accountName = trim((string) ($row['name_ar'] ?? ''));
                    }
                    $identities[$sid] = self::staffIdentity(
                        $staffId,
                        (string) ($row['staff_name'] ?? ''),
                        $accountName,
                        trim((string) ($row['web_customer_id'] ?? ''))
                    );
                    continue;
                }
                if (trim((string) ($row['web_customer_id'] ?? '')) === '' || trim((string) ($row['name_ar'] ?? '')) === '') {
                    $closedSessions[$sid] = true;
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
                if ($sid === '' || isset($identities[$sid]) || isset($closedSessions[$sid])) {
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
                    if ($sid === '' || isset($identities[$sid]) || isset($closedSessions[$sid])) {
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
        if (!empty($identity['staff_user_id'])) {
            $row['staff_user_id'] = (string) $identity['staff_user_id'];
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
            'staff_user_id' => null,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
        ];
    }

    public static function claimSessionForStaff(string $sessionId, string $staffId, string $staffName): void
    {
        if (!self::hasSchema()) {
            return;
        }

        $sessionId = substr(trim($sessionId), 0, 120);
        $staffId = trim($staffId);
        $staffName = trim($staffName) !== '' ? trim($staffName) : 'موظف';
        if ($sessionId === '' || $staffId === '') {
            return;
        }

        static $claimed = [];
        if (isset($claimed[$sessionId])) {
            return;
        }
        $claimed[$sessionId] = true;

        try {
            Database::pdo()->prepare(
                "UPDATE visitor_logs
                 SET details_ar = CASE
                     WHEN details_ar IS NULL OR btrim(details_ar) = '' THEN
                         jsonb_build_object('staff_user_id', :staff_id_empty, 'staff_name', :staff_name_empty)::text
                     WHEN details_ar ~ '^\\s*\\{' THEN
                         (details_ar::jsonb || jsonb_build_object('staff_user_id', :staff_id_json, 'staff_name', :staff_name_json))::text
                     ELSE details_ar
                 END
                 WHERE session_id = :session_id
                   AND created_at >= NOW() - INTERVAL '90 days'
                   AND COALESCE(details_ar, '') NOT LIKE '%\"staff_user_id\"%'
                   AND (details_ar IS NULL OR btrim(details_ar) = '' OR length(details_ar) < 1700)"
            )->execute([
                'staff_id_empty' => $staffId,
                'staff_name_empty' => $staffName,
                'staff_id_json' => $staffId,
                'staff_name_json' => $staffName,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable) {
            // Historical rows stay unchanged when details are not valid JSON.
        }
    }

    /** @return array<string, mixed> */
    private static function staffIdentity(
        string $staffId,
        string $staffName,
        string $customerAccount,
        string $webCustomerId
    ): array {
        $staffName = trim($staffName) !== '' ? trim($staffName) : 'موظف';
        $customerAccount = trim($customerAccount);
        $identity = self::identityFromParts(
            'staff',
            'staff_device',
            $staffName,
            '',
            $webCustomerId !== '' ? $webCustomerId : null,
            null,
            null
        );
        $identity['staff_user_id'] = trim($staffId);
        $identity['identity_subtitle'] = $customerAccount !== '' ? ('حساب ' . $customerAccount) : '';

        return $identity;
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

    /**
     * Turn a stored path such as `/store.php?q=%D8%B3...` into an Arabic page name.
     *
     * @return array{title: string, detail: string, label: string}
     */
    public static function describePath(string $path, string $pageTitle = ''): array
    {
        $path = trim($path);
        if ($path === '' || $path === '—') {
            return self::pathLabel('صفحة', '');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            $parsed = parse_url($path);
            if (is_array($parsed)) {
                $path = (string) ($parsed['path'] ?? '/');
                if (isset($parsed['query']) && $parsed['query'] !== '') {
                    $path .= '?' . $parsed['query'];
                }
            }
        }

        $query = '';
        $route = $path;
        $split = strpos($path, '?');
        if ($split !== false) {
            $route = substr($path, 0, $split);
            $query = substr($path, $split + 1);
        }
        $route = self::decodeUrlText($route);
        $routePath = (string) (parse_url($route, PHP_URL_PATH) ?: $route);
        $base = strtolower(basename($routePath));
        $isShortShare = (bool) preg_match('#^/s/[A-Za-z0-9_-]+/?$#', $routePath);
        if ($isShortShare) {
            $base = 'share.php';
        }
        $isDashboard = str_contains($routePath, '/dashboard/');
        $title = $isDashboard
            ? (self::DASHBOARD_PAGE_NAMES[$base] ?? 'لوحة التحكم')
            : (self::PAGE_NAMES[$base] ?? self::PAGE_NAMES[$routePath] ?? '');
        if ($title === '') {
            $stem = trim(str_replace(['-', '_'], ' ', (string) preg_replace('/\.php$/', '', $base)));
            $title = $stem !== '' ? $stem : 'صفحة';
        }

        $cleanedTitle = self::cleanPageTitle($pageTitle);
        // Prefer the real page/share title when the mapped name is generic.
        if ($cleanedTitle !== '' && ($base === 'product.php' || $base === 'share.php' || $title === 'صفحة')) {
            if ($base === 'share.php') {
                // Keep "رابط مشاركة" as the type; show the share's own name as detail.
                $detailFromTitle = $cleanedTitle;
                $detail = self::describeQuery($query);
                if ($detail === '') {
                    $detail = $detailFromTitle;
                } elseif ($detailFromTitle !== '' && !str_contains($detail, $detailFromTitle)) {
                    $detail = $detailFromTitle . ' · ' . $detail;
                }

                return self::pathLabel($title, $detail);
            }
            $title = $cleanedTitle;
        }

        $detail = self::describeQuery($query);
        if ($detail === '' && $cleanedTitle !== '' && $cleanedTitle !== $title && ($base === 'store.php' || $base === 'about.php')) {
            $detail = $cleanedTitle;
        }

        return self::pathLabel($title, $detail);
    }

    /** @return array{title: string, detail: string, label: string} */
    private static function pathLabel(string $title, string $detail): array
    {
        $title = trim($title) !== '' ? trim($title) : 'صفحة';
        $detail = trim($detail);
        $label = $detail !== '' ? ($title . ' — ' . $detail) : $title;

        return ['title' => $title, 'detail' => $detail, 'label' => $label];
    }

    private static function describeQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        $page = '';
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $rawKey = $eq === false ? $part : substr($part, 0, $eq);
            $rawValue = $eq === false ? '' : substr($part, $eq + 1);
            $key = strtolower((string) preg_replace('/\[\]$/', '', rawurldecode(str_replace('+', ' ', $rawKey))));
            $value = self::decodeUrlText(rawurldecode(str_replace('+', ' ', $rawValue)));
            if ($key === '' || self::isSkippedQueryKey($key)) {
                continue;
            }
            if ($key === 'page') {
                if (ctype_digit($value) && (int) $value > 1) {
                    $page = 'صفحة ' . $value;
                }
                continue;
            }
            if ($key === 'isavailable') {
                $value = in_array(strtolower($value), ['1', 'true', 'yes'], true) ? 'متوفر' : 'غير متوفر';
            }
            if ($value === '' || self::isOpaqueId($value)) {
                continue;
            }
            $grouped[$key][] = $value;
        }

        $parts = [];
        $order = ['q', 'keyword', 'search', 'materialtypes', 'agecategories', 'manufacturers', 'sizeranges', 'countryoforigins', 'section', 'offer'];
        $used = [];
        foreach ($order as $key) {
            if (!isset($grouped[$key])) {
                continue;
            }
            $used[$key] = true;
            $parts[] = self::formatQueryPart($key, $grouped[$key]);
        }
        foreach ($grouped as $key => $values) {
            if (isset($used[$key]) || self::isPriceQueryKey($key)) {
                continue;
            }
            $parts[] = self::formatQueryPart($key, $values);
        }
        $price = self::formatPriceQuery($grouped);
        if ($price !== '') {
            $parts[] = $price;
        }
        if ($page !== '') {
            $parts[] = $page;
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if (count($parts) > 4) {
            $parts = array_slice($parts, 0, 4);
        }

        return implode(' · ', $parts);
    }

    /** @param list<string> $values */
    private static function formatQueryPart(string $key, array $values): string
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $values
        ), static fn (string $value): bool => $value !== '')));
        if ($values === []) {
            return '';
        }
        $text = implode('، ', array_slice($values, 0, 3));
        $name = self::QUERY_LABELS[$key] ?? '';
        if ($name === '') {
            return $text;
        }
        if (in_array($key, ['q', 'keyword', 'search'], true)) {
            return $name . ': ' . $text;
        }

        return $name . ': ' . $text;
    }

    /** @param array<string, list<string>> $grouped */
    private static function formatPriceQuery(array $grouped): string
    {
        $ranges = [
            ['minunitsalepricesyp', 'maxunitsalepricesyp', 'بيع ل.س'],
            ['minunitsalepriceusd', 'maxunitsalepriceusd', 'بيع $'],
        ];
        foreach ($ranges as [$minKey, $maxKey, $label]) {
            $min = trim((string) ($grouped[$minKey][0] ?? ''));
            $max = trim((string) ($grouped[$maxKey][0] ?? ''));
            if ($min === '' && $max === '') {
                continue;
            }
            if ($min !== '' && $max !== '') {
                return $label . ': ' . $min . '–' . $max;
            }

            return $label . ': ' . ($min !== '' ? $min : $max);
        }

        return '';
    }

    private static function isSkippedQueryKey(string $key): bool
    {
        if (str_starts_with($key, 'utm_')) {
            return true;
        }

        return in_array($key, [
            'preview', 'guid', 'id', 'product', 'token', 't', 's', 'share',
            'return', 'redirect',
            'fbclid', 'gclid', '_ga', 'ref', 'open_cart', 'groupby', 'sort',
            'storeguids', 'groupguids',
        ], true);
    }

    private static function isPriceQueryKey(string $key): bool
    {
        return str_starts_with($key, 'min') || str_starts_with($key, 'max');
    }

    private static function isOpaqueId(string $value): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        return strlen($value) > 48 && preg_match('/\p{Arabic}/u', $value) !== 1;
    }

    private static function decodeUrlText(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        for ($i = 0; $i < 2; $i++) {
            if (preg_match('/%[0-9A-Fa-f]{2}/', $value) !== 1) {
                break;
            }
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return trim($value);
    }

    private static function cleanPageTitle(string $title): string
    {
        $title = self::decodeUrlText($title);
        $title = (string) preg_replace('/\s+—\s+[^—]+$/u', '', $title);
        $title = (string) preg_replace('/^(جَويش|جويش|جاويش|jawish)\s*[—–\-|:]\s*/iu', '', $title);
        $title = trim($title);
        $generic = ['المتجر', 'الرئيسية', 'السلة', 'جَويش', 'جويش', 'جاويش', 'jawish', 'store', 'home', 'لوحة التحكم'];
        if ($title === '' || in_array(mb_strtolower($title), $generic, true)) {
            return '';
        }

        return $title;
    }

    private static function humanizeVisitorText(string $text): string
    {
        $text = self::decodeUrlText($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('#(?:^|\s)(/|https?://)|\.php\?#i', $text) === 1) {
            $path = trim((string) preg_replace('/^(زيارة|تصفّح)\s*:\s*/u', '', $text));
            if (str_contains($path, '/') || str_contains($path, '.php')) {
                return self::describePath($path)['label'];
            }
        }

        return $text;
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
            'store_search' => $searchQ !== '' ? 'بحث: ' . self::decodeUrlText($searchQ) : self::actionLabel($action),
            'store_filter' => trim((string) ($payload['filter_summary'] ?? '')) !== ''
                ? 'تصفية: ' . self::decodeUrlText((string) $payload['filter_summary'])
                : self::actionLabel($action),
            'page_view' => $path !== ''
                ? self::describePath($path, trim((string) ($payload['title'] ?? '')))['label']
                : self::actionLabel($action),
            'order_placed' => trim((string) ($payload['order_number'] ?? '')) !== ''
                ? ('طلب: ' . (string) $payload['order_number'])
                : (trim((string) ($payload['guest_name'] ?? '')) !== ''
                    ? ('طلب: ' . (string) $payload['guest_name'])
                    : self::actionLabel($action)),
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
        $stmt->bindValue(':limit', min(50, $limit * 4), PDO::PARAM_INT);
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $described = self::describePath(
                (string) ($row['page_path'] ?? ''),
                (string) ($row['page_title'] ?? '')
            );
            $key = $described['label'] !== '' ? $described['label'] : 'صفحة';
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'page_path' => $key,
                    'page_title' => $described['title'],
                    'page_label' => $described['label'],
                    'page_title_ar' => $described['title'],
                    'page_detail_ar' => $described['detail'],
                    'hits' => 0,
                ];
            }
            $grouped[$key]['hits'] += (int) ($row['hits'] ?? 0);
        }

        $rows = array_values($grouped);
        usort($rows, static fn (array $a, array $b): int => ((int) $b['hits']) <=> ((int) $a['hits']));

        return array_slice($rows, 0, $limit);
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
                COUNT(*) FILTER (WHERE action = 'remove_from_cart')::int AS cart_removals,
                COUNT(*) FILTER (WHERE action = 'order_placed')::int AS orders,
                (
                    SELECT vl_latest.web_customer_id::text
                    FROM visitor_logs vl_latest
                    WHERE vl_latest.session_id = visitor_logs.session_id
                    ORDER BY vl_latest.created_at DESC
                    LIMIT 1
                ) AS web_customer_id,
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
            $row['cart_removals'] = (int) ($row['cart_removals'] ?? 0);
            $row['orders'] = (int) ($row['orders'] ?? 0);
            $row['first_seen_fmt'] = self::formatTimestamp($row['first_seen'] ?? null);
            $row['last_seen_fmt'] = self::formatTimestamp($row['last_seen'] ?? null);
            $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
            $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
            $row['map_url'] = self::mapExternalUrl($lat, $lng);

            $sid = (string) ($row['session_id'] ?? '');
            $existingKind = (string) ($identities[$sid]['identity_kind'] ?? '');
            $cid = trim((string) ($row['web_customer_id'] ?? ''));
            if ($existingKind !== 'staff' && $cid !== '' && isset($customerNames[$cid])) {
                $identities[$sid] = self::identityFromParts(
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
            $row['account_key'] = self::accountKeyForSessionPrivate($row);
            $row['last_seen_relative'] = self::formatRelativeTime($row['last_seen'] ?? null);
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

    /**
     * @param list<string> $sessionIds
     * @return list<array<string, mixed>>
     */
    public static function eventsForSessions(array $sessionIds, int $limit = 200, ?int $days = null): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $sessionIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $sessionIds
        ))));
        if ($sessionIds === []) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $placeholders = implode(', ', array_fill(0, count($sessionIds), '?'));
        $sql = "SELECT
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
             WHERE session_id IN ($placeholders)";
        $params = $sessionIds;

        if ($days !== null && $days > 0) {
            $sql .= " AND created_at >= NOW() - (? || ' days')::interval";
            $params[] = (string) max(1, min(365, $days));
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = array_map([self::class, 'enrichRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $identities = self::resolveIdentitiesForSessions($sessionIds);

        return array_map(static function (array $row) use ($identities): array {
            $row = self::applyIdentity($row, $identities);
            $row['created_at_relative'] = self::formatRelativeTime($row['created_at'] ?? null);
            $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
            $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
            $row['map_url'] = self::mapExternalUrl($lat, $lng);

            return $row;
        }, $rows);
    }

    /**
     * @param array<string, mixed> $group from groupSessionsByAccount
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public static function buildAccountProfile(array $group, array $events): array
    {
        $stats = [
            'session_count' => (int) ($group['session_count'] ?? count($group['sessions'] ?? [])),
            'events' => (int) ($group['events'] ?? 0),
            'page_views' => (int) ($group['page_views'] ?? 0),
            'product_views' => (int) ($group['product_views'] ?? 0),
            'cart_adds' => (int) ($group['cart_adds'] ?? 0),
            'cart_removals' => (int) ($group['cart_removals'] ?? 0),
            'orders' => (int) ($group['orders'] ?? 0),
            'first_seen_fmt' => (string) ($group['first_seen_fmt'] ?? '—'),
            'last_seen_fmt' => (string) ($group['last_seen_fmt'] ?? '—'),
            'last_seen_relative' => self::formatRelativeTime($group['last_seen'] ?? null),
        ];

        $funnel = self::buildFunnel($stats);
        $digest = self::buildSessionDigest(array_reverse($events));
        $timeline = self::buildAccountTimeline($events);
        $sessions = is_array($group['sessions'] ?? null) ? $group['sessions'] : [];
        $location = self::resolveProfileLocation($events, $sessions);

        foreach ($sessions as &$session) {
            $session['last_seen_relative'] = self::formatRelativeTime($session['last_seen'] ?? null);
        }
        unset($session);

        return [
            'account_key' => (string) ($group['account_key'] ?? ''),
            'display_name' => (string) ($group['display_name'] ?? 'زائر'),
            'identity_kind' => (string) ($group['identity_kind'] ?? 'guest'),
            'identity_subtitle' => (string) ($group['identity_subtitle'] ?? ''),
            'identity_phone' => (string) ($group['identity_phone'] ?? ''),
            'web_customer_id' => trim((string) ($group['web_customer_id'] ?? '')),
            'stats' => $stats,
            'funnel' => $funnel,
            'digest' => $digest,
            'timeline' => $timeline,
            'sessions' => $sessions,
            'location' => $location,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $sessions
     * @return array{location_label: string, location_source_label: string, map_url: ?string, visitor_ip: string}
     */
    public static function resolveProfileLocation(array $events, array $sessions): array
    {
        $result = [
            'location_label' => '—',
            'location_source_label' => '',
            'map_url' => null,
            'visitor_ip' => '',
        ];

        foreach ($events as $row) {
            $city = trim((string) ($row['city_ar'] ?? ''));
            $country = trim((string) ($row['country_ar'] ?? ''));
            if ($city !== '' || $country !== '') {
                $result['location_label'] = $city !== '' && $country !== ''
                    ? ($city . '، ' . $country)
                    : ($city !== '' ? $city : $country);
                $result['location_source_label'] = (string) ($row['location_source_label'] ?? '');
                $result['map_url'] = $row['map_url'] ?? null;
                $result['visitor_ip'] = trim((string) ($row['visitor_ip'] ?? ''));

                return $result;
            }
            if ($result['map_url'] === null && !empty($row['map_url'])) {
                $result['map_url'] = $row['map_url'];
            }
            if ($result['visitor_ip'] === '' && trim((string) ($row['visitor_ip'] ?? '')) !== '') {
                $result['visitor_ip'] = (string) $row['visitor_ip'];
            }
        }

        foreach ($sessions as $sess) {
            $city = trim((string) ($sess['city_ar'] ?? ''));
            $country = trim((string) ($sess['country_ar'] ?? ''));
            if ($city !== '' || $country !== '') {
                $result['location_label'] = $city !== '' && $country !== ''
                    ? ($city . '، ' . $country)
                    : ($city !== '' ? $city : $country);
                $result['map_url'] = $sess['map_url'] ?? $result['map_url'];
                if ($result['visitor_ip'] === '' && trim((string) ($sess['visitor_ip'] ?? '')) !== '') {
                    $result['visitor_ip'] = (string) $sess['visitor_ip'];
                }

                return $result;
            }
            if ($result['map_url'] === null && !empty($sess['map_url'])) {
                $result['map_url'] = $sess['map_url'];
            }
            if ($result['visitor_ip'] === '' && trim((string) ($sess['visitor_ip'] ?? '')) !== '') {
                $result['visitor_ip'] = (string) $sess['visitor_ip'];
            }
        }

        return $result;
    }

    /**
     * @param list<mixed> $items
     * @return array{items: list<mixed>, page: int, per_page: int, total: int, total_pages: int}
     */
    public static function paginateList(array $items, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param list<array{day: string, day_label: string, items: list<array<string, mixed>>}> $timeline
     * @return list<array{day: string, day_label: string, items: list<array<string, mixed>>}>
     */
    public static function filterTimelineByCategory(array $timeline, string $category): array
    {
        $category = trim($category);
        if ($category === '' || $category === 'all') {
            return $timeline;
        }

        $filtered = [];
        foreach ($timeline as $dayGroup) {
            $items = array_values(array_filter(
                is_array($dayGroup['items'] ?? null) ? $dayGroup['items'] : [],
                static fn (array $item): bool => (string) ($item['category'] ?? '') === $category
            ));
            if ($items === []) {
                continue;
            }
            $filtered[] = [
                'day' => (string) ($dayGroup['day'] ?? ''),
                'day_label' => (string) ($dayGroup['day_label'] ?? ''),
                'items' => $items,
            ];
        }

        return $filtered;
    }

    /**
     * @param list<array{day: string, day_label: string, items: list<array<string, mixed>>}> $timeline
     * @return array{
     *   days: list<array{day: string, day_label: string, items: list<array<string, mixed>>}>,
     *   page: int, per_page: int, total: int, total_pages: int
     * }
     */
    public static function paginateTimeline(array $timeline, int $page, int $perPage): array
    {
        $flat = [];
        foreach ($timeline as $dayGroup) {
            foreach (is_array($dayGroup['items'] ?? null) ? $dayGroup['items'] : [] as $item) {
                $flat[] = [
                    'day' => (string) ($dayGroup['day'] ?? ''),
                    'day_label' => (string) ($dayGroup['day_label'] ?? ''),
                    'item' => $item,
                ];
            }
        }

        $paged = self::paginateList($flat, $page, $perPage);
        $days = [];
        foreach ($paged['items'] as $row) {
            $day = (string) ($row['day'] ?? '');
            if (!isset($days[$day])) {
                $days[$day] = [
                    'day' => $day,
                    'day_label' => (string) ($row['day_label'] ?? ''),
                    'items' => [],
                ];
            }
            $days[$day]['items'][] = is_array($row['item'] ?? null) ? $row['item'] : [];
        }

        return [
            'days' => array_values($days),
            'page' => $paged['page'],
            'per_page' => $paged['per_page'],
            'total' => $paged['total'],
            'total_pages' => $paged['total_pages'],
        ];
    }

    /** @return array<string, string> */
    public static function timelineCategoryLabels(): array
    {
        return [
            'all' => 'الكل',
            'browse' => 'تصفّح',
            'product' => 'منتجات',
            'cart' => 'سلة',
            'order' => 'طلبات',
            'search' => 'بحث',
        ];
    }

    public static function timelineCategoryForAction(string $action): string
    {
        return match ($action) {
            'page_view' => 'browse',
            'product_view', 'product_quick_view' => 'product',
            'add_to_cart', 'remove_from_cart', 'cart_view' => 'cart',
            'order_placed', 'order_start' => 'order',
            'store_search', 'store_filter' => 'search',
            default => 'other',
        };
    }

    /**
     * @param array<string, int> $stats
     * @return list<array{key: string, label: string, count: int, pct: int, reached: bool}>
     */
    public static function buildFunnel(array $stats): array
    {
        $steps = [
            ['key' => 'visit', 'label' => 'زيارة', 'count' => max(1, (int) ($stats['page_views'] ?? 0))],
            ['key' => 'product', 'label' => 'منتج', 'count' => (int) ($stats['product_views'] ?? 0)],
            ['key' => 'cart', 'label' => 'سلة', 'count' => (int) ($stats['cart_adds'] ?? 0)],
            ['key' => 'order', 'label' => 'طلب', 'count' => (int) ($stats['orders'] ?? 0)],
        ];

        $base = max(1, $steps[0]['count']);
        $result = [];
        foreach ($steps as $step) {
            $count = (int) $step['count'];
            $result[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'count' => $count,
                'pct' => (int) min(100, round(($count / $base) * 100)),
                'reached' => $count > 0,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $events newest first
     * @return list<array{day: string, day_label: string, items: list<array<string, mixed>>}>
     */
    public static function buildAccountTimeline(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $byDay = [];
        $pageCounts = [];

        foreach ($events as $row) {
            $action = (string) ($row['action'] ?? '');
            $ts = strtotime((string) ($row['created_at'] ?? ''));
            if ($ts === false) {
                continue;
            }
            $day = date('Y-m-d', $ts);

            if ($action === 'page_view') {
                $described = self::describePath(
                    (string) ($row['page_path'] ?? ''),
                    (string) ($row['page_title'] ?? '')
                );
                $pathLabel = $described['label'] !== '' ? $described['label'] : 'صفحة';
                $bucketKey = $day . '|' . $pathLabel;
                if (!isset($pageCounts[$bucketKey])) {
                    $pageCounts[$bucketKey] = [
                        'day' => $day,
                        'path' => $pathLabel,
                        'title' => $described['title'],
                        'detail' => $described['detail'],
                        'count' => 0,
                        'last_at' => (string) ($row['created_at'] ?? ''),
                        'last_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                        'last_relative' => (string) ($row['created_at_relative'] ?? self::formatRelativeTime($row['created_at'] ?? null)),
                    ];
                }
                $pageCounts[$bucketKey]['count']++;
                $pageCounts[$bucketKey]['last_at'] = (string) ($row['created_at'] ?? '');
                $pageCounts[$bucketKey]['last_fmt'] = (string) ($row['created_at_fmt'] ?? '');
                $pageCounts[$bucketKey]['last_relative'] = (string) ($row['created_at_relative'] ?? self::formatRelativeTime($row['created_at'] ?? null));

                continue;
            }

            if (in_array($action, ['product_view', 'product_quick_view'], true)) {
                if (!isset($byDay[$day])) {
                    $byDay[$day] = [
                        'day' => $day,
                        'day_label' => self::formatDayLabel($day),
                        'items' => [],
                    ];
                }
                $productName = trim((string) ($row['product_name'] ?? '')) ?: (string) ($row['label_ar'] ?? 'صنف');
                $byDay[$day]['items'][] = [
                    'kind' => 'product',
                    'category' => 'product',
                    'action' => $action,
                    'action_label_ar' => (string) ($row['action_label_ar'] ?? self::actionLabel($action)),
                    'label_ar' => $productName,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'created_at_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                    'created_at_relative' => (string) ($row['created_at_relative'] ?? self::formatRelativeTime($row['created_at'] ?? null)),
                    'icon' => self::actionIcon($action),
                    'session_id' => (string) ($row['session_id'] ?? ''),
                ];

                continue;
            }

            if (!isset($byDay[$day])) {
                $byDay[$day] = [
                    'day' => $day,
                    'day_label' => self::formatDayLabel($day),
                    'items' => [],
                ];
            }

            $byDay[$day]['items'][] = [
                'kind' => 'moment',
                'category' => self::timelineCategoryForAction($action),
                'action' => $action,
                'action_label_ar' => (string) ($row['action_label_ar'] ?? self::actionLabel($action)),
                'label_ar' => (string) ($row['label_ar'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_at_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                'created_at_relative' => (string) ($row['created_at_relative'] ?? self::formatRelativeTime($row['created_at'] ?? null)),
                'icon' => self::actionIcon($action),
                'session_id' => (string) ($row['session_id'] ?? ''),
            ];
        }

        foreach ($pageCounts as $bucket) {
            $day = $bucket['day'];
            if (!isset($byDay[$day])) {
                $byDay[$day] = [
                    'day' => $day,
                    'day_label' => self::formatDayLabel($day),
                    'items' => [],
                ];
            }
            $browseTitle = trim((string) ($bucket['title'] ?? '')) !== ''
                ? (string) $bucket['title']
                : 'تصفّح';
            if ((int) $bucket['count'] > 1) {
                $browseTitle .= ' ×' . (int) $bucket['count'];
            }
            $byDay[$day]['items'][] = [
                'kind' => 'browse',
                'category' => 'browse',
                'action' => 'page_view',
                'action_label_ar' => $browseTitle,
                'label_ar' => (string) ($bucket['detail'] ?? ''),
                'created_at' => $bucket['last_at'],
                'created_at_fmt' => $bucket['last_fmt'],
                'created_at_relative' => $bucket['last_relative'],
                'icon' => 'language',
                'session_id' => '',
            ];
        }

        foreach ($byDay as &$dayGroup) {
            usort($dayGroup['items'], static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        }
        unset($dayGroup);

        uksort($byDay, static fn (string $a, string $b): int => strcmp($b, $a));

        return array_values($byDay);
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    public static function filterAccountGroups(array $groups, string $filter): array
    {
        $filter = trim($filter);
        if ($filter === '' || $filter === 'all') {
            return $groups;
        }

        return array_values(array_filter($groups, static function (array $group) use ($filter): bool {
            return match ($filter) {
                'customers' => (string) ($group['identity_kind'] ?? '') === 'customer',
                'ordered' => (int) ($group['orders'] ?? 0) > 0,
                'cart' => (int) ($group['cart_adds'] ?? 0) > 0 || (int) ($group['cart_removals'] ?? 0) > 0,
                'known' => trim((string) ($group['display_name'] ?? '')) !== 'زائر',
                default => true,
            };
        }));
    }

    /** @param array<string, mixed> $session */
    public static function accountKeyForSession(array $session): string
    {
        return self::accountKeyForSessionPrivate($session);
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

    /**
     * @param list<array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    public static function groupSessionsByAccount(array $sessions): array
    {
        if ($sessions === []) {
            return [];
        }

        $groups = [];
        foreach ($sessions as $session) {
            $key = self::accountKeyForSessionPrivate($session);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'account_key' => $key,
                    'display_name' => (string) ($session['display_name'] ?? 'زائر'),
                    'identity_kind' => (string) ($session['identity_kind'] ?? 'guest'),
                    'identity_subtitle' => (string) ($session['identity_subtitle'] ?? ''),
                    'identity_phone' => (string) ($session['identity_phone'] ?? ''),
                    'web_customer_id' => trim((string) ($session['web_customer_id'] ?? '')),
                    'sessions' => [],
                    'session_count' => 0,
                    'events' => 0,
                    'page_views' => 0,
                    'product_views' => 0,
                    'cart_adds' => 0,
                    'cart_removals' => 0,
                    'orders' => 0,
                    'first_seen' => null,
                    'last_seen' => null,
                    'first_seen_fmt' => '—',
                    'last_seen_fmt' => '—',
                ];
            }

            $group = &$groups[$key];
            $group['sessions'][] = $session;
            $group['session_count']++;
            $group['events'] += (int) ($session['events'] ?? 0);
            $group['page_views'] += (int) ($session['page_views'] ?? 0);
            $group['product_views'] += (int) ($session['product_views'] ?? 0);
            $group['cart_adds'] += (int) ($session['cart_adds'] ?? 0);
            $group['cart_removals'] += (int) ($session['cart_removals'] ?? 0);
            $group['orders'] += (int) ($session['orders'] ?? 0);

            if ($group['display_name'] === 'زائر' && trim((string) ($session['display_name'] ?? '')) !== 'زائر') {
                $group['display_name'] = (string) $session['display_name'];
            }
            if ($group['identity_phone'] === '' && trim((string) ($session['identity_phone'] ?? '')) !== '') {
                $group['identity_phone'] = (string) $session['identity_phone'];
            }
            if ($group['web_customer_id'] === '' && trim((string) ($session['web_customer_id'] ?? '')) !== '') {
                $group['web_customer_id'] = (string) $session['web_customer_id'];
            }

            $firstSeen = $session['first_seen'] ?? null;
            $lastSeen = $session['last_seen'] ?? null;
            if ($firstSeen !== null && ($group['first_seen'] === null || strtotime((string) $firstSeen) < strtotime((string) $group['first_seen']))) {
                $group['first_seen'] = $firstSeen;
                $group['first_seen_fmt'] = (string) ($session['first_seen_fmt'] ?? self::formatTimestamp($firstSeen));
            }
            if ($lastSeen !== null && ($group['last_seen'] === null || strtotime((string) $lastSeen) > strtotime((string) $group['last_seen']))) {
                $group['last_seen'] = $lastSeen;
                $group['last_seen_fmt'] = (string) ($session['last_seen_fmt'] ?? self::formatTimestamp($lastSeen));
            }
            unset($group);
        }

        usort($groups, static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['last_seen'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['last_seen'] ?? '')) ?: 0;

            return $bTs <=> $aTs;
        });

        foreach ($groups as &$group) {
            usort($group['sessions'], static function (array $a, array $b): int {
                $aTs = strtotime((string) ($a['last_seen'] ?? '')) ?: 0;
                $bTs = strtotime((string) ($b['last_seen'] ?? '')) ?: 0;

                return $bTs <=> $aTs;
            });
            $group['funnel'] = self::buildFunnel([
                'page_views' => (int) ($group['page_views'] ?? 0),
                'product_views' => (int) ($group['product_views'] ?? 0),
                'cart_adds' => (int) ($group['cart_adds'] ?? 0),
                'orders' => (int) ($group['orders'] ?? 0),
            ]);
            $group['last_seen_relative'] = self::formatRelativeTime($group['last_seen'] ?? null);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{
     *   stats: array<string, mixed>,
     *   highlights: list<array<string, mixed>>,
     *   raw_count: int,
     *   highlight_count: int
     * }
     */
    public static function buildSessionDigest(array $events): array
    {
        if ($events === []) {
            return [
                'stats' => [],
                'highlights' => [],
                'raw_count' => 0,
                'highlight_count' => 0,
            ];
        }

        $stats = [
            'page_views' => 0,
            'product_views' => 0,
            'cart_adds' => 0,
            'cart_removals' => 0,
            'orders' => 0,
            'searches' => 0,
            'logins' => 0,
            'unique_pages' => 0,
            'duration_label' => '—',
            'location_label' => '—',
            'location_source_label' => '',
            'map_url' => null,
            'visitor_ip' => '',
        ];

        $firstTs = null;
        $lastTs = null;
        $pageBuckets = [];
        $productBuckets = [];
        $highlights = [];
        $locationShown = false;
        $seenOrders = [];

        foreach ($events as $row) {
            $action = (string) ($row['action'] ?? '');
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $ts = strtotime((string) ($row['created_at'] ?? ''));
            if ($ts !== false) {
                $firstTs = $firstTs === null ? $ts : min($firstTs, $ts);
                $lastTs = $lastTs === null ? $ts : max($lastTs, $ts);
            }

            if ($stats['visitor_ip'] === '' && trim((string) ($row['visitor_ip'] ?? '')) !== '') {
                $stats['visitor_ip'] = (string) $row['visitor_ip'];
            }

            if (!$locationShown) {
                $city = trim((string) ($row['city_ar'] ?? ''));
                $country = trim((string) ($row['country_ar'] ?? ''));
                if ($city !== '' || $country !== '') {
                    $stats['location_label'] = $city !== '' && $country !== ''
                        ? ($city . '، ' . $country)
                        : ($city !== '' ? $city : $country);
                    $stats['location_source_label'] = (string) ($row['location_source_label'] ?? '');
                    $stats['map_url'] = $row['map_url'] ?? null;
                    $locationShown = true;
                } elseif (!empty($row['map_url'])) {
                    $stats['map_url'] = $row['map_url'];
                }
            }

            if ($action === 'page_view') {
                $stats['page_views']++;
                $described = self::describePath(
                    (string) ($row['page_path'] ?? ''),
                    (string) ($row['page_title'] ?? '')
                );
                $pathLabel = $described['label'] !== '' ? $described['label'] : 'صفحة';
                if (!isset($pageBuckets[$pathLabel])) {
                    $pageBuckets[$pathLabel] = [
                        'path' => $pathLabel,
                        'title' => $described['title'],
                        'detail' => $described['detail'],
                        'label' => $pathLabel,
                        'count' => 0,
                        'first_at' => (string) ($row['created_at'] ?? ''),
                        'last_at' => (string) ($row['created_at'] ?? ''),
                        'first_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                        'last_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                    ];
                }
                $pageBuckets[$pathLabel]['count']++;
                $pageBuckets[$pathLabel]['last_at'] = (string) ($row['created_at'] ?? '');
                $pageBuckets[$pathLabel]['last_fmt'] = (string) ($row['created_at_fmt'] ?? '');

                continue;
            }

            if (in_array($action, ['product_view', 'product_quick_view'], true)) {
                $stats['product_views']++;
                $guid = trim((string) ($row['product_guid'] ?? ''));
                $key = $guid !== '' ? $guid : (string) ($row['label_ar'] ?? 'product');
                $productBuckets[$key] = [
                    'product_name' => trim((string) ($row['product_name'] ?? '')) ?: (string) ($row['label_ar'] ?? 'صنف'),
                    'product_code' => (string) ($row['product_code'] ?? ''),
                    'created_at_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];

                continue;
            }

            if ($action === 'add_to_cart') {
                $stats['cart_adds']++;
            } elseif ($action === 'remove_from_cart') {
                $stats['cart_removals']++;
            } elseif ($action === 'order_placed') {
                $orderKey = trim((string) ($meta['order_number'] ?? ''));
                if ($orderKey === '') {
                    $orderKey = trim((string) ($meta['order_id'] ?? ''));
                }
                if ($orderKey !== '' && isset($seenOrders[$orderKey])) {
                    continue;
                }
                if ($orderKey !== '') {
                    $seenOrders[$orderKey] = true;
                }
                $stats['orders']++;
            } elseif ($action === 'store_search') {
                $stats['searches']++;
            } elseif ($action === 'login') {
                $stats['logins']++;
            }

            if (!in_array($action, ['page_view', 'product_view', 'product_quick_view'], true)) {
                $highlights[] = [
                    'kind' => 'moment',
                    'action' => $action,
                    'action_label_ar' => (string) ($row['action_label_ar'] ?? self::actionLabel($action)),
                    'label_ar' => (string) ($row['label_ar'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'created_at_fmt' => (string) ($row['created_at_fmt'] ?? ''),
                    'icon' => self::actionIcon($action),
                ];
            }
        }

        uasort($pageBuckets, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $stats['unique_pages'] = count($pageBuckets);
        $stats['top_pages'] = array_slice(array_values($pageBuckets), 0, 6);
        $stats['top_products'] = array_slice(array_values($productBuckets), 0, 6);

        if ($firstTs !== null && $lastTs !== null) {
            $minutes = max(1, (int) round(($lastTs - $firstTs) / 60));
            if ($lastTs - $firstTs < 60) {
                $stats['duration_label'] = 'أقل من دقيقة';
            } elseif ($minutes < 60) {
                $stats['duration_label'] = $minutes . ' د';
            } else {
                $hours = intdiv($minutes, 60);
                $mins = $minutes % 60;
                $stats['duration_label'] = $mins > 0 ? ($hours . ' س ' . $mins . ' د') : ($hours . ' س');
            }
            $stats['started_fmt'] = date('Y-m-d H:i', $firstTs);
            $stats['ended_fmt'] = date('Y-m-d H:i', $lastTs);
        }

        usort($highlights, static fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        return [
            'stats' => $stats,
            'highlights' => $highlights,
            'raw_count' => count($events),
            'highlight_count' => count($highlights),
        ];
    }

    /** @param array<string, mixed> $session */
    private static function accountKeyForSessionPrivate(array $session): string
    {
        if ((string) ($session['identity_kind'] ?? '') === 'staff') {
            $staffId = trim((string) ($session['staff_user_id'] ?? ''));
            if ($staffId !== '') {
                return 'staff:' . $staffId;
            }
        }

        $customerId = trim((string) ($session['web_customer_id'] ?? ''));
        if ($customerId !== '') {
            return 'customer:' . $customerId;
        }

        $phone = preg_replace('/\D+/', '', (string) ($session['identity_phone'] ?? ''));
        if (is_string($phone) && strlen($phone) >= 8) {
            return 'guest_phone:' . $phone;
        }

        $kind = (string) ($session['identity_kind'] ?? '');
        $name = mb_strtolower(trim((string) ($session['display_name'] ?? '')));
        if ($kind === 'guest_order' && $name !== '' && $name !== 'زائر') {
            return 'guest_name:' . $name;
        }

        return 'session:' . trim((string) ($session['session_id'] ?? ''));
    }

    private static function actionIcon(string $action): string
    {
        return match ($action) {
            'order_placed' => 'shopping_bag',
            'add_to_cart' => 'add_shopping_cart',
            'remove_from_cart' => 'remove_shopping_cart',
            'product_view', 'product_quick_view' => 'inventory_2',
            'cart_view' => 'shopping_cart',
            'store_search' => 'search',
            'store_filter' => 'tune',
            'login' => 'login',
            default => 'trip_origin',
        };
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
        $path = (string) ($meta['path'] ?? '');
        $pageTitle = (string) ($meta['title'] ?? '');
        $described = self::describePath($path, $pageTitle);
        $row['page_path'] = $path;
        $row['page_title'] = $pageTitle;
        $row['page_label'] = $described['label'];
        $row['page_title_ar'] = $described['title'];
        $row['page_detail_ar'] = $described['detail'];
        $storedLabel = trim((string) ($meta['label_ar'] ?? ''));
        if ($action === 'page_view') {
            $row['label_ar'] = $described['label'];
        } elseif ($storedLabel !== '') {
            $row['label_ar'] = self::humanizeVisitorText($storedLabel);
        } else {
            $row['label_ar'] = self::buildLabelAr($action, $meta);
        }
        $row['product_name'] = (string) ($meta['product_name'] ?? '');
        $row['product_code'] = (string) ($meta['product_code'] ?? '');
        $row['product_guid'] = (string) ($meta['product_guid'] ?? '');
        $row['action_label_ar'] = self::actionLabel($action);
        $row['referer_short'] = self::shortReferer((string) ($row['referer'] ?? ''));
        $row['created_at_fmt'] = self::formatTimestamp($row['created_at'] ?? null);
        $row['created_at_relative'] = self::formatRelativeTime($row['created_at'] ?? null);
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

    public static function formatRelativeTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return (string) $value;
        }

        $diff = time() - $timestamp;
        if ($diff < 0) {
            return self::formatTimestamp($value);
        }
        if ($diff < 60) {
            return 'الآن';
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);

            return 'منذ ' . $mins . ($mins === 1 ? ' دقيقة' : ' د');
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);

            return 'منذ ' . $hours . ($hours === 1 ? ' ساعة' : ' س');
        }
        if ($diff < 172800) {
            return 'أمس ' . date('H:i', $timestamp);
        }
        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);

            return 'منذ ' . $days . ($days === 1 ? ' يوم' : ' أيام');
        }

        return self::formatTimestamp($value);
    }

    private static function formatDayLabel(string $day): string
    {
        $ts = strtotime($day . ' 12:00:00');
        if ($ts === false) {
            return $day;
        }

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($day === $today) {
            return 'اليوم';
        }
        if ($day === $yesterday) {
            return 'أمس';
        }

        return date('j M Y', $ts);
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
