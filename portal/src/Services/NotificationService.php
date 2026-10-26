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
    public const REF_TYPE_SPECIAL_OFFER = 'special_offer';

    private const SESSION_GUEST_READER_KEY = 'portal_notification_guest_id';
    private const STAFF_ALERT_TTL_DAYS = 7;

    /** @var string|null */
    private static ?string $staffSinceCache = null;

    /** @var array<string, array{0: string, 1: string}> */
    private static array $pushExclusions = [];

    private const SCHEMA_MARKER_KEY = 'notifications_schema_v5';

    private static bool $schemaReady = false;

    /**
     * Schema upkeep (some statements take table locks) — once per request and then once a day,
     * not on every bell poll.
     */
    public static function ensureTable(): void
    {
        if (self::$schemaReady) {
            return;
        }
        try {
            if (\Portal\Support\ResponseCache::get(self::SCHEMA_MARKER_KEY) !== null) {
                self::$schemaReady = true;

                return;
            }
        } catch (\Throwable) {
        }
        self::applySchema();
        self::$schemaReady = true;
        try {
            \Portal\Support\ResponseCache::set(self::SCHEMA_MARKER_KEY, ['at' => time()], 86400);
        } catch (\Throwable) {
        }
    }

    private static function applySchema(): void
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
        try {
            Database::pdo()->exec(
                "ALTER TABLE portal_notification_reads
                 ADD COLUMN IF NOT EXISTS view_via VARCHAR(16) NOT NULL DEFAULT 'web'"
            );
        } catch (\Throwable) {
            // Best-effort on older installs.
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
    public static function listForReader(int $limit = 30, int $offset = 0, string $category = ''): array
    {
        self::ensureTable();
        $reader = self::currentReader();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $categorySql = '';
        $categorySources = self::CATEGORY_SOURCES[$category] ?? null;
        if ($category === 'news') {
            $categorySql = ' AND n.source NOT IN (' . self::quotedSources(array_merge(...array_values(self::CATEGORY_SOURCES))) . ')';
        } elseif ($categorySources !== null) {
            $categorySql = ' AND n.source IN (' . self::quotedSources($categorySources) . ')';
        }
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
                       (r.read_at IS NOT NULL)::int AS is_read
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND (r.dismissed_at IS NULL)' . self::staffSinceSql($reader) . $categorySql . '
                ORDER BY n.created_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        self::bindStaffSince($stmt, $reader);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['icon'] = self::sanitizeIcon((string) ($row['icon'] ?? ''), 'notifications');
            $row['is_read'] = (int) ($row['is_read'] ?? 0);
            $row['category'] = self::category((string) ($row['source'] ?? ''));
        }
        unset($row);

        return $rows;
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

    public static function normalizeViewVia(string $via): string
    {
        $via = strtolower(trim($via));

        return in_array($via, ['web', 'push'], true) ? $via : 'web';
    }

    public static function markRead(string $notificationId, string $viewVia = 'web'): bool
    {
        self::ensureTable();
        $notificationId = trim($notificationId);
        if (!self::isUuid($notificationId)) {
            return false;
        }

        $reader = self::currentReader();
        if (!self::readerCanSee($notificationId, $reader)) {
            return false;
        }

        $viewVia = self::normalizeViewVia($viewVia);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notification_reads (notification_id, reader_type, reader_id, view_via)
             VALUES (:notification_id, :reader_type, :reader_id, :view_via)
             ON CONFLICT (notification_id, reader_type, reader_id)
             DO UPDATE SET
                read_at = COALESCE(portal_notification_reads.read_at, NOW()),
                view_via = CASE
                    WHEN portal_notification_reads.view_via = \'push\' THEN portal_notification_reads.view_via
                    ELSE EXCLUDED.view_via
                END'
        );
        $stmt->execute([
            'notification_id' => $notificationId,
            'reader_type' => $reader['reader_type'],
            'reader_id' => $reader['reader_id'],
            'view_via' => $viewVia,
        ]);

        return true;
    }

    /**
     * Who opened/clicked this notification (web bell/toast or mobile push).
     *
     * @return list<array{
     *   reader_type: string,
     *   reader_id: string,
     *   name_ar: string,
     *   detail_ar: string,
     *   view_via: string,
     *   view_via_label: string,
     *   read_at: string|null,
     *   read_at_fmt: string
     * }>
     */
    public static function listReadersForNotification(string $notificationId, int $limit = 100): array
    {
        self::ensureTable();
        $notificationId = trim($notificationId);
        if (!self::isUuid($notificationId)) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        $stmt = Database::pdo()->prepare(
            "SELECT r.reader_type,
                    r.reader_id,
                    r.view_via,
                    r.read_at,
                    CASE
                      WHEN r.reader_type = 'customer' THEN COALESCE(wc.name_ar, 'عميل')
                      WHEN r.reader_type = 'staff' THEN COALESCE(wu.display_name_ar, wu.user_name, 'موظف')
                      ELSE 'زائر'
                    END AS name_ar,
                    CASE
                      WHEN r.reader_type = 'customer' THEN COALESCE(wc.phone, '')
                      WHEN r.reader_type = 'staff' THEN COALESCE(wu.user_name, '')
                      ELSE LEFT(r.reader_id, 8)
                    END AS detail_ar
             FROM portal_notification_reads r
             LEFT JOIN web_customers wc
               ON r.reader_type = 'customer' AND wc.id::text = r.reader_id
             LEFT JOIN web_users wu
               ON r.reader_type = 'staff' AND wu.id::text = r.reader_id
             WHERE r.notification_id = :notification_id
               AND r.read_at IS NOT NULL
             ORDER BY r.read_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':notification_id', $notificationId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $via = self::normalizeViewVia((string) ($row['view_via'] ?? 'web'));
            $readAt = isset($row['read_at']) ? (string) $row['read_at'] : null;
            $fmt = '';
            if ($readAt !== null && $readAt !== '') {
                try {
                    $fmt = (new \DateTimeImmutable($readAt))
                        ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                        ->format('Y/m/d H:i');
                } catch (\Throwable) {
                    $fmt = $readAt;
                }
            }
            $out[] = [
                'reader_type' => (string) ($row['reader_type'] ?? ''),
                'reader_id' => (string) ($row['reader_id'] ?? ''),
                'name_ar' => (string) ($row['name_ar'] ?? 'مستخدم'),
                'detail_ar' => (string) ($row['detail_ar'] ?? ''),
                'view_via' => $via,
                'view_via_label' => $via === 'push' ? 'من إشعار الجهاز' : 'من الموقع',
                'read_at' => $readAt,
                'read_at_fmt' => $fmt,
            ];
        }

        return $out;
    }

    public static function dismissForReader(string $notificationId): bool
    {
        self::ensureTable();
        $notificationId = trim($notificationId);
        if (!self::isUuid($notificationId)) {
            return false;
        }

        $reader = self::currentReader();
        if (!self::readerCanSee($notificationId, $reader)) {
            return false;
        }

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
        if (!self::isUuid($notificationId)) {
            return false;
        }

        $stmt = Database::pdo()->prepare('DELETE FROM portal_notifications WHERE id = :id');
        $stmt->execute(['id' => $notificationId]);

        return $stmt->rowCount() > 0;
    }

    public static function markAllRead(): int
    {
        self::ensureTable();
        $reader = self::currentReader();
        $conditions = self::visibilitySql($reader);
        $sql = 'INSERT INTO portal_notification_reads (notification_id, reader_type, reader_id)
                SELECT n.id, :insert_reader_type, :insert_reader_id
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND (r.dismissed_at IS NULL)
                  AND r.read_at IS NULL' . self::staffSinceSql($reader) . '
                ON CONFLICT (notification_id, reader_type, reader_id) DO NOTHING';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':insert_reader_type', $reader['reader_type']);
        $stmt->bindValue(':insert_reader_id', $reader['reader_id']);
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        self::bindStaffSince($stmt, $reader);
        $stmt->execute();

        return $stmt->rowCount();
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

        [$title, $body] = self::requireCopy($title, $body);
        $referenceType = self::blankToNull($referenceType);
        $referenceId = self::blankToNull($referenceId);

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
            'title' => $title,
            'body' => $body,
            'link_url' => self::normalizeLinkUrl($linkUrl),
            'icon' => self::sanitizeIcon($icon, 'campaign'),
            'source' => self::clipText($source !== '' ? $source : 'manual', 50),
            'created_by' => self::blankToNull($createdByUserId),
            'expires_at' => self::normalizeExpiresAt($expiresAt),
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
                $exclude = self::$pushExclusions[$notificationId] ?? null;
                WebPushService::sendForNotificationId($notificationId, $exclude);
            } catch (\Throwable) {
                // Never block notification persistence or the HTTP response.
            }
        });
    }

    public static function excludePushRecipient(string $notificationId, string $readerType, string $readerId): void
    {
        $notificationId = trim($notificationId);
        $readerId = trim($readerId);
        if ($notificationId === '' || $readerId === '') {
            return;
        }
        self::$pushExclusions[$notificationId] = [$readerType, $readerId];
    }

    public static function createPrivateForCustomer(
        string $customerId,
        string $title,
        string $body,
        ?string $linkUrl = null,
        string $icon = 'notifications',
        string $source = 'system',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $createdByUserId = null
    ): string {
        self::ensureTable();
        $customerId = trim($customerId);
        if (!self::isUuid($customerId)) {
            throw new \InvalidArgumentException('معرّف العميل غير صالح.');
        }
        [$title, $body] = self::requireCopy($title, $body);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notifications (
                scope, audience, title_ar, body_ar, link_url, icon, source,
                recipient_web_customer_id, created_by_web_user_id, reference_type, reference_id
             ) VALUES (
                :scope, :audience, :title, :body, :link_url, :icon, :source,
                :customer_id, :created_by, :reference_type, :reference_id
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'scope' => self::SCOPE_PRIVATE,
            'audience' => self::AUDIENCE_CUSTOMERS,
            'title' => $title,
            'body' => $body,
            'link_url' => self::normalizeLinkUrl($linkUrl),
            'icon' => self::sanitizeIcon($icon, 'notifications'),
            'source' => self::clipText($source !== '' ? $source : 'system', 50),
            'customer_id' => $customerId,
            'created_by' => self::blankToNull($createdByUserId),
            'reference_type' => self::blankToNull($referenceType),
            'reference_id' => self::blankToNull($referenceId),
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
        string $source = 'system',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $createdByUserId = null
    ): string {
        self::ensureTable();
        $userId = trim($userId);
        if (!self::isUuid($userId)) {
            throw new \InvalidArgumentException('معرّف الموظف غير صالح.');
        }
        [$title, $body] = self::requireCopy($title, $body);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_notifications (
                scope, audience, title_ar, body_ar, link_url, icon, source,
                recipient_web_user_id, created_by_web_user_id, reference_type, reference_id
             ) VALUES (
                :scope, :audience, :title, :body, :link_url, :icon, :source,
                :user_id, :created_by, :reference_type, :reference_id
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'scope' => self::SCOPE_PRIVATE,
            'audience' => self::AUDIENCE_STAFF,
            'title' => $title,
            'body' => $body,
            'link_url' => self::normalizeLinkUrl($linkUrl),
            'icon' => self::sanitizeIcon($icon, 'notifications'),
            'source' => self::clipText($source !== '' ? $source : 'system', 50),
            'user_id' => $userId,
            'created_by' => self::blankToNull($createdByUserId),
            'reference_type' => self::blankToNull($referenceType),
            'reference_id' => self::blankToNull($referenceId),
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
                    n.reference_type,
                    n.reference_id,
                    wc.name_ar AS customer_name_ar,
                    wu.display_name_ar AS staff_name_ar,
                    COALESCE(reads.read_count, 0)::int AS read_count
             FROM portal_notifications n
             LEFT JOIN web_customers wc ON wc.id = n.recipient_web_customer_id
             LEFT JOIN web_users wu ON wu.id = n.recipient_web_user_id
             LEFT JOIN (
                SELECT notification_id, COUNT(*)::int AS read_count
                FROM portal_notification_reads
                GROUP BY notification_id
             ) reads ON reads.notification_id = n.id
             ORDER BY n.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Same manual title+body sent in the last two minutes — a double click on «إرسال». */
    public static function isRecentManualDuplicate(string $title, string $body): bool
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare(
            "SELECT 1 FROM portal_notifications
             WHERE source = 'manual' AND title_ar = :title AND body_ar = :body
               AND created_at > NOW() - INTERVAL '2 minutes'
             LIMIT 1"
        );
        $stmt->execute(['title' => trim($title), 'body' => trim($body)]);

        return $stmt->fetchColumn() !== false;
    }

    /** Reader-facing groups. Anything not listed (manual campaigns, system) is «news». */
    public const CATEGORY_SOURCES = [
        'orders' => ['order_status', 'order_received', 'order_adjusted', 'new_order', 'order_handled', 'order_item_cancelled'],
        'offers' => ['special_offer'],
        'account' => ['customer_approved', 'customer_pending', 'customer_rejected', 'customer_suspended', 'customer_reactivated', 'customer_created', 'password_changed', 'new_registration', 'registration_handled'],
    ];

    public const CATEGORY_LABELS = [
        'orders' => 'الطلبات',
        'offers' => 'العروض',
        'account' => 'الحساب',
        'news' => 'إعلانات',
    ];

    public static function category(string $source): string
    {
        $source = trim($source);
        foreach (self::CATEGORY_SOURCES as $category => $sources) {
            if (in_array($source, $sources, true)) {
                return $category;
            }
        }

        return 'news';
    }

    /** @param list<string> $sources */
    private static function quotedSources(array $sources): string
    {
        return implode(', ', array_map(
            static fn (string $source): string => "'" . preg_replace('/[^a-z_]/', '', $source) . "'",
            $sources
        ));
    }

    /**
     * Dashboard history, filtered and paged in SQL so automatic order alerts don't bury campaigns.
     *
     * @param array{kind?: string, scope?: string, q?: string} $filters kind: manual|auto|all
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function listSentPage(array $filters, int $page = 1, int $perPage = 20): array
    {
        self::ensureTable();
        $perPage = max(5, min(100, $perPage));
        $where = ['TRUE'];
        $params = [];
        $kind = (string) ($filters['kind'] ?? 'manual');
        if ($kind === 'manual') {
            $where[] = "n.source = 'manual'";
        } elseif ($kind === 'auto') {
            $where[] = "n.source <> 'manual'";
        }
        $scope = (string) ($filters['scope'] ?? '');
        if (in_array($scope, [self::SCOPE_PUBLIC, self::SCOPE_PRIVATE], true)) {
            $where[] = 'n.scope = :scope';
            $params[':scope'] = $scope;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(n.title_ar ILIKE :q OR n.body_ar ILIKE :q OR COALESCE(wc.name_ar, '') ILIKE :q OR COALESCE(wc.phone, '') ILIKE :q OR COALESCE(wu.display_name_ar, '') ILIKE :q)";
            $params[':q'] = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
        }
        $whereSql = implode(' AND ', $where);
        $fromCount = ' FROM portal_notifications n
             LEFT JOIN web_customers wc ON wc.id = n.recipient_web_customer_id
             LEFT JOIN web_users wu ON wu.id = n.recipient_web_user_id
             WHERE ' . $whereSql;
        $fromList = ' FROM portal_notifications n
             LEFT JOIN web_customers wc ON wc.id = n.recipient_web_customer_id
             LEFT JOIN web_users wu ON wu.id = n.recipient_web_user_id
             LEFT JOIN web_users creator ON creator.id = n.created_by_web_user_id
             WHERE ' . $whereSql;

        $countStmt = Database::pdo()->prepare('SELECT COUNT(*)::int' . $fromCount);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        $stmt = Database::pdo()->prepare(
            'SELECT n.id::text AS id, n.scope, n.audience, n.title_ar, n.body_ar, n.link_url, n.icon, n.source,
                    n.created_at, n.expires_at, n.reference_type, n.reference_id,
                    n.created_by_web_user_id::text AS created_by_web_user_id,
                    wc.name_ar AS customer_name_ar, wu.display_name_ar AS staff_name_ar,
                    creator.display_name_ar AS creator_name_ar,
                    (SELECT COUNT(*)::int FROM portal_notification_reads r WHERE r.notification_id = n.id) AS read_count,
                    (SELECT COUNT(*)::int FROM portal_notification_reads r WHERE r.notification_id = n.id AND r.dismissed_at IS NOT NULL) AS dismiss_count'
            . $fromList . '
             ORDER BY n.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{
     *   total: int,
     *   public_count: int,
     *   private_count: int,
     *   manual_count: int,
     *   auto_count: int,
     *   expired_count: int,
     *   active_count: int,
     *   read_total: int,
     *   dismiss_total: int
     * }
     */
    public static function sentStats(): array
    {
        self::ensureTable();
        $row = Database::pdo()->query(
            "SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE scope = 'public')::int AS public_count,
                COUNT(*) FILTER (WHERE scope = 'private')::int AS private_count,
                COUNT(*) FILTER (WHERE source = 'manual')::int AS manual_count,
                COUNT(*) FILTER (WHERE source <> 'manual')::int AS auto_count,
                COUNT(*) FILTER (WHERE expires_at IS NOT NULL AND expires_at <= NOW())::int AS expired_count,
                COUNT(*) FILTER (WHERE expires_at IS NULL OR expires_at > NOW())::int AS active_count
             FROM portal_notifications"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $reads = Database::pdo()->query(
            "SELECT
                COUNT(*)::int AS read_total,
                COUNT(*) FILTER (WHERE dismissed_at IS NOT NULL)::int AS dismiss_total
             FROM portal_notification_reads"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'public_count' => (int) ($row['public_count'] ?? 0),
            'private_count' => (int) ($row['private_count'] ?? 0),
            'manual_count' => (int) ($row['manual_count'] ?? 0),
            'auto_count' => (int) ($row['auto_count'] ?? 0),
            'expired_count' => (int) ($row['expired_count'] ?? 0),
            'active_count' => (int) ($row['active_count'] ?? 0),
            'read_total' => (int) ($reads['read_total'] ?? 0),
            'dismiss_total' => (int) ($reads['dismiss_total'] ?? 0),
        ];
    }

    /**
     * Approximate audience size for compose UI (accounts + device push).
     *
     * @return array{
     *   customers_active: int,
     *   customers_pending: int,
     *   staff_active: int,
     *   push: array{total: int, guest: int, customer: int, staff: int}
     * }
     */
    public static function audienceReach(): array
    {
        $customersActive = 0;
        $customersPending = 0;
        $staffActive = 0;
        try {
            $customersActive = (int) Database::pdo()->query(
                "SELECT COUNT(*)::int FROM web_customers WHERE status = 'active'"
            )->fetchColumn();
        } catch (\Throwable) {
            $customersActive = 0;
        }
        try {
            $customersPending = (int) Database::pdo()->query(
                "SELECT COUNT(*)::int FROM web_customers WHERE status = 'pending'"
            )->fetchColumn();
        } catch (\Throwable) {
            $customersPending = 0;
        }
        try {
            $staffActive = (int) Database::pdo()->query(
                'SELECT COUNT(*)::int FROM web_users WHERE is_active = TRUE'
            )->fetchColumn();
        } catch (\Throwable) {
            $staffActive = 0;
        }

        $push = [
            'total' => 0,
            'guest' => 0,
            'customer' => 0,
            'staff' => 0,
        ];
        try {
            $push = PushSubscriptionService::countsByReaderType();
        } catch (\Throwable) {
            /* push table may be missing on older installs */
        }

        return [
            'customers_active' => $customersActive,
            'customers_pending' => $customersPending,
            'staff_active' => $staffActive,
            'push' => $push,
        ];
    }

    /** Built-in compose templates for the dashboard hub. */
    public static function composeTemplates(): array
    {
        return [
            [
                'label' => 'بضاعة جديدة',
                'icon' => 'storefront',
                'title' => 'وصلت بضاعة جديدة',
                'body' => 'تشكيلة جديدة متوفرة الآن بأسعار الجملة. تصفّح واطلب قبل نفاد الكمية.',
                'link' => '/store.php',
            ],
            [
                'label' => 'عرض',
                'icon' => 'local_offer',
                'title' => 'عرض خاص لفترة محدودة',
                'body' => 'خصم على تشكيلة مختارة عند الطلب بالطرد. العرض ساري حتى نفاد الكمية.',
                'link' => '/store.php',
            ],
            [
                'label' => 'تحديث أسعار',
                'icon' => 'info',
                'title' => 'تحديث الأسعار',
                'body' => 'تم تحديث أسعار بعض الأصناف. راجع الأسعار الحالية قبل إرسال طلبك.',
                'link' => '/store.php',
            ],
            [
                'label' => 'عطلة',
                'icon' => 'celebration',
                'title' => 'تنويه بموعد العطلة',
                'body' => 'نستقبل الطلبات عبر الموقع، ويبدأ التجهيز بعد انتهاء العطلة.',
                'link' => '',
            ],
            [
                'label' => 'تذكير طلب',
                'icon' => 'shopping_bag',
                'title' => 'هل أكملت طلبك؟',
                'body' => 'لديك أصناف في السلة. أكمل الطلب الآن لضمان التجهيز بأسرع وقت.',
                'link' => '/store-cart.php',
            ],
            [
                'label' => 'ترحيب',
                'icon' => 'verified',
                'title' => 'مرحباً بك في متجر الجملة',
                'body' => 'حسابك جاهز. تصفّح التشكيلة وأرسل طلبك مباشرة من الموقع.',
                'link' => '/store.php',
            ],
        ];
    }

    public static function notifyOrderStatusChanged(string $orderId, string $nextStatus, bool $notifyCustomer = true): void
    {
        if (!$notifyCustomer) {
            return;
        }

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

        $link = self::customerOrderLink($order);

        $customerId = trim((string) ($order['web_customer_id'] ?? ''));
        if ($customerId === '') {
            return;
        }

        self::createPrivateForCustomer(
            $customerId,
            $title,
            $body,
            $link,
            'shopping_bag',
            'order_status',
            self::REF_TYPE_ORDER,
            $orderId
        );
    }

    public static function notifyCustomerOrderPlaced(string $orderId): void
    {
        try {
            $order = self::loadOrderForNotice($orderId);
            if ($order === null) {
                return;
            }
            $customerId = trim((string) ($order['web_customer_id'] ?? ''));
            if ($customerId === '') {
                return;
            }
            $orderNumber = trim((string) ($order['order_number'] ?? ''));
            self::createPrivateForCustomer(
                $customerId,
                'تم استلام طلبك',
                'طلبك رقم ' . $orderNumber . ' وصل إلينا وهو الآن قيد المراجعة.',
                self::customerOrderLink($order),
                'receipt_long',
                'order_received',
                self::REF_TYPE_ORDER,
                $orderId
            );
        } catch (\Throwable) {
        }
    }

    public static function notifyCustomerOrderAdjusted(string $orderId, string $summary): void
    {
        try {
            $summary = trim($summary);
            if ($summary === '') {
                return;
            }
            $order = self::loadOrderForNotice($orderId);
            if ($order === null) {
                return;
            }
            $customerId = trim((string) ($order['web_customer_id'] ?? ''));
            if ($customerId === '') {
                return;
            }
            $orderNumber = trim((string) ($order['order_number'] ?? ''));
            self::createPrivateForCustomer(
                $customerId,
                'تم تعديل طلبك',
                'طلب رقم ' . $orderNumber . ': ' . $summary,
                self::customerOrderLink($order),
                'edit_note',
                'order_adjusted',
                self::REF_TYPE_ORDER,
                $orderId
            );
        } catch (\Throwable) {
        }
    }

    public static function notifyStaffCustomerCancelledItem(string $orderId, string $itemName, string $customerName = ''): void
    {
        try {
            $orderId = trim($orderId);
            $itemName = trim($itemName);
            if ($orderId === '' || $itemName === '') {
                return;
            }

            $order = self::loadOrderForNotice($orderId);
            $orderNumber = trim((string) ($order['order_number'] ?? ''));
            $body = 'أُلغي الصنف «' . $itemName . '»';
            if ($orderNumber !== '') {
                $body .= ' من الطلب ' . $orderNumber;
            }
            $customerName = trim($customerName);
            if ($customerName === '' && is_array($order)) {
                $customerName = trim((string) ($order['customer_name_ar'] ?? $order['guest_name_ar'] ?? ''));
            }
            if ($customerName !== '') {
                $body .= ' · ' . $customerName;
            }

            self::createPublic(
                'إلغاء صنف من العميل',
                $body,
                self::AUDIENCE_STAFF,
                '/dashboard/orders.php?details=' . rawurlencode($orderId),
                'remove_shopping_cart',
                self::defaultStaffAlertExpiresAt(),
                null,
                'order_item_cancelled',
                self::REF_TYPE_ORDER,
                $orderId
            );
        } catch (\Throwable) {
        }
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
            'customer_rejected',
            self::REF_TYPE_CUSTOMER_REGISTRATION,
            $customerId
        );
    }

    public static function notifyCustomerSuspended(string $customerId, string $reason = ''): void
    {
        try {
            $body = 'تم إيقاف حسابك مؤقتاً. لن تتمكن من تسجيل الدخول حتى يعيد الفريق تفعيله.';
            if (trim($reason) !== '') {
                $body .= ' السبب: ' . trim($reason);
            }
            self::createPrivateForCustomer(
                $customerId,
                'تم إيقاف الحساب',
                $body,
                '/customer-login.php',
                'block',
                'customer_suspended'
            );
        } catch (\Throwable) {
        }
    }

    public static function notifyCustomerReactivated(string $customerId): void
    {
        try {
            self::createPrivateForCustomer(
                $customerId,
                'تم إعادة تفعيل حسابك',
                'يمكنك الآن تسجيل الدخول وتصفح المتجر وإتمام الطلبات.',
                '/store.php',
                'verified',
                'customer_reactivated'
            );
        } catch (\Throwable) {
        }
    }

    public static function notifyCustomerAccountCreated(string $customerId, bool $active): void
    {
        try {
            if ($active) {
                self::createPrivateForCustomer(
                    $customerId,
                    'تم إنشاء حسابك',
                    'أنشأ فريق المتجر حسابك وقام بتفعيله. يمكنك تسجيل الدخول برقم هاتفك.',
                    '/customer-login.php',
                    'person_add',
                    'customer_created'
                );

                return;
            }

            self::notifyCustomerRegistrationPending($customerId);
        } catch (\Throwable) {
        }
    }

    public static function notifyCustomerPasswordChanged(string $customerId): void
    {
        try {
            self::createPrivateForCustomer(
                $customerId,
                'تم تغيير كلمة المرور',
                'تم تحديث كلمة مرور حسابك. إذا لم تقم بهذا التغيير فتواصل مع المتجر فوراً.',
                '/my-profile.php',
                'key',
                'password_changed'
            );
        } catch (\Throwable) {
        }
    }

    /** @param array<string, mixed> $offer */
    public static function syncSpecialOfferNotification(array $offer): void
    {
        try {
            self::ensureTable();
            $offerId = trim((string) ($offer['id'] ?? ''));
            if ($offerId === '') {
                return;
            }

            $active = self::isTruthy($offer['is_active'] ?? false);
            if (!$active) {
                self::resolveReferenceAlert('special_offer', self::REF_TYPE_SPECIAL_OFFER, $offerId);

                return;
            }

            $endsAt = trim((string) ($offer['ends_at'] ?? ''));
            if ($endsAt !== '') {
                try {
                    if (new \DateTimeImmutable($endsAt) <= new \DateTimeImmutable('now')) {
                        self::resolveReferenceAlert('special_offer', self::REF_TYPE_SPECIAL_OFFER, $offerId);

                        return;
                    }
                } catch (\Throwable) {
                }
            }

            if (self::hasOpenReference('special_offer', self::REF_TYPE_SPECIAL_OFFER, $offerId)) {
                return;
            }

            $title = trim((string) ($offer['title_ar'] ?? ''));
            if ($title === '') {
                return;
            }
            $subtitle = trim((string) ($offer['subtitle_ar'] ?? ''));
            $body = $subtitle !== '' ? $subtitle : 'يتوفر الآن عرض جديد في المتجر. تصفحه قبل انتهائه.';
            $slug = trim((string) ($offer['slug'] ?? ''));
            $link = $slug !== '' ? '/store.php?offer=' . rawurlencode($slug) : '/store.php';
            $expires = $endsAt !== '' ? $endsAt : (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:sP');

            self::createPublic(
                'عرض خاص: ' . $title,
                $body,
                self::AUDIENCE_ALL,
                $link,
                'local_offer',
                $expires,
                null,
                'special_offer',
                self::REF_TYPE_SPECIAL_OFFER,
                $offerId
            );
        } catch (\Throwable) {
        }
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
            self::excludePushRecipient($notificationId, self::READER_STAFF, (string) $handlerUserId);
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
            self::excludePushRecipient($notificationId, self::READER_STAFF, (string) $handlerUserId);
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

    public static function normalizeLinkUrl(?string $linkUrl): ?string
    {
        $linkUrl = trim((string) $linkUrl);
        if ($linkUrl === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $linkUrl) === 1) {
            throw new \InvalidArgumentException('الرابط غير صالح.');
        }
        if (preg_match('#^(?:javascript|data|vbscript):#i', $linkUrl) === 1 || str_starts_with($linkUrl, '//')) {
            throw new \InvalidArgumentException('الرابط غير مسموح.');
        }
        if (preg_match('#^https?://#i', $linkUrl) === 1) {
            $parts = parse_url($linkUrl);
            if (!is_array($parts) || empty($parts['host'])) {
                throw new \InvalidArgumentException('الرابط غير صالح.');
            }

            return $linkUrl;
        }
        if (!str_starts_with($linkUrl, '/')) {
            $linkUrl = '/' . $linkUrl;
        }
        if (str_contains($linkUrl, '://') || str_starts_with($linkUrl, '//')) {
            throw new \InvalidArgumentException('الرابط غير مسموح.');
        }

        return $linkUrl;
    }

    public static function sanitizeIcon(string $icon, string $fallback = 'notifications'): string
    {
        $icon = strtolower(trim($icon));
        $fallback = strtolower(trim($fallback));
        if (preg_match('/^[a-z0-9_]{1,50}$/', $fallback) !== 1) {
            $fallback = 'notifications';
        }
        if (preg_match('/^[a-z0-9_]{1,50}$/', $icon) !== 1) {
            return $fallback;
        }

        return $icon;
    }

    public static function normalizeExpiresAt(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('تاريخ الانتهاء غير صالح.');
        }

        return $dt->format('Y-m-d H:i:sP');
    }

    public static function activityIsNewer(?string $latest, ?string $since): bool
    {
        $latest = trim((string) $latest);
        $since = trim((string) $since);
        if ($latest === '' || $since === '') {
            return false;
        }
        try {
            return new \DateTimeImmutable($latest) > new \DateTimeImmutable($since);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function sourceLabel(string $source): string
    {
        return match (trim($source)) {
            'manual' => 'يدوي',
            'order_status' => 'حالة طلب',
            'order_received' => 'استلام طلب',
            'order_adjusted' => 'تعديل طلب',
            'new_order' => 'طلب جديد',
            'order_handled' => 'متابعة طلب',
            'order_item_cancelled' => 'إلغاء صنف',
            'customer_approved' => 'تفعيل حساب',
            'customer_pending' => 'تسجيل قيد المراجعة',
            'customer_rejected' => 'رفض تسجيل',
            'customer_suspended' => 'إيقاف حساب',
            'customer_reactivated' => 'إعادة تفعيل',
            'customer_created' => 'إنشاء حساب',
            'password_changed' => 'كلمة المرور',
            'new_registration' => 'تسجيل عميل',
            'registration_handled' => 'متابعة تسجيل',
            'special_offer' => 'عرض خاص',
            'system' => 'النظام',
            default => trim($source) !== '' ? trim($source) : 'النظام',
        };
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

        $linkUrl = self::normalizeLinkUrl($linkUrl) ?? '';
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

    /**
     * @param array{reader_type: string, reader_id: string, is_customer: bool, is_staff: bool} $reader
     */
    private static function readerCanSee(string $notificationId, array $reader): bool
    {
        $notificationId = trim($notificationId);
        if ($notificationId === '' || $reader['reader_id'] === '') {
            return false;
        }

        $conditions = self::visibilitySql($reader);
        $sql = 'SELECT 1
                FROM portal_notifications n
                LEFT JOIN portal_notification_reads r
                  ON r.notification_id = n.id
                 AND r.reader_type = :reader_type
                 AND r.reader_id = :reader_id
                WHERE n.id = :id
                  AND (' . implode(' OR ', $conditions['parts']) . ')
                  AND (n.expires_at IS NULL OR n.expires_at > NOW())
                  AND (r.dismissed_at IS NULL)' . self::staffSinceSql($reader) . '
                LIMIT 1';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($conditions['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':reader_type', $reader['reader_type']);
        $stmt->bindValue(':reader_id', $reader['reader_id']);
        $stmt->bindValue(':id', $notificationId);
        self::bindStaffSince($stmt, $reader);
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }

    /** @return array{0: string, 1: string} */
    private static function requireCopy(string $title, string $body): array
    {
        $title = self::clipText(trim($title), 200);
        $body = self::clipText(trim($body), 4000);
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('العنوان والنص مطلوبان.');
        }

        return [$title, $body];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function clipText(string $value, int $max): string
    {
        if ($max < 1) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    /** @param array<string, mixed> $order */
    private static function customerOrderLink(array $order): string
    {
        $token = trim((string) ($order['quote_access_token'] ?? ''));
        if ($token !== '') {
            return '/track-order.php?token=' . rawurlencode($token);
        }

        return '/my-orders.php';
    }

    /** @return array<string, mixed>|null */
    private static function loadOrderForNotice(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        return OrderService::getOrderDetails($orderId);
    }

    private static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private static function hasOpenReference(string $source, string $referenceType, string $referenceId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1
             FROM portal_notifications
             WHERE source = :source
               AND reference_type = :reference_type
               AND reference_id = :reference_id
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([
            'source' => $source,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private static function resolveReferenceAlert(string $source, string $referenceType, string $referenceId): void
    {
        $source = trim($source);
        $referenceType = trim($referenceType);
        $referenceId = trim($referenceId);
        if ($source === '' || $referenceId === '') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE portal_notifications
             SET expires_at = NOW()
             WHERE source = :source
               AND reference_type = :reference_type
               AND reference_id = :reference_id
               AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $stmt->execute([
            'source' => $source,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
