<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Database;
use PDO;

final class NotificationService
{
    public const SCOPE_PUBLIC = 'public';
    public const SCOPE_PRIVATE = 'private';

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_GUESTS = 'guests';
    public const AUDIENCE_CUSTOMERS = 'customers';
    public const AUDIENCE_STAFF = 'staff';

    public const READER_GUEST = 'guest';
    public const READER_CUSTOMER = 'customer';
    public const READER_STAFF = 'staff';

    private const SESSION_GUEST_READER_KEY = 'portal_notification_guest_id';

    public static function ensureTable(): void
    {
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS portal_notifications (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                scope VARCHAR(16) NOT NULL CHECK (scope IN ('public', 'private')),
                audience VARCHAR(16) NOT NULL DEFAULT 'all' CHECK (audience IN ('all', 'guests', 'customers', 'staff')),
                title_ar VARCHAR(200) NOT NULL,
                body_ar TEXT NOT NULL,
                link_url VARCHAR(500),
                icon VARCHAR(50) NOT NULL DEFAULT 'notifications',
                recipient_web_customer_id UUID REFERENCES web_customers (id) ON DELETE CASCADE,
                recipient_web_user_id UUID REFERENCES web_users (id) ON DELETE CASCADE,
                source VARCHAR(50) NOT NULL DEFAULT 'manual',
                created_by_web_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL,
                expires_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_portal_notifications_created ON portal_notifications (created_at DESC)'
        );
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS portal_notification_reads (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                notification_id UUID NOT NULL REFERENCES portal_notifications (id) ON DELETE CASCADE,
                reader_type VARCHAR(16) NOT NULL CHECK (reader_type IN ('guest', 'customer', 'staff')),
                reader_id VARCHAR(64) NOT NULL,
                read_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (notification_id, reader_type, reader_id)
            )"
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_portal_notification_reads_reader ON portal_notification_reads (reader_type, reader_id, read_at DESC)'
        );
        self::ensurePermission();
        self::ensureAudienceGuestsSupport();
    }

    private static function ensureAudienceGuestsSupport(): void
    {
        try {
            Database::pdo()->exec(
                "ALTER TABLE portal_notifications DROP CONSTRAINT IF EXISTS portal_notifications_audience_check"
            );
            Database::pdo()->exec(
                "ALTER TABLE portal_notifications
                 ADD CONSTRAINT portal_notifications_audience_check
                 CHECK (audience IN ('all', 'guests', 'customers', 'staff'))"
            );
        } catch (\Throwable) {
            // Best-effort for installs that use ENUM types from SQL migrations.
        }

        try {
            Database::pdo()->exec("ALTER TYPE notification_audience ADD VALUE IF NOT EXISTS 'guests'");
        } catch (\Throwable) {
            // ENUM may not exist when the table uses VARCHAR checks only.
        }
    }

    private static function ensurePermission(): void
    {
        Database::pdo()->exec(
            "INSERT INTO web_permissions (code, name_ar, category_ar, description_ar)
             VALUES ('notifications.manage', 'إدارة الإشعارات', 'إدارة', 'إرسال إشعارات عامة وخاصة')
             ON CONFLICT (code) DO NOTHING"
        );
        Database::pdo()->exec(
            "INSERT INTO web_role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM web_roles r
             JOIN web_permissions p ON p.code = 'notifications.manage'
             WHERE r.code = 'super_admin'
             ON CONFLICT DO NOTHING"
        );
    }

    /** @return array{reader_type: string, reader_id: string, is_customer: bool, is_staff: bool} */
    public static function currentReader(): array
    {
        self::ensureTable();

        if (WebSession::check()) {
            $user = WebSession::user();

            return [
                'reader_type' => self::READER_STAFF,
                'reader_id' => (string) ($user['id'] ?? ''),
                'is_customer' => false,
                'is_staff' => true,
            ];
        }

        if (CustomerSession::check()) {
            $customer = CustomerSession::customer();

            return [
                'reader_type' => self::READER_CUSTOMER,
                'reader_id' => (string) ($customer['id'] ?? ''),
                'is_customer' => true,
                'is_staff' => false,
            ];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $guestId = (string) ($_SESSION[self::SESSION_GUEST_READER_KEY] ?? '');
        if ($guestId === '') {
            $guestId = bin2hex(random_bytes(16));
            $_SESSION[self::SESSION_GUEST_READER_KEY] = $guestId;
        }

        return [
            'reader_type' => self::READER_GUEST,
            'reader_id' => $guestId,
            'is_customer' => false,
            'is_staff' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForReader(int $limit = 30): array
    {
        self::ensureTable();
        $reader = self::currentReader();
        $limit = max(1, min(100, $limit));
        $conditions = self::visibilitySql($reader);
        $sql = 'SELECT n.id::text AS id,
                       n.scope,
                       n.audience,
                       n.title_ar,
                       n.body_ar,
                       n.link_url,
                       n.icon,
                       n.source,
                       n.created_at,
                       (r.id IS NOT NULL) AS is_read
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                ORDER BY n.created_at DESC
                LIMIT :limit';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function unreadCount(): int
    {
        self::ensureTable();
        $reader = self::currentReader();
        $conditions = self::visibilitySql($reader);
        $sql = 'SELECT COUNT(*)::int
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND r.id IS NULL';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function markRead(string $notificationId): bool
    {
        self::ensureTable();
        $notificationId = trim($notificationId);
        if ($notificationId === '') {
            return false;
        }

        $reader = self::currentReader();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notification_reads (notification_id, reader_type, reader_id)
             VALUES (:notification_id, :reader_type, :reader_id)
             ON CONFLICT (notification_id, reader_type, reader_id) DO NOTHING'
        );
        $stmt->execute([
            'notification_id' => $notificationId,
            'reader_type' => $reader['reader_type'],
            'reader_id' => $reader['reader_id'],
        ]);

        return true;
    }

    public static function markAllRead(): int
    {
        $items = self::listForReader(100);
        $marked = 0;
        foreach ($items as $item) {
            if (empty($item['is_read'])) {
                self::markRead((string) ($item['id'] ?? ''));
                $marked++;
            }
        }

        return $marked;
    }

    public static function createPublic(
        string $title,
        string $body,
        string $audience = self::AUDIENCE_ALL,
        ?string $linkUrl = null,
        string $icon = 'campaign',
        ?string $expiresAt = null,
        ?string $createdByUserId = null,
        string $source = 'manual'
    ): string {
        self::ensureTable();
        if (!in_array($audience, [self::AUDIENCE_ALL, self::AUDIENCE_GUESTS, self::AUDIENCE_CUSTOMERS, self::AUDIENCE_STAFF], true)) {
            $audience = self::AUDIENCE_ALL;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notifications (
                scope, audience, title_ar, body_ar, link_url, icon, source, created_by_web_user_id, expires_at
             ) VALUES (
                :scope, :audience, :title, :body, :link_url, :icon, :source, :created_by, :expires_at
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'scope' => self::SCOPE_PUBLIC,
            'audience' => $audience,
            'title' => trim($title),
            'body' => trim($body),
            'link_url' => self::normalizeLink($linkUrl),
            'icon' => $icon !== '' ? $icon : 'campaign',
            'source' => $source,
            'created_by' => $createdByUserId,
            'expires_at' => $expiresAt,
        ]);

        return (string) $stmt->fetchColumn();
    }

    public static function createPrivateForCustomer(
        string $customerId,
        string $title,
        string $body,
        ?string $linkUrl = null,
        string $icon = 'notifications',
        string $source = 'system'
    ): string {
        self::ensureTable();
        $customerId = trim($customerId);
        if ($customerId === '') {
            throw new \InvalidArgumentException('معرّف العميل مطلوب.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notifications (
                scope, audience, title_ar, body_ar, link_url, icon, source,
                recipient_web_customer_id
             ) VALUES (
                :scope, :audience, :title, :body, :link_url, :icon, :source, :customer_id
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'scope' => self::SCOPE_PRIVATE,
            'audience' => self::AUDIENCE_CUSTOMERS,
            'title' => trim($title),
            'body' => trim($body),
            'link_url' => self::normalizeLink($linkUrl),
            'icon' => $icon !== '' ? $icon : 'notifications',
            'source' => $source,
            'customer_id' => $customerId,
        ]);

        return (string) $stmt->fetchColumn();
    }

    public static function createPrivateForStaff(
        string $userId,
        string $title,
        string $body,
        ?string $linkUrl = null,
        string $icon = 'notifications',
        string $source = 'system'
    ): string {
        self::ensureTable();
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('معرّف الموظف مطلوب.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notifications (
                scope, audience, title_ar, body_ar, link_url, icon, source,
                recipient_web_user_id
             ) VALUES (
                :scope, :audience, :title, :body, :link_url, :icon, :source, :user_id
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'scope' => self::SCOPE_PRIVATE,
            'audience' => self::AUDIENCE_STAFF,
            'title' => trim($title),
            'body' => trim($body),
            'link_url' => self::normalizeLink($linkUrl),
            'icon' => $icon !== '' ? $icon : 'notifications',
            'source' => $source,
            'user_id' => $userId,
        ]);

        return (string) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function listSent(int $limit = 50): array
    {
        self::ensureTable();
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT n.id::text AS id,
                    n.scope,
                    n.audience,
                    n.title_ar,
                    n.body_ar,
                    n.link_url,
                    n.icon,
                    n.source,
                    n.created_at,
                    n.expires_at,
                    wc.name_ar AS customer_name_ar,
                    wu.display_name_ar AS staff_name_ar
             FROM portal_notifications n
             LEFT JOIN web_customers wc ON wc.id = n.recipient_web_customer_id
             LEFT JOIN web_users wu ON wu.id = n.recipient_web_user_id
             ORDER BY n.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function notifyOrderStatusChanged(string $orderId, string $nextStatus): void
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return;
        }

        $order = OrderService::getOrderDetails($orderId);
        if ($order === null) {
            return;
        }

        $labels = [
            'pending' => 'قيد المراجعة',
            'confirmed' => 'مؤكّد',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغى',
        ];
        $label = $labels[$nextStatus] ?? $nextStatus;
        $orderNumber = (string) ($order['order_number'] ?? '');
        $title = 'تحديث حالة الطلب';
        $body = 'طلبك رقم ' . $orderNumber . ' أصبح الآن: ' . $label . '.';

        $link = '/my-orders.php';
        $token = trim((string) ($order['quote_access_token'] ?? ''));
        if ($token !== '') {
            $link = '/track-order.php?token=' . rawurlencode($token);
        }

        $customerId = trim((string) ($order['web_customer_id'] ?? ''));
        if ($customerId === '') {
            return;
        }

        self::createPrivateForCustomer($customerId, $title, $body, $link, 'shopping_bag', 'order_status');
    }

    public static function notifyCustomerApproved(string $customerId): void
    {
        self::createPrivateForCustomer(
            $customerId,
            'تم تفعيل حسابك',
            'مرحباً بك! تمت الموافقة على تسجيلك ويمكنك الآن تصفح المتجر وإتمام الطلبات.',
            '/store.php',
            'verified',
            'customer_approved'
        );
    }

    public static function notifyCustomerRejected(string $customerId, string $reason = ''): void
    {
        $body = 'للأسف لم تتم الموافقة على طلب التسجيل.';
        if (trim($reason) !== '') {
            $body .= ' السبب: ' . trim($reason);
        }

        self::createPrivateForCustomer(
            $customerId,
            'بخصوص طلب التسجيل',
            $body,
            '/register.php',
            'person_off',
            'customer_rejected'
        );
    }

    /**
     * @param array{reader_type: string, reader_id: string, is_customer: bool, is_staff: bool} $reader
     * @return array{parts: list<string>, params: array<string, string>}
     */
    private static function visibilitySql(array $reader): array
    {
        $parts = [];
        $params = [];

        $parts[] = '(n.scope = :scope_public AND n.audience = :audience_all)';
        $params[':scope_public'] = self::SCOPE_PUBLIC;
        $params[':audience_all'] = self::AUDIENCE_ALL;

        if (!$reader['is_customer'] && !$reader['is_staff']) {
            $parts[] = '(n.scope = :scope_public_guests AND n.audience = :audience_guests)';
            $params[':scope_public_guests'] = self::SCOPE_PUBLIC;
            $params[':audience_guests'] = self::AUDIENCE_GUESTS;
        }

        if ($reader['is_customer']) {
            $parts[] = '(n.scope = :scope_public2 AND n.audience = :audience_customers)';
            $params[':scope_public2'] = self::SCOPE_PUBLIC;
            $params[':audience_customers'] = self::AUDIENCE_CUSTOMERS;
            $parts[] = '(n.scope = :scope_private AND n.recipient_web_customer_id::text = :customer_id)';
            $params[':scope_private'] = self::SCOPE_PRIVATE;
            $params[':customer_id'] = $reader['reader_id'];
        }

        if ($reader['is_staff']) {
            $parts[] = '(n.scope = :scope_public3 AND n.audience = :audience_staff)';
            $params[':scope_public3'] = self::SCOPE_PUBLIC;
            $params[':audience_staff'] = self::AUDIENCE_STAFF;
            $parts[] = '(n.scope = :scope_private2 AND n.recipient_web_user_id::text = :staff_id)';
            $params[':scope_private2'] = self::SCOPE_PRIVATE;
            $params[':staff_id'] = $reader['reader_id'];
        }

        return ['parts' => $parts, 'params' => $params];
    }

    private static function normalizeLink(?string $linkUrl): ?string
    {
        $linkUrl = trim((string) $linkUrl);
        if ($linkUrl === '') {
            return null;
        }
        if (str_starts_with($linkUrl, 'http://') || str_starts_with($linkUrl, 'https://')) {
            return $linkUrl;
        }

        return str_starts_with($linkUrl, '/') ? $linkUrl : '/' . $linkUrl;
    }
}
