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

    public const REF_TYPE_ORDER = 'order';
    public const REF_TYPE_CUSTOMER_REGISTRATION = 'customer_registration';

    private const SESSION_GUEST_READER_KEY = 'portal_notification_guest_id';
    private const STAFF_ALERT_TTL_DAYS = 7;

    /** @var string|null */
    private static ?string $staffSinceCache = null;

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
                reference_type VARCHAR(32),
                reference_id VARCHAR(64),
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
                dismissed_at TIMESTAMPTZ,
                UNIQUE (notification_id, reader_type, reader_id)
            )"
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_portal_notification_reads_reader ON portal_notification_reads (reader_type, reader_id, read_at DESC)'
        );
        self::ensurePermission();
        self::ensureAudienceGuestsSupport();
        self::ensureDismissedColumn();
        self::ensureReferenceColumns();
    }

    private static function ensureReferenceColumns(): void
    {
        try {
            Database::pdo()->exec(
                'ALTER TABLE portal_notifications
                 ADD COLUMN IF NOT EXISTS reference_type VARCHAR(32),
                 ADD COLUMN IF NOT EXISTS reference_id VARCHAR(64)'
            );
            Database::pdo()->exec(
                'CREATE INDEX IF NOT EXISTS ix_portal_notifications_reference
                 ON portal_notifications (source, reference_type, reference_id)
                 WHERE reference_id IS NOT NULL'
            );
        } catch (\Throwable) {
            // Best-effort for older PostgreSQL installs.
        }
    }

    private static function ensureDismissedColumn(): void
    {
        try {
            Database::pdo()->exec(
                'ALTER TABLE portal_notification_reads ADD COLUMN IF NOT EXISTS dismissed_at TIMESTAMPTZ'
            );
        } catch (\Throwable) {
            // Older PostgreSQL without IF NOT EXISTS on ADD COLUMN.
        }
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

        if (CustomerSession::isLoggedIn()) {
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
                       (r.read_at IS NOT NULL) AS is_read
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND (r.dismissed_at IS NULL)' . self::staffSinceSql($reader) . '
                ORDER BY n.created_at DESC
                LIMIT :limit';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        self::bindStaffSince($stmt, $reader);
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
                  AND (r.dismissed_at IS NULL)
                  AND r.read_at IS NULL' . self::staffSinceSql($reader);

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        self::bindStaffSince($stmt, $reader);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Latest visible notification timestamp for efficient polling. */
    public static function latestActivityAt(): ?string
    {
        self::ensureTable();
        $reader = self::currentReader();
        $conditions = self::visibilitySql($reader);
        $sql = 'SELECT MAX(n.created_at)::text
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND (r.dismissed_at IS NULL)' . self::staffSinceSql($reader);

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        self::bindStaffSince($stmt, $reader);
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
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

    public static function dismissForReader(string $notificationId): bool
    {
        self::ensureTable();
        $notificationId = trim($notificationId);
        if ($notificationId === '') {
            return false;
        }

        $reader = self::currentReader();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notification_reads (notification_id, reader_type, reader_id, read_at, dismissed_at)
             VALUES (:notification_id, :reader_type, :reader_id, NOW(), NOW())
             ON CONFLICT (notification_id, reader_type, reader_id)
             DO UPDATE SET dismissed_at = NOW(), read_at = COALESCE(portal_notification_reads.read_at, NOW())'
        );
        $stmt->execute([
            'notification_id' => $notificationId,
            'reader_type' => $reader['reader_type'],
            'reader_id' => $reader['reader_id'],
        ]);

        return true;
    }

    public static function deleteNotification(string $notificationId): bool
    {
        self::ensureTable();
        $notificationId = trim($notificationId);
        if ($notificationId === '') {
            return false;
        }

        $stmt = Database::pdo()->prepare('DELETE FROM portal_notifications WHERE id = :id');
        $stmt->execute(['id' => $notificationId]);

        return $stmt->rowCount() > 0;
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

    public static function latestUnread(): ?array
    {
        $items = self::listForReader(5);
        foreach ($items as $item) {
            if (empty($item['is_read'])) {
                return $item;
            }
        }

        return null;
    }

    public static function createPublic(
        string $title,
        string $body,
        string $audience = self::AUDIENCE_ALL,
        ?string $linkUrl = null,
        string $icon = 'campaign',
        ?string $expiresAt = null,
        ?string $createdByUserId = null,
        string $source = 'manual',
        ?string $referenceType = null,
        ?string $referenceId = null
    ): string {
        self::ensureTable();
        if (!in_array($audience, [self::AUDIENCE_ALL, self::AUDIENCE_GUESTS, self::AUDIENCE_CUSTOMERS, self::AUDIENCE_STAFF], true)) {
            $audience = self::AUDIENCE_ALL;
        }

        $referenceType = trim((string) $referenceType);
        $referenceId = trim((string) $referenceId);
        if ($referenceType === '') {
            $referenceType = null;
        }
        if ($referenceId === '') {
            $referenceId = null;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notifications (
                scope, audience, title_ar, body_ar, link_url, icon, source,
                created_by_web_user_id, expires_at, reference_type, reference_id
             ) VALUES (
                :scope, :audience, :title, :body, :link_url, :icon, :source,
                :created_by, :expires_at, :reference_type, :reference_id
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
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        $id = (string) $stmt->fetchColumn();
        self::dispatchDevicePush($id);

        return $id;
    }

    private static function dispatchDevicePush(string $notificationId): void
    {
        $notificationId = trim($notificationId);
        if ($notificationId === '') {
            return;
        }

        register_shutdown_function(static function () use ($notificationId): void {
            try {
                WebPushService::sendForNotificationId($notificationId);
            } catch (\Throwable) {
                // Never block notification persistence or the HTTP response.
            }
        });
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

        $id = (string) $stmt->fetchColumn();
        self::dispatchDevicePush($id);

        return $id;
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

        $id = (string) $stmt->fetchColumn();
        self::dispatchDevicePush($id);

        return $id;
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

    public static function notifyCustomerRegistrationPending(string $customerId): void
    {
        self::createPrivateForCustomer(
            $customerId,
            'طلب التسجيل قيد المراجعة',
            'تم استلام طلبك بنجاح. يمكنك التصفح بصلاحيات الزائر حتى تفعيل حسابك — سنُعلمك فور الموافقة.',
            '/store.php',
            'hourglass_top',
            'customer_pending'
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

    public static function notifyStaffNewOrder(
        string $orderId,
        string $orderNumber,
        string $customerName,
        string $customerPhone,
        int $itemCount
    ): void {
        try {
            $orderId = trim($orderId);
            $orderNumber = trim($orderNumber);
            if ($orderId === '' || $orderNumber === '') {
                return;
            }

            $customerName = trim($customerName);
            $customerPhone = trim($customerPhone);
            $body = 'طلب رقم ' . $orderNumber;
            if ($customerName !== '') {
                $body .= ' — ' . $customerName;
            }
            if ($customerPhone !== '') {
                $body .= ' · ' . $customerPhone;
            }
            if ($itemCount > 0) {
                $body .= ' · ' . $itemCount . ' صنف';
            }

            self::createPublic(
                'طلب جديد',
                $body,
                self::AUDIENCE_STAFF,
                '/dashboard/orders.php?details=' . rawurlencode($orderId),
                'shopping_cart',
                self::defaultStaffAlertExpiresAt(),
                null,
                'new_order',
                self::REF_TYPE_ORDER,
                $orderId
            );
        } catch (\Throwable) {
            // Never block order creation.
        }
    }

    public static function notifyStaffNewRegistration(string $customerId, string $name, string $phone): void
    {
        try {
            $customerId = trim($customerId);
            $name = trim($name);
            $phone = trim($phone);
            if ($customerId === '' || $name === '') {
                return;
            }

            $body = $name;
            if ($phone !== '') {
                $body .= ' · ' . $phone;
            }

            self::createPublic(
                'طلب تسجيل عميل جديد',
                $body,
                self::AUDIENCE_STAFF,
                '/dashboard/customers.php?status=pending&details=' . rawurlencode($customerId),
                'person_add',
                self::defaultStaffAlertExpiresAt(),
                null,
                'new_registration',
                self::REF_TYPE_CUSTOMER_REGISTRATION,
                $customerId
            );
        } catch (\Throwable) {
            // Never block registration.
        }
    }

    public static function resolveStaffOrderAlert(string $orderId): void
    {
        self::resolveStaffReferenceAlert('new_order', self::REF_TYPE_ORDER, $orderId, '/dashboard/orders.php?details=' . rawurlencode($orderId));
    }

    public static function resolveStaffRegistrationAlert(string $customerId): void
    {
        self::resolveStaffReferenceAlert(
            'new_registration',
            self::REF_TYPE_CUSTOMER_REGISTRATION,
            $customerId,
            '/dashboard/customers.php?status=pending&details=' . rawurlencode($customerId)
        );
    }

    public static function notifyStaffOrderHandled(
        string $orderId,
        string $orderNumber,
        string $statusLabel,
        ?string $handlerUserId = null,
        ?string $handlerName = null
    ): void {
        try {
            $orderId = trim($orderId);
            $orderNumber = trim($orderNumber);
            if ($orderId === '' || $orderNumber === '') {
                return;
            }

            $handlerName = trim((string) $handlerName);
            $body = 'طلب رقم ' . $orderNumber . ' — ' . trim($statusLabel);
            if ($handlerName !== '') {
                $body .= ' · بواسطة ' . $handlerName;
            }

            $notificationId = self::createPublic(
                'تمت متابعة الطلب',
                $body,
                self::AUDIENCE_STAFF,
                '/dashboard/orders.php?details=' . rawurlencode($orderId),
                'task_alt',
                self::defaultStaffAlertExpiresAt(),
                $handlerUserId,
                'order_handled',
                self::REF_TYPE_ORDER,
                $orderId
            );

            self::markReadForStaff($notificationId, $handlerUserId);
        } catch (\Throwable) {
            // Never block order updates.
        }
    }

    public static function notifyStaffRegistrationHandled(
        string $customerId,
        string $customerName,
        string $actionLabel,
        ?string $handlerUserId = null,
        ?string $handlerName = null
    ): void {
        try {
            $customerId = trim($customerId);
            $customerName = trim($customerName);
            if ($customerId === '' || $customerName === '') {
                return;
            }

            $handlerName = trim((string) $handlerName);
            $body = $customerName . ' — ' . trim($actionLabel);
            if ($handlerName !== '') {
                $body .= ' · بواسطة ' . $handlerName;
            }

            $notificationId = self::createPublic(
                'تمت متابعة طلب التسجيل',
                $body,
                self::AUDIENCE_STAFF,
                '/dashboard/customers.php?details=' . rawurlencode($customerId),
                'how_to_reg',
                self::defaultStaffAlertExpiresAt(),
                $handlerUserId,
                'registration_handled',
                self::REF_TYPE_CUSTOMER_REGISTRATION,
                $customerId
            );

            self::markReadForStaff($notificationId, $handlerUserId);
        } catch (\Throwable) {
            // Never block customer updates.
        }
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

    private static function defaultStaffAlertExpiresAt(): string
    {
        return (new \DateTimeImmutable('+' . self::STAFF_ALERT_TTL_DAYS . ' days'))->format('Y-m-d H:i:sP');
    }

    private static function resolveStaffReferenceAlert(
        string $source,
        string $referenceType,
        string $referenceId,
        string $linkUrl
    ): void {
        self::ensureTable();
        $source = trim($source);
        $referenceType = trim($referenceType);
        $referenceId = trim($referenceId);
        if ($source === '' || $referenceId === '') {
            return;
        }

        $linkUrl = self::normalizeLink($linkUrl) ?? '';
        $stmt = Database::pdo()->prepare(
            'UPDATE portal_notifications
             SET expires_at = NOW()
             WHERE audience = :audience
               AND (expires_at IS NULL OR expires_at > NOW())
               AND (
                    (source = :source AND reference_type = :reference_type AND reference_id = :reference_id)
                    OR (source = :source2 AND link_url = :link_url)
               )'
        );
        $stmt->execute([
            'audience' => self::AUDIENCE_STAFF,
            'source' => $source,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'source2' => $source,
            'link_url' => $linkUrl,
        ]);
    }

    private static function markReadForStaff(string $notificationId, ?string $staffUserId): void
    {
        $notificationId = trim($notificationId);
        $staffUserId = trim((string) $staffUserId);
        if ($notificationId === '' || $staffUserId === '') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notification_reads (notification_id, reader_type, reader_id)
             VALUES (:notification_id, :reader_type, :reader_id)
             ON CONFLICT (notification_id, reader_type, reader_id) DO NOTHING'
        );
        $stmt->execute([
            'notification_id' => $notificationId,
            'reader_type' => self::READER_STAFF,
            'reader_id' => $staffUserId,
        ]);
    }

    /**
     * @param array{reader_type: string, reader_id: string, is_customer: bool, is_staff: bool} $reader
     */
    private static function staffSinceSql(array $reader): string
    {
        return $reader['is_staff'] ? ' AND n.created_at >= :staff_since' : '';
    }

    /**
     * @param array{reader_type: string, reader_id: string, is_customer: bool, is_staff: bool} $reader
     */
    private static function bindStaffSince(\PDOStatement $stmt, array $reader): void
    {
        if (!$reader['is_staff']) {
            return;
        }

        $since = self::staffSinceForCurrentReader($reader['reader_id']);
        $stmt->bindValue(':staff_since', $since ?? '1970-01-01 00:00:00+00');
    }

    private static function staffSinceForCurrentReader(string $staffUserId): ?string
    {
        if (self::$staffSinceCache !== null) {
            return self::$staffSinceCache;
        }

        $staffUserId = trim($staffUserId);
        if ($staffUserId === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT created_at::text FROM web_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $staffUserId]);
        $createdAt = $stmt->fetchColumn();
        self::$staffSinceCache = is_string($createdAt) && $createdAt !== '' ? $createdAt : null;

        return self::$staffSinceCache;
    }
}
