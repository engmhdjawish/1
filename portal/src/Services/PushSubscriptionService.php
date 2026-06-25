<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class PushSubscriptionService
{
    public static function ensureTable(): void
    {
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS portal_push_subscriptions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                reader_type VARCHAR(16) NOT NULL CHECK (reader_type IN ('guest', 'customer', 'staff')),
                reader_id VARCHAR(64) NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                user_agent VARCHAR(500),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (endpoint)
            )"
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_portal_push_subscriptions_reader
             ON portal_push_subscriptions (reader_type, reader_id)'
        );
    }

    /** @param array<string, mixed> $subscription */
    public static function saveForCurrentReader(array $subscription): bool
    {
        self::ensureTable();
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }

        $reader = NotificationService::currentReader();
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (strlen($userAgent) > 500) {
            $userAgent = substr($userAgent, 0, 500);
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_push_subscriptions (
                reader_type, reader_id, endpoint, p256dh, auth, user_agent, updated_at
             ) VALUES (
                :reader_type, :reader_id, :endpoint, :p256dh, :auth, :user_agent, NOW()
             )
             ON CONFLICT (endpoint)
             DO UPDATE SET
                reader_type = EXCLUDED.reader_type,
                reader_id = EXCLUDED.reader_id,
                p256dh = EXCLUDED.p256dh,
                auth = EXCLUDED.auth,
                user_agent = EXCLUDED.user_agent,
                updated_at = NOW()'
        );
        $stmt->execute([
            'reader_type' => $reader['reader_type'],
            'reader_id' => $reader['reader_id'],
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
        ]);

        return true;
    }

    public static function removeForCurrentReader(string $endpoint): void
    {
        self::ensureTable();
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }

        $reader = NotificationService::currentReader();
        $stmt = Database::pdo()->prepare(
            'DELETE FROM portal_push_subscriptions
             WHERE endpoint = :endpoint
               AND reader_type = :reader_type
               AND reader_id = :reader_id'
        );
        $stmt->execute([
            'endpoint' => $endpoint,
            'reader_type' => $reader['reader_type'],
            'reader_id' => $reader['reader_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $notification
     * @return list<array<string, mixed>>
     */
    public static function subscriptionsForNotification(array $notification): array
    {
        self::ensureTable();
        $scope = (string) ($notification['scope'] ?? '');
        $audience = (string) ($notification['audience'] ?? '');
        $customerId = trim((string) ($notification['recipient_web_customer_id'] ?? ''));
        $staffId = trim((string) ($notification['recipient_web_user_id'] ?? ''));

        if ($scope === NotificationService::SCOPE_PRIVATE) {
            if ($customerId !== '') {
                return self::listByReader(NotificationService::READER_CUSTOMER, $customerId);
            }
            if ($staffId !== '') {
                return self::listByReader(NotificationService::READER_STAFF, $staffId);
            }

            return [];
        }

        return match ($audience) {
            NotificationService::AUDIENCE_GUESTS => self::listByReaderType(NotificationService::READER_GUEST),
            NotificationService::AUDIENCE_CUSTOMERS => self::listByReaderType(NotificationService::READER_CUSTOMER),
            NotificationService::AUDIENCE_STAFF => self::listByReaderType(NotificationService::READER_STAFF),
            default => self::listAll(),
        };
    }

    /** @return list<array<string, mixed>> */
    private static function listAll(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT endpoint, p256dh, auth
             FROM portal_push_subscriptions
             ORDER BY updated_at DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private static function listByReaderType(string $readerType): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT endpoint, p256dh, auth
             FROM portal_push_subscriptions
             WHERE reader_type = :reader_type
             ORDER BY updated_at DESC'
        );
        $stmt->execute(['reader_type' => $readerType]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private static function listByReader(string $readerType, string $readerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT endpoint, p256dh, auth
             FROM portal_push_subscriptions
             WHERE reader_type = :reader_type
               AND reader_id = :reader_id
             ORDER BY updated_at DESC'
        );
        $stmt->execute([
            'reader_type' => $readerType,
            'reader_id' => $readerId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function deleteByEndpoint(string $endpoint): void
    {
        self::ensureTable();
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }

        $stmt = Database::pdo()->prepare('DELETE FROM portal_push_subscriptions WHERE endpoint = :endpoint');
        $stmt->execute(['endpoint' => $endpoint]);
    }
}
