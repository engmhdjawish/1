<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

/**
 * Stores filtered catalog query state behind a short ?v= code so address-bar
 * / WhatsApp links stay short while browsing with many Arabic facet values.
 */
final class CatalogFilterViewService
{
    private const VIEW_PARAM = 'v';

    /** @var list<string> */
    private const LIST_KEYS = [
        'materialTypes',
        'ageCategories',
        'manufacturers',
        'sizeRanges',
        'countryOfOrigins',
        'groupGuids',
        'storeGuids',
    ];

    /** @var list<string> */
    private const SCALAR_KEYS = [
        'q',
        'search',
        'sort',
        'groupBy',
        'season',
        'section',
        'offer',
        'isAvailable',
        'hasImage',
        'minWarehouseQuantity',
        'maxWarehouseQuantity',
        'minUnitSalePriceSyp',
        'maxUnitSalePriceSyp',
        'minUnitSalePriceUsd',
        'maxUnitSalePriceUsd',
        'minUnitPurchasePriceUsd',
        'maxUnitPurchasePriceUsd',
    ];

    /** @var list<string> */
    private const PASSTHROUGH_KEYS = [
        'page',
        'token',
        't',
        'c',
        'code',
        'preview',
        'open_cart',
        self::VIEW_PARAM,
    ];

    public static function viewParamName(): string
    {
        return self::VIEW_PARAM;
    }

    public static function ensureTable(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        try {
            Database::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS catalog_filter_views (
                    short_code   VARCHAR(16) PRIMARY KEY,
                    payload_json JSONB NOT NULL,
                    payload_hash CHAR(64) NOT NULL,
                    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    last_used_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                 )'
            );
            Database::pdo()->exec(
                'ALTER TABLE catalog_filter_views ADD COLUMN IF NOT EXISTS target_path TEXT'
            );
            Database::pdo()->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS ux_catalog_filter_views_payload_hash
                 ON catalog_filter_views (payload_hash)'
            );
            $ready = true;
        } catch (\Throwable) {
            // Migrations / privileges may lag; callers treat miss as no-op.
        }
    }

    /**
     * If $_GET contains v=, expand the stored filter bag into the query
     * (without wiping identity keys like token / page).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function expandRequest(array $query): array
    {
        $code = self::normalizeCode((string) ($query[self::VIEW_PARAM] ?? ''));
        if ($code === null) {
            return $query;
        }

        $payload = self::loadPayload($code);
        if ($payload === null) {
            return $query;
        }

        $passthrough = [];
        foreach (self::PASSTHROUGH_KEYS as $key) {
            if (array_key_exists($key, $query)) {
                $passthrough[$key] = $query[$key];
            }
        }

        $merged = array_merge($payload, $passthrough);
        $merged[self::VIEW_PARAM] = $code;

        return $merged;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ok: bool, code?: string, url?: string, message?: string}
     */
    public static function mint(string $path, array $params): array
    {
        self::ensureTable();
        $path = self::normalizePath($path);
        $payload = self::normalizePayload($params);
        if ($payload === []) {
            return [
                'ok' => true,
                'code' => '',
                'url' => self::buildUrl($path, [], $params),
            ];
        }

        $target = self::targetFor($path, $params);
        $code = self::store($target, $payload);
        if ($code === null) {
            return ['ok' => false, 'message' => 'تعذر إنشاء رابط مختصر.'];
        }

        return [
            'ok' => true,
            'code' => $code,
            'url' => self::buildUrl($path, ['v' => $code], $params),
            'short_url' => self::shortPath($code),
        ];
    }

    /**
     * Short /s/{code} for an exact same-site path (product, order tracking…).
     */
    public static function shortenPath(string $targetPath): ?string
    {
        $targetPath = trim($targetPath);
        if ($targetPath === '' || !str_starts_with($targetPath, '/') || str_starts_with($targetPath, '//')) {
            return null;
        }
        self::ensureTable();
        $code = self::store($targetPath, []);

        return $code === null ? null : self::shortPath($code);
    }

    public static function shortPath(string $code): string
    {
        return '/s/' . $code;
    }

    /**
     * Where /s/{code} should redirect when the code belongs to this table.
     */
    public static function resolveRedirect(string $code): ?string
    {
        $code = self::normalizeCode($code);
        if ($code === null) {
            return null;
        }
        self::ensureTable();
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT target_path, payload_json::text AS payload_json
                 FROM catalog_filter_views WHERE short_code = :code LIMIT 1'
            );
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $target = trim((string) ($row['target_path'] ?? ''));
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (is_array($payload) && $payload !== []) {
            $base = $target !== '' ? $target : '/store.php';
            return $base . (str_contains($base, '?') ? '&' : '?') . 'v=' . $code;
        }

        return $target !== '' && str_starts_with($target, '/') && !str_starts_with($target, '//') ? $target : null;
    }

    /** @param array<string, mixed> $params */
    private static function targetFor(string $path, array $params): string
    {
        if (in_array($path, ['/share.php', '/s.php'], true)) {
            foreach (['token', 't', 'c', 'code'] as $key) {
                $value = trim((string) ($params[$key] ?? ''));
                if ($value !== '' && !is_array($params[$key])) {
                    return '/share.php?token=' . rawurlencode($value);
                }
            }
        }

        return $path;
    }

    /** @param array<string, mixed> $payload */
    private static function store(string $target, array $payload): ?string
    {
        $json = (string) json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $target . "\n" . $json);
        try {
            $existing = Database::pdo()->prepare(
                'SELECT short_code FROM catalog_filter_views WHERE payload_hash = :hash LIMIT 1'
            );
            $existing->execute(['hash' => $hash]);
            $code = trim((string) $existing->fetchColumn());
            if ($code !== '') {
                Database::pdo()->prepare(
                    'UPDATE catalog_filter_views SET last_used_at = NOW() WHERE short_code = :code'
                )->execute(['code' => $code]);

                return $code;
            }

            $code = self::generateCode();
            Database::pdo()->prepare(
                'INSERT INTO catalog_filter_views (short_code, payload_json, payload_hash, target_path)
                 VALUES (:code, CAST(:payload AS jsonb), :hash, :target)'
            )->execute(['code' => $code, 'payload' => $json, 'hash' => $hash, 'target' => $target]);

            return $code;
        } catch (\Throwable) {
            try {
                $existing = Database::pdo()->prepare(
                    'SELECT short_code FROM catalog_filter_views WHERE payload_hash = :hash LIMIT 1'
                );
                $existing->execute(['hash' => $hash]);
                $code = trim((string) $existing->fetchColumn());

                return $code !== '' ? $code : null;
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /** True when the query is long enough that a short view helps. */
    public static function shouldShorten(array $params): bool
    {
        $payload = self::normalizePayload($params);
        if ($payload === []) {
            return false;
        }

        $encoded = http_build_query(self::queryFromPayload($payload));

        return strlen($encoded) >= 80 || count($payload) >= 2;
    }

    public static function normalizeCode(string $code): ?string
    {
        $code = strtolower(trim($code));
        if ($code === '' || preg_match('/^[a-z0-9]{4,16}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    /** @return array<string, mixed>|null */
    private static function loadPayload(string $code): ?array
    {
        self::ensureTable();
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT payload_json::text AS payload_json
                 FROM catalog_filter_views
                 WHERE short_code = :code
                 LIMIT 1'
            );
            $stmt->execute(['code' => $code]);
            $raw = $stmt->fetchColumn();
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            Database::pdo()->prepare(
                'UPDATE catalog_filter_views SET last_used_at = NOW() WHERE short_code = :code'
            )->execute(['code' => $code]);

            return self::normalizePayload($decoded);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $params): array
    {
        $out = [];

        foreach (self::LIST_KEYS as $key) {
            $values = self::normalizeList($params[$key] ?? null);
            if ($values !== []) {
                $out[$key] = $values;
            }
        }

        foreach (self::SCALAR_KEYS as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[$key];
            if (is_array($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            if ($key === 'search') {
                $out['q'] = $text;
                continue;
            }
            $out[$key] = $text;
        }

        ksort($out);

        return $out;
    }

    /** @param mixed $value @return list<string> */
    private static function normalizeList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            $value = preg_split('/[,|\n]+/u', (string) $value) ?: [];
        }
        $items = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);

        return $items;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function queryFromPayload(array $payload): array
    {
        $query = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $query[$key] = array_values($value);
            } else {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @param array<string, scalar> $viewParams
     * @param array<string, mixed> $originalParams
     */
    private static function buildUrl(string $path, array $viewParams, array $originalParams): string
    {
        $query = $viewParams;
        $page = trim((string) ($originalParams['page'] ?? ''));
        if ($page !== '' && $page !== '1') {
            $query['page'] = $page;
        }
        foreach (['preview', 'open_cart'] as $extra) {
            $value = trim((string) ($originalParams[$extra] ?? ''));
            if ($value !== '') {
                $query[$extra] = $value;
            }
        }

        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/')) {
            return '/store.php';
        }
        $parts = parse_url($path);
        $clean = (string) ($parts['path'] ?? '/store.php');
        if (preg_match('#^/s/[A-Za-z0-9_-]+/?$#', $clean) === 1) {
            return rtrim($clean, '/') ?: $clean;
        }
        if (in_array($clean, ['/store.php', '/store', '/share.php', '/share', '/s.php'], true)) {
            return $clean === '/store' ? '/store.php'
                : ($clean === '/share' ? '/share.php' : $clean);
        }

        return '/store.php';
    }

    private static function generateCode(int $length = 6): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            try {
                $stmt = Database::pdo()->prepare(
                    'SELECT 1 FROM catalog_filter_views WHERE short_code = :code LIMIT 1'
                );
                $stmt->execute(['code' => $code]);
                if ($stmt->fetchColumn() === false) {
                    return $code;
                }
            } catch (\Throwable) {
                return $code;
            }
        }

        return strtolower(bin2hex(random_bytes(4)));
    }
}
