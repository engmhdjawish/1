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
        $cacheKey = 'home_product_strips_v1:' . self::cacheKey();
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

        $sections = self::loadFullSections();
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

    /** @return list<array<string, mixed>> */
    private static function loadFullSections(): array
    {
        $sections = HomeSectionService::activeSections();
        foreach ($sections as &$section) {
            $section['_sort'] = (int) ($section['sort_order'] ?? 0);
        }
        unset($section);

        $offers = SpecialOfferService::activeHomeSections();
        foreach ($offers as &$section) {
            $section['_sort'] = (int) ($section['home_sort_order'] ?? 0);
        }
        unset($section);

        $merged = array_merge($sections, $offers);
        usort($merged, static fn (array $a, array $b): int => ($a['_sort'] ?? 0) <=> ($b['_sort'] ?? 0));

        return $merged;
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
