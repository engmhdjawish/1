<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Database;
use PDO;

final class PortalSessionService
{
    private const META_KEY = '_portal_db_session';
    private const ONLINE_MINUTES = 5;
    private const TTL_DAYS = 14;

    private static ?bool $enabled = null;

    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        try {
            $pdo = Database::pdo();
            $staff = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public'
                   AND table_name = 'web_sessions'
                   AND column_name = 'last_seen_at'
                 LIMIT 1"
            )->fetchColumn();
            $customer = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public'
                   AND table_name = 'web_customer_sessions'
                   AND column_name = 'last_seen_at'
                 LIMIT 1"
            )->fetchColumn();
            self::$enabled = $staff && $customer;
        } catch (\Throwable) {
            self::$enabled = false;
        }

        return self::$enabled;
    }

    public static function bootstrap(): void
    {
        if (!self::isEnabled() || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (WebSession::check()) {
            self::ensureStaffSession((string) (WebSession::user()['id'] ?? ''));
            self::touchCurrent();
            return;
        }

        if (CustomerSession::check()) {
            self::ensureCustomerSession((string) (CustomerSession::customer()['id'] ?? ''));
            self::touchCurrent();
            return;
        }

        unset($_SESSION[self::META_KEY]);
    }

    public static function registerStaff(string $userId): void
    {
        $userId = trim($userId);
        if ($userId === '' || !self::isEnabled()) {
            return;
        }

        self::revokeCurrent();
        self::insertSession('staff', $userId);
    }

    public static function registerCustomer(string $customerId): void
    {
        $customerId = trim($customerId);
        if ($customerId === '' || !self::isEnabled()) {
            return;
        }

        self::revokeCurrent();
        self::insertSession('customer', $customerId);
    }

    public static function touchCurrent(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $meta = self::meta();
        if ($meta === null) {
            return;
        }

        $table = ($meta['kind'] ?? '') === 'customer' ? 'web_customer_sessions' : 'web_sessions';
        $stmt = Database::pdo()->prepare(
            "UPDATE {$table}
             SET last_seen_at = NOW(),
                 expires_at = GREATEST(expires_at, NOW() + (:days || ' days')::interval)
             WHERE id = :id
               AND revoked_at IS NULL
               AND expires_at > NOW()"
        );
        $stmt->execute([
            'id' => $meta['id'],
            'days' => (string) self::TTL_DAYS,
        ]);

        if ($stmt->rowCount() < 1) {
            self::forceLogoutForKind((string) ($meta['kind'] ?? ''));
        }
    }

    public static function revokeCurrent(): void
    {
        if (!self::isEnabled()) {
            unset($_SESSION[self::META_KEY]);

            return;
        }

        $meta = self::meta();
        if ($meta !== null) {
            self::revokeById((string) ($meta['kind'] ?? ''), (string) ($meta['id'] ?? ''));
        }

        unset($_SESSION[self::META_KEY]);
    }

    public static function revokeById(string $kind, string $sessionId): bool
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !self::isEnabled()) {
            return false;
        }

        $table = $kind === 'customer' ? 'web_customer_sessions' : 'web_sessions';
        $stmt = Database::pdo()->prepare(
            "UPDATE {$table}
             SET revoked_at = NOW()
             WHERE id = :id
               AND revoked_at IS NULL"
        );
        $stmt->execute(['id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    public static function revokeAllForStaffUser(string $userId): int
    {
        return self::revokeAllForSubject('staff', $userId);
    }

    public static function revokeAllForCustomer(string $customerId): int
    {
        return self::revokeAllForSubject('customer', $customerId);
    }

    public static function revokeAllOnline(string $kind): int
    {
        if (!self::isEnabled()) {
            return 0;
        }

        $table = $kind === 'customer' ? 'web_customer_sessions' : 'web_sessions';
        $stmt = Database::pdo()->prepare(
            "UPDATE {$table}
             SET revoked_at = NOW()
             WHERE revoked_at IS NULL
               AND expires_at > NOW()
               AND last_seen_at >= NOW() - (:minutes || ' minutes')::interval"
        );
        $stmt->execute(['minutes' => (string) self::ONLINE_MINUTES]);

        return $stmt->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public static function onlineStaff(int $minutes = self::ONLINE_MINUTES): array
    {
        return self::onlineRows('staff', $minutes);
    }

    /** @return list<array<string, mixed>> */
    public static function onlineCustomers(int $minutes = self::ONLINE_MINUTES): array
    {
        return self::onlineRows('customer', $minutes);
    }

    public static function onlineCounts(int $minutes = self::ONLINE_MINUTES): array
    {
        return [
            'staff' => count(self::onlineStaff($minutes)),
            'customers' => count(self::onlineCustomers($minutes)),
            'total' => count(self::onlineStaff($minutes)) + count(self::onlineCustomers($minutes)),
        ];
    }

    private static function ensureStaffSession(string $userId): void
    {
        $meta = self::meta();
        if ($meta !== null && ($meta['kind'] ?? '') === 'staff') {
            return;
        }

        self::registerStaff($userId);
    }

    private static function ensureCustomerSession(string $customerId): void
    {
        $meta = self::meta();
        if ($meta !== null && ($meta['kind'] ?? '') === 'customer') {
            return;
        }

        self::registerCustomer($customerId);
    }

    private static function insertSession(string $kind, string $subjectId): void
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            return;
        }

        $hash = hash('sha256', $sessionId);
        $ip = self::clientIp();
        $ua = self::userAgent();
        $pdo = Database::pdo();

        if ($kind === 'customer') {
            $stmt = $pdo->prepare(
                "INSERT INTO web_customer_sessions (
                    customer_id, token_hash, expires_at, created_ip, user_agent, last_seen_at
                 ) VALUES (
                    :subject_id, :hash, NOW() + (:days || ' days')::interval, :ip, :ua, NOW()
                 )
                 RETURNING id::text"
            );
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO web_sessions (
                    user_id, token_hash, expires_at, created_ip, user_agent, last_seen_at
                 ) VALUES (
                    :subject_id, :hash, NOW() + (:days || ' days')::interval, :ip, :ua, NOW()
                 )
                 RETURNING id::text"
            );
        }

        $stmt->execute([
            'subject_id' => $subjectId,
            'hash' => $hash,
            'days' => (string) self::TTL_DAYS,
            'ip' => $ip !== '' ? substr($ip, 0, 45) : null,
            'ua' => $ua !== '' ? substr($ua, 0, 500) : null,
        ]);

        $id = (string) $stmt->fetchColumn();
        if ($id !== '') {
            $_SESSION[self::META_KEY] = ['id' => $id, 'kind' => $kind];
        }
    }

    private static function revokeAllForSubject(string $kind, string $subjectId): int
    {
        $subjectId = trim($subjectId);
        if ($subjectId === '' || !self::isEnabled()) {
            return 0;
        }

        if ($kind === 'customer') {
            $stmt = Database::pdo()->prepare(
                "UPDATE web_customer_sessions
                 SET revoked_at = NOW()
                 WHERE customer_id = :subject_id
                   AND revoked_at IS NULL"
            );
        } else {
            $stmt = Database::pdo()->prepare(
                "UPDATE web_sessions
                 SET revoked_at = NOW()
                 WHERE user_id = :subject_id
                   AND revoked_at IS NULL"
            );
        }
        $stmt->execute(['subject_id' => $subjectId]);

        return $stmt->rowCount();
    }

    /** @return list<array<string, mixed>> */
    private static function onlineRows(string $kind, int $minutes): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $minutes = max(1, min(60, $minutes));

        if ($kind === 'customer') {
            $sql = "SELECT
                        s.id::text AS session_id,
                        'customer' AS kind,
                        c.id::text AS subject_id,
                        c.name_ar AS display_name,
                        c.phone AS login_name,
                        s.created_ip,
                        s.user_agent,
                        s.created_at,
                        s.last_seen_at
                    FROM web_customer_sessions s
                    INNER JOIN web_customers c ON c.id = s.customer_id
                    WHERE s.revoked_at IS NULL
                      AND s.expires_at > NOW()
                      AND s.last_seen_at >= NOW() - (:minutes || ' minutes')::interval
                    ORDER BY s.last_seen_at DESC";
        } else {
            $sql = "SELECT
                        s.id::text AS session_id,
                        'staff' AS kind,
                        u.id::text AS subject_id,
                        COALESCE(u.display_name_ar, u.user_name) AS display_name,
                        u.user_name AS login_name,
                        s.created_ip,
                        s.user_agent,
                        s.created_at,
                        s.last_seen_at
                    FROM web_sessions s
                    INNER JOIN web_users u ON u.id = s.user_id
                    WHERE s.revoked_at IS NULL
                      AND s.expires_at > NOW()
                      AND s.last_seen_at >= NOW() - (:minutes || ' minutes')::interval
                    ORDER BY s.last_seen_at DESC";
        }

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['minutes' => (string) $minutes]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{id: string, kind: string}|null */
    private static function meta(): ?array
    {
        $meta = $_SESSION[self::META_KEY] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        $id = trim((string) ($meta['id'] ?? ''));
        $kind = trim((string) ($meta['kind'] ?? ''));
        if ($id === '' || !in_array($kind, ['staff', 'customer'], true)) {
            return null;
        }

        return ['id' => $id, 'kind' => $kind];
    }

    private static function forceLogoutForKind(string $kind): void
    {
        unset($_SESSION[self::META_KEY]);
        if ($kind === 'customer') {
            CustomerSession::logout();
        } else {
            WebSession::logout();
        }
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $candidate = trim((string) ($_SERVER[$key] ?? ''));
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

    private static function userAgent(): string
    {
        return trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}
