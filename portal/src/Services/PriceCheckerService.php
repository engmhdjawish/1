<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;
use RuntimeException;

final class PriceCheckerService
{
    private const SETTINGS_ID = 1;

    /** @var array<string, mixed>|null */
    private static ?array $configCache = null;

    /** @return array<string, mixed> */
    public static function config(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        $defaults = self::defaultConfig();
        try {
            $stmt = Database::pdo()->query(
                'SELECT *
                 FROM price_checker_settings
                 WHERE id = ' . self::SETTINGS_ID
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                self::$configCache = $defaults;

                return $defaults;
            }

            self::$configCache = self::normalizeRow($row, $defaults);

            return self::$configCache;
        } catch (\Throwable) {
            self::$configCache = $defaults;

            return $defaults;
        }
    }

    public static function clearCache(): void
    {
        self::$configCache = null;
    }

    /** @param array<string, mixed> $input */
    public static function save(array $input, ?string $updatedByUserId): void
    {
        $current = self::config();
        $allowedIps = self::normalizeIpListText((string) ($input['allowed_ips'] ?? ''));
        $manufacturers = self::normalizeManufacturerListText((string) ($input['slideshow_manufacturers'] ?? ''));

        $stmt = Database::pdo()->prepare(
            'INSERT INTO price_checker_settings (
                id,
                enabled,
                allowed_ips,
                page_title_ar,
                display_seconds,
                error_display_seconds,
                slideshow_enabled,
                slideshow_count,
                slideshow_interval_ms,
                slideshow_cache_seconds,
                slideshow_show_price,
                slideshow_manufacturers,
                updated_at,
                updated_by_user_id
             ) VALUES (
                :id,
                :enabled,
                :allowed_ips,
                :page_title_ar,
                :display_seconds,
                :error_display_seconds,
                :slideshow_enabled,
                :slideshow_count,
                :slideshow_interval_ms,
                :slideshow_cache_seconds,
                :slideshow_show_price,
                :slideshow_manufacturers,
                NOW(),
                :updated_by_user_id
             )
             ON CONFLICT (id) DO UPDATE SET
                enabled = EXCLUDED.enabled,
                allowed_ips = EXCLUDED.allowed_ips,
                page_title_ar = EXCLUDED.page_title_ar,
                display_seconds = EXCLUDED.display_seconds,
                error_display_seconds = EXCLUDED.error_display_seconds,
                slideshow_enabled = EXCLUDED.slideshow_enabled,
                slideshow_count = EXCLUDED.slideshow_count,
                slideshow_interval_ms = EXCLUDED.slideshow_interval_ms,
                slideshow_cache_seconds = EXCLUDED.slideshow_cache_seconds,
                slideshow_show_price = EXCLUDED.slideshow_show_price,
                slideshow_manufacturers = EXCLUDED.slideshow_manufacturers,
                updated_at = NOW(),
                updated_by_user_id = EXCLUDED.updated_by_user_id'
        );

        $stmt->execute([
            'id' => self::SETTINGS_ID,
            'enabled' => self::boolValue($input['enabled'] ?? $current['enabled']),
            'allowed_ips' => $allowedIps,
            'page_title_ar' => self::clipString((string) ($input['page_title_ar'] ?? $current['page_title_ar']), 200),
            'display_seconds' => self::intInRange((int) ($input['display_seconds'] ?? $current['display_seconds']), 2, 120, 5),
            'error_display_seconds' => self::intInRange((int) ($input['error_display_seconds'] ?? $current['error_display_seconds']), 2, 60, 5),
            'slideshow_enabled' => self::boolValue($input['slideshow_enabled'] ?? $current['slideshow_enabled']),
            'slideshow_count' => self::intInRange((int) ($input['slideshow_count'] ?? $current['slideshow_count']), 1, 20, 5),
            'slideshow_interval_ms' => self::intInRange((int) ($input['slideshow_interval_ms'] ?? $current['slideshow_interval_ms']), 3000, 120000, 20000),
            'slideshow_cache_seconds' => self::intInRange((int) ($input['slideshow_cache_seconds'] ?? $current['slideshow_cache_seconds']), 30, 3600, 300),
            'slideshow_show_price' => self::boolValue($input['slideshow_show_price'] ?? $current['slideshow_show_price']),
            'slideshow_manufacturers' => $manufacturers,
            'updated_by_user_id' => $updatedByUserId ?: null,
        ]);

        self::clearCache();
        self::clearSlideshowCache();
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::config()['enabled'] ?? false);
    }

    public static function clientIp(): string
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

    /** @return list<string> */
    public static function allowedIps(): array
    {
        $raw = trim((string) (self::config()['allowed_ips'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $ips = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '' || !filter_var($part, FILTER_VALIDATE_IP)) {
                continue;
            }
            $ips[] = $part;
        }

        return array_values(array_unique($ips));
    }

    public static function isIpAllowed(?string $ip = null): bool
    {
        $ip = trim($ip ?? self::clientIp());
        $allowed = self::allowedIps();
        if ($allowed === []) {
            return false;
        }
        if ($ip === '') {
            return false;
        }

        return in_array($ip, $allowed, true);
    }

    public static function publicUrl(): string
    {
        $base = rtrim((string) (Config::get('PORTAL_APP_URL', '') ?? ''), '/');
        if ($base === '') {
            return '/price-checker.php';
        }

        return $base . '/price-checker.php';
    }

    /** @return array<string, mixed>|null */
    public static function lookupBarcode(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $response = ApiClient::get('/api/materials', [
            'search' => $barcode,
            'page' => 1,
            'pageSize' => 20,
        ], 20);

        if (!($response['ok'] ?? false)) {
            $message = is_array($response['data'] ?? null)
                ? (string) (($response['data']['message'] ?? $response['data']['title'] ?? $response['error'] ?? '') ?: 'فشل جلب المواد')
                : (string) ($response['error'] ?? 'فشل جلب المواد');
            throw new RuntimeException($message);
        }

        $items = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];
        $material = self::pickMaterial($items, $barcode);
        if ($material === null || !empty($material['isHidden'])) {
            return null;
        }

        $perBox = (float) ($material['packageConversionFactor'] ?? 0);
        if ($perBox <= 0) {
            $perBox = 1.0;
        }

        return [
            'name' => (string) ($material['name'] ?? ''),
            'salePrice_SP' => (float) ($material['unitSalePriceSyp'] ?? 0),
            'salePrice_Usd' => (float) ($material['unitSalePriceUsd'] ?? 0),
            'pcsPerBox' => $perBox,
            'availableQuantity' => (float) ($material['warehouseQuantity'] ?? 0),
            'availableQunatity' => (float) ($material['warehouseQuantity'] ?? 0),
        ];
    }

    /**
     * @param list<string> $excludeGuids
     * @return list<array<string, mixed>>
     */
    public static function slideshowItems(bool $forceRefresh = false, array $excludeGuids = []): array
    {
        $config = self::config();
        if (!(bool) ($config['slideshow_enabled'] ?? false)) {
            return [];
        }

        $cacheSeconds = (int) ($config['slideshow_cache_seconds'] ?? 300);
        $cacheKey = self::slideshowCacheKey($config);
        if (!$forceRefresh) {
            $cached = self::readSlideshowCache($cacheKey);
            if ($cached !== null) {
                return self::filterExcludedSlideshowItems($cached, $excludeGuids, (int) ($config['slideshow_count'] ?? 5));
            }
        }

        $query = [
            'hasImage' => 'true',
            'isAvailable' => 'true',
            'page' => 1,
            'pageSize' => $forceRefresh ? 120 : 80,
        ];

        $manufacturers = self::slideshowManufacturers();
        if ($manufacturers !== []) {
            $query['manufacturers'] = implode(',', $manufacturers);
        }

        try {
            $response = ApiClient::get('/api/materials', $query, 25);
            if (!($response['ok'] ?? false) || !is_array($response['data'])) {
                return [];
            }

            $excludeSet = [];
            foreach ($excludeGuids as $guid) {
                $guid = strtolower(trim($guid));
                if ($guid !== '' && preg_match('/^[0-9a-f-]{36}$/', $guid) === 1) {
                    $excludeSet[$guid] = true;
                }
            }

            $allowedManufacturers = array_map('mb_strtolower', $manufacturers);
            $pool = [];
            foreach ($response['data']['items'] ?? [] as $row) {
                if (!is_array($row) || !empty($row['isHidden'])) {
                    continue;
                }
                $imageGuid = trim((string) ($row['productImageGuid'] ?? ''));
                if ($imageGuid === '' || isset($excludeSet[strtolower($imageGuid)])) {
                    continue;
                }

                $manufacturer = trim((string) ($row['manufacturer'] ?? ''));
                if ($allowedManufacturers !== [] && !in_array(mb_strtolower($manufacturer), $allowedManufacturers, true)) {
                    continue;
                }

                $pool[] = [
                    'imageGuid' => $imageGuid,
                    'name' => (string) ($row['name'] ?? ''),
                    'manufacturer' => $manufacturer,
                    'image' => self::materialImageUrl($imageGuid, true),
                    'priceSp' => (float) ($row['unitSalePriceSyp'] ?? 0),
                    'priceUsd' => (float) ($row['unitSalePriceUsd'] ?? 0),
                ];
            }

            self::writeSlideshowCache($cacheKey, $pool, $cacheSeconds);

            return self::pickSlideshowBatch($pool, (int) ($config['slideshow_count'] ?? 5), $excludeGuids);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public static function slideshowManufacturers(): array
    {
        $raw = trim((string) (self::config()['slideshow_manufacturers'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\R+/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return array_values(array_unique($items));
    }

    public static function materialImageUrl(string $imageGuid, bool $thumb = true): string
    {
        $query = 'id=' . rawurlencode($imageGuid);
        if (!$thumb) {
            $query .= '&thumb=0';
        }

        return '/api/image.php?' . $query;
    }

    public static function logoUrl(): string
    {
        $logo = PortalSettingsService::companyLogoUrl();
        if (is_string($logo) && trim($logo) !== '') {
            return $logo;
        }

        return '';
    }

    public static function siteName(): string
    {
        $company = PortalSettingsService::companySettings();
        $name = trim((string) ($company['company_name'] ?? ''));

        return $name !== '' ? $name : 'جويش للتجارة';
    }

    /** @param list<array<string, mixed>> $items */
    private static function pickMaterial(array $items, string $barcode): ?array
    {
        if ($items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['barcode', 'materialCode'] as $field) {
                $value = trim((string) ($item[$field] ?? ''));
                if ($value !== '' && strcasecmp($value, $barcode) === 0) {
                    return $item;
                }
            }
        }

        if (count($items) === 1 && is_array($items[0])) {
            return $items[0];
        }

        return is_array($items[0] ?? null) ? $items[0] : null;
    }

    /** @return array<string, mixed> */
    private static function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'allowed_ips' => '',
            'page_title_ar' => 'فاحص الأسعار',
            'display_seconds' => 5,
            'error_display_seconds' => 5,
            'slideshow_enabled' => true,
            'slideshow_count' => 5,
            'slideshow_interval_ms' => 20000,
            'slideshow_cache_seconds' => 300,
            'slideshow_show_price' => true,
            'slideshow_manufacturers' => '',
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $defaults @return array<string, mixed> */
    private static function normalizeRow(array $row, array $defaults): array
    {
        return [
            'enabled' => filter_var($row['enabled'] ?? $defaults['enabled'], FILTER_VALIDATE_BOOLEAN),
            'allowed_ips' => (string) ($row['allowed_ips'] ?? ''),
            'page_title_ar' => self::clipString((string) ($row['page_title_ar'] ?? $defaults['page_title_ar']), 200),
            'display_seconds' => self::intInRange((int) ($row['display_seconds'] ?? $defaults['display_seconds']), 2, 120, 5),
            'error_display_seconds' => self::intInRange((int) ($row['error_display_seconds'] ?? $defaults['error_display_seconds']), 2, 60, 5),
            'slideshow_enabled' => filter_var($row['slideshow_enabled'] ?? $defaults['slideshow_enabled'], FILTER_VALIDATE_BOOLEAN),
            'slideshow_count' => self::intInRange((int) ($row['slideshow_count'] ?? $defaults['slideshow_count']), 1, 20, 5),
            'slideshow_interval_ms' => self::intInRange((int) ($row['slideshow_interval_ms'] ?? $defaults['slideshow_interval_ms']), 3000, 120000, 20000),
            'slideshow_cache_seconds' => self::intInRange((int) ($row['slideshow_cache_seconds'] ?? $defaults['slideshow_cache_seconds']), 30, 3600, 300),
            'slideshow_show_price' => filter_var($row['slideshow_show_price'] ?? $defaults['slideshow_show_price'], FILTER_VALIDATE_BOOLEAN),
            'slideshow_manufacturers' => (string) ($row['slideshow_manufacturers'] ?? ''),
        ];
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function intInRange(int $value, int $min, int $max, int $fallback): int
    {
        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    private static function clipString(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }

    private static function normalizeIpListText(string $text): string
    {
        $ips = [];
        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || !filter_var($line, FILTER_VALIDATE_IP)) {
                continue;
            }
            $ips[] = $line;
        }

        return implode("\n", array_values(array_unique($ips)));
    }

    private static function normalizeManufacturerListText(string $text): string
    {
        $items = [];
        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return implode("\n", array_values(array_unique($items)));
    }

    /** @param array<string, mixed> $config */
    private static function slideshowCacheKey(array $config): string
    {
        return hash('sha256', json_encode([
            'manufacturers' => self::slideshowManufacturers(),
            'count' => (int) ($config['slideshow_count'] ?? 5),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array<string, mixed>>|null */
    private static function readSlideshowCache(string $cacheKey): ?array
    {
        $path = self::slideshowCachePath();
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['key'] ?? '') !== $cacheKey) {
            return null;
        }
        if ((int) ($decoded['exp'] ?? 0) < time()) {
            return null;
        }

        return is_array($decoded['pool'] ?? null) ? $decoded['pool'] : null;
    }

    /** @param list<array<string, mixed>> $pool */
    private static function writeSlideshowCache(string $cacheKey, array $pool, int $ttlSeconds): void
    {
        $dir = dirname(self::slideshowCachePath());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode([
            'key' => $cacheKey,
            'exp' => time() + max(30, $ttlSeconds),
            'pool' => $pool,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        file_put_contents(self::slideshowCachePath(), $payload, LOCK_EX);
    }

    public static function clearSlideshowCache(): void
    {
        $path = self::slideshowCachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function slideshowCachePath(): string
    {
        return rtrim(Config::storagePath(), '/\\') . '/cache/price-checker-slideshow.json';
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @param list<string> $excludeGuids
     * @return list<array<string, mixed>>
     */
    private static function filterExcludedSlideshowItems(array $pool, array $excludeGuids, int $count): array
    {
        $excludeSet = [];
        foreach ($excludeGuids as $guid) {
            $guid = strtolower(trim($guid));
            if ($guid !== '') {
                $excludeSet[$guid] = true;
            }
        }

        $filtered = [];
        foreach ($pool as $item) {
            $guid = strtolower(trim((string) ($item['imageGuid'] ?? '')));
            if ($guid !== '' && isset($excludeSet[$guid])) {
                continue;
            }
            $filtered[] = $item;
        }

        return self::pickSlideshowBatch($filtered, $count, []);
    }

    /**
     * @param list<array<string, mixed>> $pool
     * @param list<string> $excludeGuids
     * @return list<array<string, mixed>>
     */
    private static function pickSlideshowBatch(array $pool, int $count, array $excludeGuids): array
    {
        if ($pool === []) {
            return [];
        }

        if ($excludeGuids !== []) {
            $excludeSet = array_fill_keys(array_map('strtolower', array_map('trim', $excludeGuids)), true);
            $pool = array_values(array_filter(
                $pool,
                static fn (array $item): bool => !isset($excludeSet[strtolower(trim((string) ($item['imageGuid'] ?? '')))])
            ));
        }

        if ($pool === []) {
            return [];
        }

        shuffle($pool);

        return array_slice($pool, 0, min($count, count($pool)));
    }
}
