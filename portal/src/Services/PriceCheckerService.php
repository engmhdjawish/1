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
    private const BARCODE_CACHE_TTL_SECONDS = 120;
    private const BARCODE_CACHE_MAX_ENTRIES = 400;

    /** @var array<string, mixed>|null */
    private static ?array $configCache = null;

    /** @var array<string, array{exp: int, data: array<string, mixed>}> */
    private static array $barcodeMemoryCache = [];

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
            self::$configCache['slideshow_material_guids'] = self::loadManualMaterialGuids();

            return self::$configCache;
        } catch (\Throwable) {
            self::$configCache = $defaults;
            self::$configCache['slideshow_material_guids'] = [];

            return self::$configCache;
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
        $mode = self::normalizeSlideshowMode((string) ($input['slideshow_mode'] ?? $current['slideshow_mode']));
        $filterRules = is_array($input['slideshow_filter_rules'] ?? null)
            ? $input['slideshow_filter_rules']
            : self::decodeFilterRules($input['slideshow_filter_rules'] ?? $current['slideshow_filter_rules']);
        $manualGuids = self::stringList($input['slideshow_material_guids'] ?? []);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
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
                    slideshow_mode,
                    slideshow_filter_rules,
                    slideshow_offer_slug,
                    slideshow_use_offer_prices,
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
                    :slideshow_mode,
                    CAST(:slideshow_filter_rules AS jsonb),
                    :slideshow_offer_slug,
                    :slideshow_use_offer_prices,
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
                    slideshow_mode = EXCLUDED.slideshow_mode,
                    slideshow_filter_rules = EXCLUDED.slideshow_filter_rules,
                    slideshow_offer_slug = EXCLUDED.slideshow_offer_slug,
                    slideshow_use_offer_prices = EXCLUDED.slideshow_use_offer_prices,
                    updated_at = NOW(),
                    updated_by_user_id = EXCLUDED.updated_by_user_id'
            );

            $legacyManufacturers = self::manufacturersFromRules($filterRules);

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
                'slideshow_manufacturers' => implode("\n", $legacyManufacturers),
                'slideshow_mode' => $mode,
                'slideshow_filter_rules' => json_encode($filterRules, JSON_UNESCAPED_UNICODE) ?: '{}',
                'slideshow_offer_slug' => self::clipString((string) ($input['slideshow_offer_slug'] ?? $current['slideshow_offer_slug']), 120),
                'slideshow_use_offer_prices' => self::boolValue($input['slideshow_use_offer_prices'] ?? $current['slideshow_use_offer_prices']),
                'updated_by_user_id' => $updatedByUserId ?: null,
            ]);

            self::syncManualMaterials($manualGuids);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::clearCache();
        self::clearSlideshowCache();
    }

    /** @return array<string, mixed> */
    public static function parseFilterPayloadFromPost(array $post): array
    {
        return [
            'keyword' => trim((string) ($post['filter_keyword'] ?? '')),
            'material_types' => self::stringList($post['filter_material_types'] ?? []),
            'age_categories' => self::stringList($post['filter_age_categories'] ?? []),
            'manufacturers' => self::stringList($post['filter_manufacturers'] ?? []),
            'size_ranges' => self::stringList($post['filter_size_ranges'] ?? []),
            'country_origins' => self::stringList($post['filter_country_origins'] ?? []),
            'store_guids' => self::stringList($post['filter_store_guids'] ?? []),
            'group_guids' => self::stringList($post['filter_group_guids'] ?? []),
            'is_available' => self::toNullableBool((string) ($post['filter_is_available'] ?? '')),
            'has_image' => self::toNullableBool((string) ($post['filter_has_image'] ?? '')),
            'min_warehouse_quantity' => self::toNullableFloat((string) ($post['filter_min_warehouse_quantity'] ?? '')),
            'max_warehouse_quantity' => self::toNullableFloat((string) ($post['filter_max_warehouse_quantity'] ?? '')),
            'min_unit_sale_price_syp' => self::toNullableFloat((string) ($post['filter_min_unit_sale_price_syp'] ?? '')),
            'max_unit_sale_price_syp' => self::toNullableFloat((string) ($post['filter_max_unit_sale_price_syp'] ?? '')),
            'min_unit_sale_price_usd' => self::toNullableFloat((string) ($post['filter_min_unit_sale_price_usd'] ?? '')),
            'max_unit_sale_price_usd' => self::toNullableFloat((string) ($post['filter_max_unit_sale_price_usd'] ?? '')),
            'min_unit_purchase_price_usd' => self::toNullableFloat((string) ($post['filter_min_unit_purchase_price_usd'] ?? '')),
            'max_unit_purchase_price_usd' => self::toNullableFloat((string) ($post['filter_max_unit_purchase_price_usd'] ?? '')),
        ];
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
        if ($allowed === [] || $ip === '') {
            return false;
        }

        return in_array($ip, $allowed, true);
    }

    public static function publicUrl(): string
    {
        $base = rtrim((string) (Config::get('PORTAL_APP_URL', '') ?? ''), '/');

        return $base !== '' ? $base . '/price-checker.php' : '/price-checker.php';
    }

    /** @return array<string, mixed>|null */
    public static function lookupBarcode(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $cacheKey = self::barcodeCacheKey($barcode);
        $cached = self::readBarcodeCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $material = self::fetchMaterialByBarcode($barcode);
        if ($material === null || !empty($material['isHidden'])) {
            return null;
        }

        $material = self::hydrateMaterialForLookup($material);
        $offerOverlay = SpecialOfferService::pricingOverlay($material);
        $result = self::mapLookupMaterial($material, $offerOverlay);
        self::writeBarcodeCache($cacheKey, $result);

        return $result;
    }

    /** @return array<string, mixed>|null */
    private static function fetchMaterialByBarcode(string $barcode): ?array
    {
        foreach ([3, 12] as $pageSize) {
            $response = ApiClient::get('/api/materials', [
                'keyword' => $barcode,
                'page' => 1,
                'pageSize' => $pageSize,
                'includeTotalCount' => 'false',
            ], 12);

            if (!($response['ok'] ?? false)) {
                $message = is_array($response['data'] ?? null)
                    ? (string) (($response['data']['message'] ?? $response['data']['title'] ?? $response['error'] ?? '') ?: 'فشل جلب المواد')
                    : (string) ($response['error'] ?? 'فشل جلب المواد');
                throw new RuntimeException($message);
            }

            $items = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];
            $material = self::pickMaterial($items, $barcode);
            if ($material !== null) {
                return $material;
            }

            if ($items === []) {
                return null;
            }
        }

        return null;
    }

    public static function warmConnection(): void
    {
        self::config();
        ApiClient::ensureToken();
    }

    public static function clearBarcodeCache(): void
    {
        self::$barcodeMemoryCache = [];
        $path = self::barcodeCachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $material @param array<string, mixed> $offerOverlay @return array<string, mixed> */
    private static function mapLookupMaterial(array $material, array $offerOverlay = []): array
    {
        $perBox = (float) ($material['packageConversionFactor'] ?? 0);
        if ($perBox <= 0) {
            $perBox = 1.0;
        }

        $hasOffer = !empty($offerOverlay['has_offer']);
        $unitSp = (float) ($material['unitSalePriceSyp'] ?? 0);
        $unitUsd = (float) ($material['unitSalePriceUsd'] ?? 0);
        if ($hasOffer) {
            $unitSp = (float) ($offerOverlay['effective_unit_sale_price_sp'] ?? $unitSp);
            $unitUsd = (float) ($offerOverlay['effective_unit_sale_price_usd'] ?? $unitUsd);
        }

        $result = [
            'name' => (string) ($material['name'] ?? ''),
            'salePrice_SP' => $unitSp,
            'salePrice_Usd' => $unitUsd,
            'pcsPerBox' => $perBox,
            'availableQuantity' => (float) ($material['warehouseQuantity'] ?? 0),
            'availableQunatity' => (float) ($material['warehouseQuantity'] ?? 0),
            'hasOffer' => $hasOffer,
        ];

        if ($hasOffer) {
            $offer = is_array($offerOverlay['offer'] ?? null) ? $offerOverlay['offer'] : [];
            $originalUnitSp = (float) ($offerOverlay['original_unit_sale_price_sp'] ?? $unitSp);
            $originalUnitUsd = (float) ($offerOverlay['original_unit_sale_price_usd'] ?? $unitUsd);
            $result['offerBadge'] = trim((string) ($offerOverlay['offer_badge'] ?? '')) ?: 'عرض خاص';
            $result['offerTitle'] = trim((string) ($offer['title_ar'] ?? ''));
            $result['originalSalePrice_SP'] = $originalUnitSp;
            $result['originalSalePrice_Usd'] = $originalUnitUsd;
            $result['originalBoxSalePrice_SP'] = $originalUnitSp * $perBox;
            $result['originalBoxSalePrice_Usd'] = $originalUnitUsd * $perBox;
            $result['discountPercent'] = self::discountPercent($originalUnitSp, $unitSp, $originalUnitUsd, $unitUsd);
            if ($originalUnitSp > $unitSp + 0.01) {
                $result['savings_SP'] = $originalUnitSp - $unitSp;
                $result['savingsBox_SP'] = ($originalUnitSp - $unitSp) * $perBox;
            }
            if ($originalUnitUsd > $unitUsd + 0.0001) {
                $result['savings_Usd'] = $originalUnitUsd - $unitUsd;
                $result['savingsBox_Usd'] = ($originalUnitUsd - $unitUsd) * $perBox;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $material @return array<string, mixed> */
    private static function normalizeMaterialRow(array $material): array
    {
        $guid = trim((string) (
            $material['materialGuid'] ?? $material['MaterialGuid'] ?? $material['guid'] ?? $material['Guid'] ?? ''
        ));
        if ($guid !== '') {
            $material['materialGuid'] = $guid;
        }

        return $material;
    }

    /** @param array<string, mixed> $material @return array<string, mixed> */
    private static function hydrateMaterialForLookup(array $material): array
    {
        $material = self::normalizeMaterialRow($material);
        $guid = trim((string) ($material['materialGuid'] ?? ''));
        if ($guid === '' || !preg_match('/^[0-9a-f-]{36}$/i', $guid)) {
            return $material;
        }

        try {
            $fetched = MaterialBatchService::fetchByGuids([$guid], 12);
            $full = $fetched[$guid] ?? null;
            if (is_array($full)) {
                return self::normalizeMaterialRow(array_merge($material, $full));
            }
        } catch (\Throwable) {
            // keep search row
        }

        return $material;
    }

    private static function discountPercent(float $originalSp, float $currentSp, float $originalUsd, float $currentUsd): int
    {
        $percents = [];
        if ($originalSp > $currentSp + 0.01 && $originalSp > 0) {
            $percents[] = (int) round((1 - ($currentSp / $originalSp)) * 100);
        }
        if ($originalUsd > $currentUsd + 0.0001 && $originalUsd > 0) {
            $percents[] = (int) round((1 - ($currentUsd / $originalUsd)) * 100);
        }

        return $percents !== [] ? max($percents) : 0;
    }

    private static function barcodeCacheKey(string $barcode): string
    {
        return hash('sha256', 'v4|' . strtolower(trim($barcode)));
    }

    /** @return array<string, mixed>|null */
    private static function readBarcodeCache(string $cacheKey): ?array
    {
        $now = time();
        if (isset(self::$barcodeMemoryCache[$cacheKey]) && self::$barcodeMemoryCache[$cacheKey]['exp'] >= $now) {
            return self::$barcodeMemoryCache[$cacheKey]['data'];
        }

        $path = self::barcodeCachePath();
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $entry = $decoded[$cacheKey] ?? null;
        if (!is_array($entry) || (int) ($entry['exp'] ?? 0) < $now) {
            return null;
        }

        $data = $entry['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        self::$barcodeMemoryCache[$cacheKey] = ['exp' => (int) $entry['exp'], 'data' => $data];

        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function writeBarcodeCache(string $cacheKey, array $data): void
    {
        $exp = time() + self::BARCODE_CACHE_TTL_SECONDS;
        self::$barcodeMemoryCache[$cacheKey] = ['exp' => $exp, 'data' => $data];

        $path = self::barcodeCachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $store = [];
        if (is_readable($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            if (is_array($existing)) {
                $store = $existing;
            }
        }

        $now = time();
        foreach ($store as $key => $entry) {
            if (!is_array($entry) || (int) ($entry['exp'] ?? 0) < $now) {
                unset($store[$key]);
            }
        }

        $store[$cacheKey] = ['exp' => $exp, 'data' => $data];

        if (count($store) > self::BARCODE_CACHE_MAX_ENTRIES) {
            uasort($store, static fn (array $a, array $b): int => (int) ($a['exp'] ?? 0) <=> (int) ($b['exp'] ?? 0));
            $store = array_slice($store, -self::BARCODE_CACHE_MAX_ENTRIES, null, true);
        }

        $payload = json_encode($store, JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            file_put_contents($path, $payload, LOCK_EX);
        }
    }

    private static function barcodeCachePath(): string
    {
        return rtrim(Config::storagePath(), '/\\') . '/cache/price-checker-barcodes.json';
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

        try {
            $pool = self::buildSlideshowPool($config, $forceRefresh);
            self::writeSlideshowCache($cacheKey, $pool, $cacheSeconds);

            return self::pickSlideshowBatch($pool, (int) ($config['slideshow_count'] ?? 5), $excludeGuids);
        } catch (\Throwable) {
            return [];
        }
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

        return is_string($logo) && trim($logo) !== '' ? $logo : '';
    }

    public static function siteName(): string
    {
        $company = PortalSettingsService::companySettings();
        $name = trim((string) ($company['company_name'] ?? ''));

        return $name !== '' ? $name : 'جويش للتجارة';
    }

    public static function clearSlideshowCache(): void
    {
        $path = self::slideshowCachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function clearLookupCaches(): void
    {
        self::clearSlideshowCache();
        self::clearBarcodeCache();
    }

    /** @param array<string, mixed> $config @return list<array<string, mixed>> */
    private static function buildSlideshowPool(array $config, bool $forceRefresh): array
    {
        $mode = self::normalizeSlideshowMode((string) ($config['slideshow_mode'] ?? 'filter'));
        $count = (int) ($config['slideshow_count'] ?? 5);
        $poolSize = min(200, max($count * 8, 48));
        $offerSlug = trim((string) ($config['slideshow_offer_slug'] ?? ''));
        $useOfferPrices = (bool) ($config['slideshow_use_offer_prices'] ?? false);

        if ($mode === 'offer') {
            $offer = $offerSlug !== '' ? SpecialOfferService::activeOfferBySlug($offerSlug) : null;
            if ($offer === null) {
                return [];
            }
            $offerSlug = (string) ($offer['slug'] ?? $offerSlug);
            $selectionMode = (string) ($offer['selection_mode'] ?? 'filter');
            if ($selectionMode === 'manual') {
                $items = self::fetchManualMaterials(is_array($offer['material_guids'] ?? null) ? $offer['material_guids'] : [], $poolSize);
            } else {
                $rules = is_array($offer['filter_rules'] ?? null) ? $offer['filter_rules'] : [];
                $items = self::fetchFilteredMaterials($rules, $poolSize);
            }

            return self::mapSlideshowItems($items, $offerSlug, true);
        }

        if ($mode === 'manual') {
            $guids = is_array($config['slideshow_material_guids'] ?? null)
                ? $config['slideshow_material_guids']
                : self::loadManualMaterialGuids();
            $items = self::fetchManualMaterials($guids, $poolSize);
            $priceOfferSlug = $useOfferPrices ? $offerSlug : null;

            return self::mapSlideshowItems($items, $priceOfferSlug !== '' ? $priceOfferSlug : null, $useOfferPrices && $priceOfferSlug !== '');
        }

        $rules = is_array($config['slideshow_filter_rules'] ?? null) ? $config['slideshow_filter_rules'] : [];
        if ($rules === []) {
            $rules = ['has_image' => true, 'is_available' => true];
        }
        $items = self::fetchFilteredMaterials($rules, $forceRefresh ? 120 : 80);
        $priceOfferSlug = $useOfferPrices ? $offerSlug : null;

        return self::mapSlideshowItems($items, $priceOfferSlug !== '' ? $priceOfferSlug : null, $useOfferPrices && $priceOfferSlug !== '');
    }

    /** @param array<string, mixed> $rules @return list<array<string, mixed>> */
    private static function fetchFilteredMaterials(array $rules, int $pageSize): array
    {
        $query = HomeSectionService::materialsListQuery($rules, $pageSize);
        $response = ApiClient::get('/api/materials', $query, 25);
        if (!($response['ok'] ?? false)) {
            return [];
        }

        $items = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];

        return StockReservationService::filterSellableProducts($items);
    }

    /** @param list<string> $guids @return list<array<string, mixed>> */
    private static function fetchManualMaterials(array $guids, int $maxProducts): array
    {
        $guids = array_values(array_unique(array_filter(array_map('strval', $guids), static fn (string $g): bool => trim($g) !== '')));
        if ($guids === []) {
            return [];
        }

        shuffle($guids);
        $tryGuids = array_slice($guids, 0, min(count($guids), max($maxProducts * 3, $maxProducts)));
        $materialsByGuid = MaterialBatchService::fetchByGuids($tryGuids, 20);
        $candidates = [];
        foreach ($tryGuids as $guid) {
            $item = $materialsByGuid[$guid] ?? null;
            if (is_array($item)) {
                $candidates[] = $item;
            }
        }

        return StockReservationService::filterSellableProducts($candidates);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function mapSlideshowItems(array $items, ?string $offerSlug, bool $forceOfferPricing): array
    {
        if ($items === []) {
            return [];
        }

        if ($forceOfferPricing && $offerSlug !== null && trim($offerSlug) !== '') {
            $items = StoreCatalogService::withOfferPricing($items, trim($offerSlug));
        } elseif (!$forceOfferPricing) {
            $items = SpecialOfferService::applyPricingOverlays($items, null);
        }

        $pool = [];
        foreach ($items as $row) {
            if (!is_array($row) || !empty($row['isHidden'])) {
                continue;
            }
            $imageGuid = trim((string) ($row['productImageGuid'] ?? ''));
            if ($imageGuid === '') {
                continue;
            }
            $pool[] = [
                'imageGuid' => $imageGuid,
                'name' => (string) ($row['name'] ?? ''),
                'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
                'image' => self::materialImageUrl($imageGuid, true),
                'priceSp' => (float) ($row['unitSalePriceSyp'] ?? 0),
                'priceUsd' => (float) ($row['unitSalePriceUsd'] ?? 0),
                'isOfferPrice' => !empty($row['offerPricingApplied']) || !empty($row['isOfferPrice']),
            ];
        }

        return $pool;
    }

    /** @param list<string> $guids */
    private static function syncManualMaterials(array $guids): void
    {
        $pdo = Database::pdo();
        $pdo->exec('DELETE FROM price_checker_slideshow_materials');
        if ($guids === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO price_checker_slideshow_materials (material_guid, sort_order)
             VALUES (:guid, :sort_order)'
        );
        foreach (array_values($guids) as $index => $guid) {
            if (!preg_match('/^[0-9a-f-]{36}$/i', $guid)) {
                continue;
            }
            $stmt->execute([
                'guid' => $guid,
                'sort_order' => $index,
            ]);
        }
    }

    /** @return list<string> */
    private static function loadManualMaterialGuids(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT material_guid::text AS material_guid
                 FROM price_checker_slideshow_materials
                 ORDER BY sort_order ASC, created_at ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            return array_values(array_filter(array_map(
                static fn (array $row): string => trim((string) ($row['material_guid'] ?? '')),
                is_array($rows) ? $rows : []
            ), static fn (string $guid): bool => $guid !== ''));
        } catch (\Throwable) {
            return [];
        }
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
            foreach (['barcode', 'barCode', 'barCode2', 'barCode3', 'materialCode', 'code'] as $field) {
                $value = trim((string) ($item[$field] ?? ''));
                if ($value !== '' && strcasecmp($value, $barcode) === 0) {
                    return $item;
                }
            }
        }

        return null;
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
            'slideshow_mode' => 'filter',
            'slideshow_filter_rules' => ['has_image' => true, 'is_available' => true],
            'slideshow_offer_slug' => '',
            'slideshow_use_offer_prices' => false,
            'slideshow_material_guids' => [],
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $defaults @return array<string, mixed> */
    private static function normalizeRow(array $row, array $defaults): array
    {
        $filterRules = self::decodeFilterRules($row['slideshow_filter_rules'] ?? $defaults['slideshow_filter_rules']);
        if ($filterRules === [] && trim((string) ($row['slideshow_manufacturers'] ?? '')) !== '') {
            $filterRules = [
                'manufacturers' => self::stringList((string) $row['slideshow_manufacturers']),
                'has_image' => true,
                'is_available' => true,
            ];
        }

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
            'slideshow_mode' => self::normalizeSlideshowMode((string) ($row['slideshow_mode'] ?? $defaults['slideshow_mode'])),
            'slideshow_filter_rules' => $filterRules,
            'slideshow_offer_slug' => self::clipString((string) ($row['slideshow_offer_slug'] ?? ''), 120),
            'slideshow_use_offer_prices' => filter_var($row['slideshow_use_offer_prices'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private static function normalizeSlideshowMode(string $mode): string
    {
        return in_array($mode, ['filter', 'manual', 'offer'], true) ? $mode : 'filter';
    }

    /** @return array<string, mixed> */
    private static function decodeFilterRules(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @param array<string, mixed> $rules @return list<string> */
    private static function manufacturersFromRules(array $rules): array
    {
        return self::stringList($rules['manufacturers'] ?? []);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            $value = preg_split('/[,|\n]+/u', (string) $value) ?: [];
        }

        $result = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    private static function toNullableBool(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private static function toNullableFloat(string $value): ?float
    {
        $value = trim($value);

        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function intInRange(int $value, int $min, int $max, int $fallback): int
    {
        return ($value >= $min && $value <= $max) ? $value : $fallback;
    }

    private static function clipString(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }

    private static function normalizeIpListText(string $text): string
    {
        $ips = [];
        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '' && filter_var($line, FILTER_VALIDATE_IP)) {
                $ips[] = $line;
            }
        }

        return implode("\n", array_values(array_unique($ips)));
    }

    /** @param array<string, mixed> $config */
    private static function slideshowCacheKey(array $config): string
    {
        return hash('sha256', json_encode([
            'mode' => $config['slideshow_mode'] ?? 'filter',
            'rules' => $config['slideshow_filter_rules'] ?? [],
            'offer' => $config['slideshow_offer_slug'] ?? '',
            'use_offer_prices' => $config['slideshow_use_offer_prices'] ?? false,
            'manual' => $config['slideshow_material_guids'] ?? [],
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

        if ($payload !== false) {
            file_put_contents(self::slideshowCachePath(), $payload, LOCK_EX);
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
