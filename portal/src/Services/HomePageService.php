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
    public static function productStripHtmlBySectionKey(): array
    {
        $cacheKey = 'home_product_strips_v2:' . self::cacheKey();
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
        $storeCatalogDisplay = StoreCatalogService::displayOptions();
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
            $poolSize = min(200, max($maxProducts * 8, 48));
            $query = $isOffer
                ? SpecialOfferService::materialsListQuery($rules, $poolSize)
                : HomeSectionService::materialsListQuery($rules, $poolSize);

            $filterJobs[] = [
                'key' => (string) $index,
                'max_products' => $maxProducts,
                'is_offer' => $isOffer,
                'path' => '/api/materials',
                'query' => $query,
            ];
        }

        $materialsByGuid = MaterialBatchService::fetchByGuids(array_keys($manualGuids), 25);

        $filterResponses = [];
        if ($filterJobs !== []) {
            $filterResponses = ApiClient::getMany($filterJobs, 25);
        }

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

            $response = $filterResponses[(string) $index] ?? null;
            $items = [];
            if (is_array($response) && ($response['ok'] ?? false) && is_array($response['data']['items'] ?? null)) {
                $items = $response['data']['items'];
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

        return hash('sha256', json_encode(['home' => $home, 'offers' => $offers], JSON_UNESCAPED_UNICODE));
    }
}
