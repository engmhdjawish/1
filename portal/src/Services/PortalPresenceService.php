<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Database;
use PDO;

final class PortalPresenceService
{
    private const ONLINE_MINUTES = 5;

    private static ?bool $enabled = null;

    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        try {
            self::$enabled = (bool) Database::pdo()->query(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'portal_presence'
                 LIMIT 1"
            )->fetchColumn();
        } catch (\Throwable) {
            self::$enabled = false;
        }

        return self::$enabled;
    }

    public static function touchFromRequest(?string $visitorId = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (WebSession::check()) {
            $user = WebSession::user();
            $userId = trim((string) ($user['id'] ?? ''));
            if ($userId !== '') {
                $label = trim((string) ($user['display_name_ar'] ?? $user['user_name'] ?? 'موظف'));
                self::upsert('staff:' . $userId, 'staff', $userId, $label);

                return;
            }
        }

        if (CustomerSession::check()) {
            $customer = CustomerSession::customer();
            $customerId = trim((string) ($customer['id'] ?? ''));
            if ($customerId !== '') {
                $label = trim((string) ($customer['name_ar'] ?? 'عميل'));
                self::upsert('customer:' . $customerId, 'customer', $customerId, $label);

                return;
            }
        }

        $visitorId = trim((string) $visitorId);
        if ($visitorId === '' && session_status() === PHP_SESSION_ACTIVE) {
            $sid = session_id();
            if ($sid !== '') {
                $visitorId = 'php:' . hash('sha256', $sid);
            }
        }
        if ($visitorId === '') {
            return;
        }

        self::upsert('guest:' . $visitorId, 'guest', null, 'زائر');
    }

    public static function onlineGuestCount(int $minutes = self::ONLINE_MINUTES): int
    {
        return count(self::onlineGuests($minutes));
    }

    /** @return list<array<string, mixed>> */
    public static function onlineGuests(int $minutes = self::ONLINE_MINUTES): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $minutes = max(1, min(60, $minutes));
        $stmt = Database::pdo()->prepare(
            "SELECT
                presence_key,
                label_ar,
                visitor_ip,
                country_ar,
                city_ar,
                user_agent,
                last_seen_at
             FROM portal_presence
             WHERE kind = 'guest'
               AND last_seen_at >= NOW() - (:minutes || ' minutes')::interval
             ORDER BY last_seen_at DESC
             LIMIT 200"
        );
        $stmt->execute(['minutes' => (string) $minutes]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function pruneStale(int $days = 7): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $days = max(1, min(90, $days));
        Database::pdo()->prepare(
            'DELETE FROM portal_presence WHERE last_seen_at < NOW() - (:days || \' days\')::interval'
        )->execute(['days' => (string) $days]);
    }

    private static function upsert(
        string $presenceKey,
        string $kind,
        ?string $subjectId,
        string $label
    ): void {
        $ip = self::clientIp();
        $ua = self::userAgent();
        $geo = self::geoForIp($ip);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO portal_presence (
                presence_key, kind, subject_id, label_ar, visitor_ip,
                country_ar, city_ar, user_agent, last_seen_at
             ) VALUES (
                :presence_key, :kind, :subject_id, :label_ar, :visitor_ip,
                :country_ar, :city_ar, :user_agent, NOW()
             )
             ON CONFLICT (presence_key) DO UPDATE SET
                kind = EXCLUDED.kind,
                subject_id = EXCLUDED.subject_id,
                label_ar = EXCLUDED.label_ar,
                visitor_ip = EXCLUDED.visitor_ip,
                country_ar = COALESCE(EXCLUDED.country_ar, portal_presence.country_ar),
                city_ar = COALESCE(EXCLUDED.city_ar, portal_presence.city_ar),
                user_agent = EXCLUDED.user_agent,
                last_seen_at = NOW()'
        );
        $stmt->execute([
            'presence_key' => substr($presenceKey, 0, 128),
            'kind' => substr($kind, 0, 20),
            'subject_id' => $subjectId !== null && $subjectId !== '' ? $subjectId : null,
            'label_ar' => substr($label, 0, 250),
            'visitor_ip' => $ip !== '' ? substr($ip, 0, 45) : null,
            'country_ar' => $geo['country_ar'] ?? null,
            'city_ar' => $geo['city_ar'] ?? null,
            'user_agent' => $ua !== '' ? substr($ua, 0, 500) : null,
        ]);
    }

    /** @return array{country_ar?: string|null, city_ar?: string|null} */
    private static function geoForIp(string $ip): array
    {
        if ($ip === '') {
            return [];
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT country_ar, city_ar
                 FROM visitor_logs
                 WHERE visitor_ip = :ip
                   AND country_ar IS NOT NULL
                 ORDER BY created_at DESC
                 LIMIT 1'
            );
            $stmt->execute(['ip' => $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return [
                    'country_ar' => $row['country_ar'] ?? null,
                    'city_ar' => $row['city_ar'] ?? null,
                ];
            }
        } catch (\Throwable) {
            // visitor_logs may be unavailable
        }

        return [];
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
