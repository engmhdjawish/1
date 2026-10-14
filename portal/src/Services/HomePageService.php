<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\ResponseCache;

final class HomePageService
{
    /** @return list<array<string, mixed>> */
    public static function mergedSectionShells(): array
    {
        $sections = HomeSectionService::activeSectionShells();
        foreach ($sections as &$section) {
            $section['_sort'] = (int) ($section['sort_order'] ?? 0);
        }
        unset($section);

        $offers = SpecialOfferService::activeHomeSectionShells();
        foreach ($offers as &$section) {
            $section['_sort'] = (int) ($section['home_sort_order'] ?? 0);
        }
        unset($section);

        $merged = array_merge($sections, $offers);
        usort($merged, static fn (array $a, array $b): int => ($a['_sort'] ?? 0) <=> ($b['_sort'] ?? 0));

        return $merged;
    }

    /** @return array<string, string> */
    public static function embeddedProductStrips(): array
    {
        $cacheKey = self::productStripsCacheKey();
        $cached = ResponseCache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stale = ResponseCache::getStale($cacheKey);
        if (is_array($stale)) {
            register_shutdown_function(static function () use ($cacheKey): void {
                try {
                    self::writeProductStripCache($cacheKey);
                } catch (\Throwable) {
                    // ignore background refresh failures
                }
            });

            return $stale;
        }

        return [];
    }

    /** @return array<string, string> */
    public static function productStripHtmlBySectionKey(): array
    {
        $cacheKey = self::productStripsCacheKey();
        $cached = ResponseCache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stale = ResponseCache::getStale($cacheKey);
        if (is_array($stale)) {
            register_shutdown_function(static function () use ($cacheKey): void {
                try {
                    self::writeProductStripCache($cacheKey);
                } catch (\Throwable) {
                    // ignore background refresh failures
                }
            });

            return $stale;
        }

        return self::writeProductStripCache($cacheKey);
    }

    /** @return array<string, string> */
    private static function writeProductStripCache(string $cacheKey): array
    {
        $strips = self::buildProductStripHtmlBySectionKey();
        ResponseCache::set($cacheKey, $strips, 600);

        return $strips;
    }

    /** @return array<string, string> */
    private static function buildProductStripHtmlBySectionKey(): array
    {
        if (!function_exists('h')) {
            require dirname(__DIR__, 2) . '/views/helpers.php';
        }

        $sections = self::loadFullSectionsBatched();
        // شرائط الرئيسية عامة ومخبّأة — تُبنى دائماً بسياسة الزائر لا بجلسة عميل مسجّل.
        $storeCatalogDisplay = StoreCatalogService::guestDisplayOptions();
        $strips = [];

        foreach ($sections as $section) {
            $key = trim((string) ($section['slug'] ?? $section['id'] ?? ''));
            if ($key === '') {
                continue;
            }

            ob_start();
            $products = is_array($section['products'] ?? null) ? $section['products'] : [];
            require dirname(__DIR__, 2) . '/views/partials/home-section-product-strip.php';
            $strips[$key] = ob_get_clean() ?: '';
        }

        return $strips;
    }

    /**
     * تحميل منتجات كل الأقسام بأقل عدد طلبات: طلب جماعي للمواد اليدوية + طلبات متوازية للفلاتر.
     *
     * @return list<array<string, mixed>>
     */
    private static function loadFullSectionsBatched(): array
    {
        $sections = self::mergedSectionShells();
        if ($sections === []) {
            return [];
        }

        $manualGuids = [];
        $filterJobs = [];
        $filterJobKeyBySectionIndex = [];
        $queryKeyToJobKey = [];

        $policyStoreGuids = StoreCatalogService::resolvedPolicyStoreGuids(null);

        foreach ($sections as $index => $section) {
            $maxProducts = max(1, (int) ($section['max_products'] ?? 12));
            $isOffer = !empty($section['is_offer_section']);
            $isManual = $isOffer
                ? (string) ($section['selection_mode'] ?? '') === 'manual'
                : (string) ($section['display_mode'] ?? 'filter') === 'manual';

            if ($isManual) {
                $guids = is_array($section['material_guids'] ?? null) ? $section['material_guids'] : [];
                $guids = array_values(array_unique(array_filter(array_map('strval', $guids), static fn (string $g): bool => trim($g) !== '')));
                shuffle($guids);
                $tryGuids = array_slice($guids, 0, min(count($guids), max($maxProducts * 3, $maxProducts)));
                $sections[$index]['_batch_manual_guids'] = $tryGuids;
                $sections[$index]['_batch_max_products'] = $maxProducts;
                foreach ($tryGuids as $guid) {
                    $manualGuids[$guid] = true;
                }
                continue;
            }

            $rules = is_array($section['filter_rules'] ?? null) ? $section['filter_rules'] : [];
            $rules = StoreCatalogService::mergePolicyFilterRules($rules);
            $poolSize = min(36, max($maxProducts * 2, $maxProducts + 6));
            $query = $isOffer
                ? SpecialOfferService::materialsListQuery($rules, $poolSize)
                : HomeSectionService::materialsListQuery($rules, $poolSize);
            $query = self::optimizeHomeMaterialsQuery($query, $rules);
            $queryKey = hash('sha256', json_encode($query, JSON_UNESCAPED_UNICODE));

            if (isset($queryKeyToJobKey[$queryKey])) {
                $filterJobKeyBySectionIndex[$index] = $queryKeyToJobKey[$queryKey];
                $sections[$index]['_batch_max_products'] = $maxProducts;
                $sections[$index]['_batch_is_offer'] = $isOffer;
                continue;
            }

            $jobKey = (string) count($filterJobs);
            $queryKeyToJobKey[$queryKey] = $jobKey;
            $filterJobKeyBySectionIndex[$index] = $jobKey;
            $sections[$index]['_batch_max_products'] = $maxProducts;
            $sections[$index]['_batch_is_offer'] = $isOffer;
            $filterJobs[] = [
                'key' => $jobKey,
                'max_products' => $maxProducts,
                'is_offer' => $isOffer,
                'path' => '/api/materials',
                'query' => $query,
            ];
        }

        $materialsByGuid = MaterialBatchService::fetchByGuids(array_keys($manualGuids), 25, $policyStoreGuids);

        $filterResponses = self::fetchFilterSections($filterJobs);

        foreach ($sections as $index => &$section) {
            $maxProducts = (int) ($section['_batch_max_products'] ?? max(1, (int) ($section['max_products'] ?? 12)));
            $isOffer = !empty($section['is_offer_section']);
            $manualTryGuids = is_array($section['_batch_manual_guids'] ?? null) ? $section['_batch_manual_guids'] : null;

            if ($manualTryGuids !== null) {
                $candidates = [];
                foreach ($manualTryGuids as $guid) {
                    $item = $materialsByGuid[$guid] ?? null;
                    if (is_array($item)) {
                        $candidates[] = $item;
                    }
                }
                if (!$isOffer) {
                    $candidates = StockReservationService::filterSellableProducts($candidates);
                }
                shuffle($candidates);
                $section['products'] = array_slice($candidates, 0, $maxProducts);
                unset($section['_batch_manual_guids'], $section['_batch_max_products']);
                if ($isOffer) {
                    $section['products'] = SpecialOfferService::attachOfferPricing($section['products'], $section);
                }
                continue;
            }

            $response = $filterResponses[$filterJobKeyBySectionIndex[$index] ?? ''] ?? null;
            $items = [];
            if (is_array($response) && ($response['ok'] ?? false) && is_array($response['data']['items'] ?? null)) {
                $items = $response['data']['items'];
            }

            if ($policyStoreGuids !== []) {
                $items = StoreCatalogService::filterProductsForStoreGuids($items, $policyStoreGuids, true);
            }

            if (!$isOffer) {
                $items = StockReservationService::filterSellableProducts($items);
            }

            if ($items !== []) {
                shuffle($items);
            }

            $section['products'] = array_slice($items, 0, $maxProducts);
            if ($isOffer) {
                $section['products'] = SpecialOfferService::attachOfferPricing($section['products'], $section);
            }
        }
        unset($section);

        return $sections;
    }

    /**
     * One batch request per eight sections. Falls back to parallel GETs if the
     * API does not accept the batch yet.
     *
     * @param list<array{key: string, path: string, query: array<string, mixed>}> $filterJobs
     * @return array<string, array<string, mixed>>
     */
    private static function fetchFilterSections(array $filterJobs): array
    {
        if ($filterJobs === []) {
            return [];
        }

        $responses = [];
        foreach (array_chunk($filterJobs, 8) as $chunk) {
            $batched = self::fetchFilterChunk($chunk);
            foreach ($batched as $key => $response) {
                $responses[$key] = $response;
            }
        }

        return $responses;
    }

    /**
     * @param list<array{key: string, path: string, query: array<string, mixed>}> $chunk
     * @return array<string, array<string, mixed>>
     */
    private static function fetchFilterChunk(array $chunk): array
    {
        try {
            $response = ApiClient::postJson('/api/materials/batch', [
                'queries' => array_map(
                    static fn (array $job): array => self::batchQueryPayload($job['query'], (string) $job['key']),
                    $chunk
                ),
            ], 25);
            $results = is_array($response['data']['results'] ?? null) ? $response['data']['results'] : null;
            if (($response['ok'] ?? false) && is_array($results)) {
                $mapped = [];
                foreach ($chunk as $job) {
                    $key = (string) $job['key'];
                    $data = $results[$key] ?? null;
                    $mapped[$key] = [
                        'ok' => is_array($data),
                        'status' => is_array($data) ? 200 : 502,
                        'data' => is_array($data) ? $data : null,
                        'error' => is_array($data) ? '' : 'missing batch result',
                    ];
                }

                return $mapped;
            }
        } catch (\Throwable) {
            // Fall through to the previous per-section requests.
        }

        return ApiClient::getMany($chunk, 25);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private static function batchQueryPayload(array $query, string $key): array
    {
        $payload = [
            'key' => $key,
            'includeTotalCount' => false,
            'page' => (int) ($query['page'] ?? 1),
            'pageSize' => (int) ($query['pageSize'] ?? 24),
        ];
        foreach ($query as $name => $value) {
            if ($name === 'includeTotalCount' || $value === null || $value === '') {
                continue;
            }
            if (in_array($name, ['isAvailable', 'hasImage'], true)) {
                $payload[$name] = $value === true || $value === 'true' || $value === '1' || $value === 1;
                continue;
            }
            if (in_array($name, ['page', 'pageSize'], true)) {
                $payload[$name] = (int) $value;
                continue;
            }
            if (is_string($name) && (str_starts_with($name, 'min') || str_starts_with($name, 'max'))) {
                $payload[$name] = (float) $value;
                continue;
            }
            $payload[$name] = $value;
        }

        return $payload;
    }

    private static function productStripsCacheKey(): string
    {
        return 'home_product_strips_v4:' . self::cacheKey();
    }

    /** @param array<string, mixed> $rules */
    private static function optimizeHomeMaterialsQuery(array $query, array $rules): array
    {
        $query['includeTotalCount'] = 'false';
        if (($rules['is_available'] ?? null) !== false && !isset($query['isAvailable'])) {
            $query['isAvailable'] = 'true';
        }

        return $query;
    }

    private static function cacheKey(): string
    {
        $home = Database::pdo()->query(
            'SELECT id::text AS id, updated_at::text AS updated_at, max_products, display_mode::text AS display_mode
             FROM home_sections WHERE is_active = TRUE ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $offers = Database::pdo()->query(
            'SELECT id::text AS id, updated_at::text AS updated_at, max_products, home_sort_order
             FROM special_offers
             WHERE is_active = TRUE
               AND show_on_home = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $guestDisplay = StoreCatalogService::guestDisplayOptions();
        $policy = StorePolicyService::guestPolicy();
        $policyFingerprint = is_array($policy)
            ? [
                'id' => (string) ($policy['id'] ?? ''),
                'show_price' => (bool) ($guestDisplay['show_price'] ?? false),
                'show_quantity' => (bool) ($guestDisplay['show_quantity'] ?? false),
                'allow_cart' => (bool) ($guestDisplay['allow_cart'] ?? false),
                'allow_order' => (bool) ($guestDisplay['allow_order'] ?? false),
                'store_guids' => StoreCatalogService::resolvedPolicyStoreGuids(null),
            ]
            : null;

        return hash('sha256', json_encode(['home' => $home, 'offers' => $offers, 'policy' => $policyFingerprint], JSON_UNESCAPED_UNICODE));
    }
}
