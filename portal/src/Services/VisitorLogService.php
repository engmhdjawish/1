<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class VisitorLogService
{
    private static ?bool $hasSchema = null;

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

    /**
     * @param array<string, mixed>|null $customer
     * @return array{ok: bool, message?: string}
     */
    public static function recordEvent(
        string $sessionId,
        string $action,
        string $path,
        string $title,
        string $referer,
        ?array $customer = null
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

        $details = json_encode([
            'path' => $path,
            'title' => trim($title),
        ], JSON_UNESCAPED_UNICODE);
        if ($details === false) {
            $details = '{}';
        }

        $ip = self::clientIp();
        $geo = self::cachedGeo($ip) ?? [];
        if ($geo === [] && $ip !== '' && !self::isPrivateIp($ip)) {
            $geo = self::fetchGeo($ip);
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
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'db_error'];
        }

        return ['ok' => true];
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** @return array{page_views: int, unique_sessions: int, unique_ips: int, registered_hits: int} */
    public static function summaryForDays(int $days = 7): array
    {
        if (!self::hasSchema()) {
            return [
                'page_views' => 0,
                'unique_sessions' => 0,
                'unique_ips' => 0,
                'registered_hits' => 0,
            ];
        }

        $days = max(1, min(365, $days));
        $stmt = Database::pdo()->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE action = 'page_view')::int AS page_views,
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
            'page_views' => (int) ($row['page_views'] ?? 0),
            'unique_sessions' => (int) ($row['unique_sessions'] ?? 0),
            'unique_ips' => (int) ($row['unique_ips'] ?? 0),
            'registered_hits' => (int) ($row['registered_hits'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 100, ?string $action = null, ?int $days = null): array
    {
        if (!self::hasSchema()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
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
        $sql .= ' ORDER BY created_at DESC LIMIT :limit';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $details = json_decode((string) ($row['details_ar'] ?? ''), true);
            $row['page_path'] = is_array($details) ? (string) ($details['path'] ?? '') : '';
            $row['page_title'] = is_array($details) ? (string) ($details['title'] ?? '') : '';
            $path = $row['page_path'];
            $title = $row['page_title'];
            $row['details_ar'] = $path !== '' ? $path : ($title !== '' ? $title : (string) ($row['details_ar'] ?? ''));

            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
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
                COUNT(*)::int AS hits
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
