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
            $userId = (string) (WebSession::user()['id'] ?? '');
            if ($userId === '' || !self::assertCurrentSession('staff', $userId)) {
                self::forceLogoutForKind('staff');

                return;
            }
            self::touchCurrent();

            return;
        }

        if (CustomerSession::check()) {
            $customerId = (string) (CustomerSession::customer()['id'] ?? '');
            if ($customerId === '' || !self::assertCurrentSession('customer', $customerId)) {
                self::forceLogoutForKind('customer');

                return;
            }
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::revokeAllForStaffUser($userId);
            unset($_SESSION[self::META_KEY]);
            self::insertSession('staff', $userId);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public static function registerCustomer(string $customerId): void
    {
        $customerId = trim($customerId);
        if ($customerId === '' || !self::isEnabled()) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::revokeAllForCustomer($customerId);
            unset($_SESSION[self::META_KEY]);
            self::insertSession('customer', $customerId);
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
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

        $meta = self::meta();
        if (
            $stmt->rowCount() > 0
            && $meta !== null
            && ($meta['kind'] ?? '') === $kind
            && ($meta['id'] ?? '') === $sessionId
        ) {
            self::forceLogoutForKind($kind);
        }

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
        $staff = self::onlineStaff($minutes);
        $customers = self::onlineCustomers($minutes);
        $guests = PortalPresenceService::isEnabled() ? PortalPresenceService::onlineGuestCount($minutes) : 0;

        return [
            'staff' => count($staff),
            'customers' => count($customers),
            'guests' => $guests,
            'total' => count($staff) + count($customers) + $guests,
        ];
    }

    private static function assertCurrentSession(string $kind, string $subjectId): bool
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            return false;
        }

        $hash = hash('sha256', $sessionId);
        $table = $kind === 'customer' ? 'web_customer_sessions' : 'web_sessions';
        $subjectCol = $kind === 'customer' ? 'customer_id' : 'user_id';

        $stmt = Database::pdo()->prepare(
            "SELECT s.id::text
             FROM {$table} s
             WHERE s.{$subjectCol} = :subject_id
               AND s.token_hash = :hash
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()
               AND s.id = (
                   SELECT s2.id
                   FROM {$table} s2
                   WHERE s2.{$subjectCol} = :subject_id2
                     AND s2.revoked_at IS NULL
                     AND s2.expires_at > NOW()
                   ORDER BY s2.last_seen_at DESC NULLS LAST, s2.created_at DESC
                   LIMIT 1
               )
             LIMIT 1"
        );
        $stmt->execute([
            'subject_id' => $subjectId,
            'subject_id2' => $subjectId,
            'hash' => $hash,
        ]);
        $id = $stmt->fetchColumn();
        if ($id === false || $id === '') {
            return false;
        }

        $_SESSION[self::META_KEY] = ['id' => (string) $id, 'kind' => $kind];

        return true;
    }

    private static function validateCurrentMeta(string $expectedKind): bool
    {
        $meta = self::meta();
        if ($meta === null || ($meta['kind'] ?? '') !== $expectedKind) {
            return false;
        }

        if (!self::isEnabled()) {
            return false;
        }

        $table = $expectedKind === 'customer' ? 'web_customer_sessions' : 'web_sessions';
        $stmt = Database::pdo()->prepare(
            "SELECT 1 FROM {$table}
             WHERE id = :id
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute(['id' => $meta['id']]);

        return (bool) $stmt->fetchColumn();
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
            $sql = "INSERT INTO web_customer_sessions (
                        customer_id, token_hash, expires_at, created_ip, user_agent, last_seen_at
                    ) VALUES (
                        :subject_id, :hash, NOW() + (:days || ' days')::interval, :ip, :ua, NOW()
                    )
                    ON CONFLICT (token_hash) DO UPDATE SET
                        customer_id = EXCLUDED.customer_id,
                        expires_at = EXCLUDED.expires_at,
                        created_ip = EXCLUDED.created_ip,
                        user_agent = EXCLUDED.user_agent,
                        last_seen_at = NOW(),
                        revoked_at = NULL
                    RETURNING id::text";
        } else {
            $sql = "INSERT INTO web_sessions (
                        user_id, token_hash, expires_at, created_ip, user_agent, last_seen_at
                    ) VALUES (
                        :subject_id, :hash, NOW() + (:days || ' days')::interval, :ip, :ua, NOW()
                    )
                    ON CONFLICT (token_hash) DO UPDATE SET
                        user_id = EXCLUDED.user_id,
                        expires_at = EXCLUDED.expires_at,
                        created_ip = EXCLUDED.created_ip,
                        user_agent = EXCLUDED.user_agent,
                        last_seen_at = NOW(),
                        revoked_at = NULL
                    RETURNING id::text";
        }

        $stmt = $pdo->prepare($sql);
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
            unset($_SESSION['web_customer']);
        } else {
            unset($_SESSION['web_user']);
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
