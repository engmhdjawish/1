<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Database;
use Portal\Services\PortalPresenceService;
use PDO;

/**
 * Remembers a browser that belongs to a staff member.
 * Training a customer from that browser stays attributed to the staff user.
 */
final class StaffDevice
{
    private const COOKIE = 'jawish_staff';
    private const MAX_AGE = 15552000;

    /** @var array<string, array{id: string, name: string}|null> */
    private static array $profiles = [];

    public static function remember(string $userId): void
    {
        $userId = trim($userId);
        if (!self::isUuid($userId) || headers_sent()) {
            return;
        }

        $expires = time() + self::MAX_AGE;
        $value = self::sign($userId, $expires);
        setcookie(self::COOKIE, $value, array_merge(CookieOptions::base(true), [
            'expires' => $expires,
        ]));
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function id(): ?string
    {
        $raw = trim((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($raw === '') {
            return null;
        }

        $parts = explode('.', $raw, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$userId, $expires, $signature] = $parts;
        if (!self::isUuid($userId) || !ctype_digit($expires)) {
            return null;
        }
        if ((int) $expires < time()) {
            return null;
        }

        $expected = hash_hmac('sha256', $userId . '|' . $expires, self::key());
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return $userId;
    }

    /**
     * Staff member currently using this browser, including a customer login
     * opened from a phone that was already used by staff.
     *
     * @return array{id: string, name: string}|null
     */
    public static function current(?string $visitorId = null): ?array
    {
        if (WebSession::check()) {
            $user = WebSession::user();
            $userId = trim((string) ($user['id'] ?? ''));
            $name = trim((string) ($user['display_name_ar'] ?? $user['user_name'] ?? ''));
            if ($userId !== '' && $name !== '') {
                return ['id' => $userId, 'name' => $name];
            }
        }

        $cookieId = self::id();
        if ($cookieId !== null) {
            $profile = self::profile($cookieId);
            if ($profile !== null) {
                return $profile;
            }
        }

        $visitorId = trim((string) $visitorId);
        if ($visitorId === '') {
            return null;
        }

        $mappedId = PortalPresenceService::staffIdForVisitor($visitorId);
        if ($mappedId === null) {
            return null;
        }

        return self::profile($mappedId);
    }

    /** @return array{id: string, name: string}|null */
    private static function profile(string $userId): ?array
    {
        if (array_key_exists($userId, self::$profiles)) {
            return self::$profiles[$userId];
        }

        try {
            $stmt = Database::pdo()->prepare(
                "SELECT id::text AS id,
                        COALESCE(NULLIF(TRIM(display_name_ar), ''), NULLIF(TRIM(user_name), ''), 'موظف') AS name
                 FROM web_users
                 WHERE id = :id
                   AND is_active
                 LIMIT 1"
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            self::$profiles[$userId] = null;

            return null;
        }

        if (!is_array($row) || trim((string) ($row['id'] ?? '')) === '') {
            self::$profiles[$userId] = null;

            return null;
        }

        self::$profiles[$userId] = [
            'id' => (string) $row['id'],
            'name' => trim((string) ($row['name'] ?? 'موظف')),
        ];

        return self::$profiles[$userId];
    }

    private static function sign(string $userId, int $expires): string
    {
        $signature = hash_hmac('sha256', $userId . '|' . $expires, self::key());

        return $userId . '.' . $expires . '.' . $signature;
    }

    private static function key(): string
    {
        $secret = (string) (Config::get('PORTAL_DB_PASSWORD', 'portal') ?? 'portal');

        return hash('sha256', $secret . '|jawish-staff-device');
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
