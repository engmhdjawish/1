<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

/**
 * Website-only special offers. Prices from Amine API are overlaid at display/cart time.
 * Conflict rule: lowest effective package price for the customer; tie → higher priority; tie → newer offer.
 */
final class SpecialOfferService
{
    private const FILTER_KEYWORD = 'keyword';
    private const FILTER_MATERIAL_TYPE = 'material_type';
    private const FILTER_AGE_CATEGORY = 'age_category';
    private const FILTER_MANUFACTURER = 'manufacturer';
    private const FILTER_SIZE_RANGE = 'size_range';
    private const FILTER_COUNTRY_ORIGIN = 'country_origin';
    private const FILTER_STORE_GUID = 'store_guid';
    private const FILTER_GROUP_GUID = 'group_guid';
    private const FILTER_IS_AVAILABLE = 'is_available';
    private const FILTER_HAS_IMAGE = 'has_image';

    /** @return list<array<string, mixed>> */
    public static function activeHomeSections(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    selection_mode::text AS selection_mode, discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,
                    min_packages, max_packages, max_products, home_sort_order
             FROM special_offers
             WHERE is_active = TRUE
               AND show_on_home = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY home_sort_order ASC, created_at ASC'
        );
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($sections as &$section) {
            $offerId = (string) $section['id'];
            $parsed = self::parseFilterRows(self::filtersForOffer($offerId));
            $section['filter_rules'] = $parsed['rules'];
            $section['material_guids'] = self::manualProducts($offerId);
            $section['is_offer_section'] = true;
            $products = self::loadOfferProducts($section);
            $section['products'] = self::attachOfferPricing($products, $section);
            $section['display_options'] = ['show_images' => true, 'price_mode' => 'both'];
        }

        return $sections;
    }

    /** @return list<array<string, mixed>> */
    public static function adminList(): array
    {
        return Database::pdo()->query(
            'SELECT so.id::text AS id, so.slug, so.title_ar, so.subtitle_ar, so.badge_text_ar,
                    so.selection_mode::text AS selection_mode, so.discount_type::text AS discount_type,
                    so.discount_percent, so.fixed_price_syp, so.fixed_price_usd,
                    so.starts_at, so.ends_at,
                    CASE WHEN so.is_active THEN 1 ELSE 0 END AS is_active,
                    so.priority, so.min_packages, so.max_packages, so.max_products,
                    CASE WHEN so.show_on_home THEN 1 ELSE 0 END AS show_on_home,
                    so.home_sort_order, so.updated_at,
                    COALESCE(fp.filters_count, 0)::int AS filters_count,
                    COALESCE(mp.products_count, 0)::int AS products_count
             FROM special_offers so
             LEFT JOIN (
                SELECT offer_id, COUNT(*)::int AS filters_count
                FROM special_offer_filters GROUP BY offer_id
             ) fp ON fp.offer_id = so.id
             LEFT JOIN (
                SELECT offer_id, COUNT(*)::int AS products_count
                FROM special_offer_products GROUP BY offer_id
             ) mp ON mp.offer_id = so.id
             ORDER BY so.home_sort_order ASC, so.created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    selection_mode::text AS selection_mode, discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,
                    starts_at::text AS starts_at, ends_at::text AS ends_at,
                    CASE WHEN is_active THEN 1 ELSE 0 END AS is_active,
                    priority, min_packages, max_packages, max_products,
                    CASE WHEN show_on_home THEN 1 ELSE 0 END AS show_on_home,
                    home_sort_order
             FROM special_offers WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $parsed = self::parseFilterRows(self::filtersForOffer($id));
        $row['filter_rules'] = $parsed['rules'];
        $row['material_guids'] = self::manualProducts($id);
        $row['manual_products'] = self::loadManualProductDetails($row['material_guids']);
        $row['preview_products'] = self::attachOfferPricing(self::loadOfferProducts($row), $row);

        return $row;
    }

    /** @param array<string, mixed> $payload */
    public static function save(array $payload, ?string $userId): array
    {
        $id = trim((string) ($payload['id'] ?? ''));
        $title = trim((string) ($payload['title_ar'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'message' => 'عنوان العرض مطلوب.', 'id' => null];
        }

        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::normalizeSlug($title);
        }
        if ($slug === '') {
            $slug = 'offer-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $discountType = (string) ($payload['discount_type'] ?? 'percent');
        if (!in_array($discountType, ['percent', 'fixed_price'], true)) {
            $discountType = 'percent';
        }

        $selectionMode = (string) ($payload['selection_mode'] ?? 'filter');
        if (!in_array($selectionMode, ['manual', 'filter'], true)) {
            $selectionMode = 'filter';
        }

        $pdo = Database::pdo();
        $params = [
            'slug' => $slug,
            'title_ar' => $title,
            'subtitle_ar' => trim((string) ($payload['subtitle_ar'] ?? '')) ?: null,
            'badge_text_ar' => trim((string) ($payload['badge_text_ar'] ?? '')) ?: null,
            'banner_image_url' => trim((string) ($payload['banner_image_url'] ?? '')) ?: null,
            'selection_mode' => $selectionMode,
            'discount_type' => $discountType,
            'discount_percent' => $discountType === 'percent' ? self::toNullableFloat((string) ($payload['discount_percent'] ?? '')) : null,
            'fixed_price_syp' => $discountType === 'fixed_price' ? self::toNullableFloat((string) ($payload['fixed_price_syp'] ?? '')) : null,
            'fixed_price_usd' => $discountType === 'fixed_price' ? self::toNullableFloat((string) ($payload['fixed_price_usd'] ?? '')) : null,
            'starts_at' => trim((string) ($payload['starts_at'] ?? '')) ?: date('Y-m-d H:i:s'),
            'ends_at' => trim((string) ($payload['ends_at'] ?? '')) ?: null,
            'is_active' => !empty($payload['is_active']),
            'priority' => (int) ($payload['priority'] ?? 0),
            'min_packages' => self::toNullableFloat((string) ($payload['min_packages'] ?? '')),
            'max_packages' => self::toNullableFloat((string) ($payload['max_packages'] ?? '')),
            'max_products' => max(1, min(48, (int) ($payload['max_products'] ?? 12))),
            'show_on_home' => !empty($payload['show_on_home']),
            'home_sort_order' => (int) ($payload['home_sort_order'] ?? 0),
            'updated_by_web_user_id' => $userId ?: null,
        ];

        if ($id === '') {
            $stmt = $pdo->prepare(
                'INSERT INTO special_offers (
                    slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    selection_mode, discount_type, discount_percent, fixed_price_syp, fixed_price_usd,
                    starts_at, ends_at, is_active, priority, min_packages, max_packages,
                    max_products, show_on_home, home_sort_order, updated_by_web_user_id
                 ) VALUES (
                    :slug, :title_ar, :subtitle_ar, :badge_text_ar, :banner_image_url,
                    :selection_mode, :discount_type, :discount_percent, :fixed_price_syp, :fixed_price_usd,
                    :starts_at, :ends_at, :is_active, :priority, :min_packages, :max_packages,
                    :max_products, :show_on_home, :home_sort_order, :updated_by_web_user_id
                 ) RETURNING id::text'
            );
            $stmt->execute($params);
            $id = (string) $stmt->fetchColumn();
        } else {
            $params['id'] = $id;
            $stmt = $pdo->prepare(
                'UPDATE special_offers SET
                    slug = :slug, title_ar = :title_ar, subtitle_ar = :subtitle_ar,
                    badge_text_ar = :badge_text_ar, banner_image_url = :banner_image_url,
                    selection_mode = :selection_mode, discount_type = :discount_type,
                    discount_percent = :discount_percent, fixed_price_syp = :fixed_price_syp, fixed_price_usd = :fixed_price_usd,
                    starts_at = :starts_at, ends_at = :ends_at, is_active = :is_active,
                    priority = :priority, min_packages = :min_packages, max_packages = :max_packages,
                    max_products = :max_products, show_on_home = :show_on_home,
                    home_sort_order = :home_sort_order, updated_by_web_user_id = :updated_by_web_user_id
                 WHERE id = :id'
            );
            $stmt->execute($params);
        }

        self::syncFilters($id, is_array($payload['filter_rules'] ?? null) ? $payload['filter_rules'] : []);
        self::syncManualProducts($id, is_array($payload['material_guids'] ?? null) ? $payload['material_guids'] : []);

        return ['ok' => true, 'message' => 'تم حفظ العرض.', 'id' => $id];
    }

    /** Full active offer row (pricing + rules) for storefront deep-links. */
    public static function activeOfferBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    selection_mode::text AS selection_mode, discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,
                    min_packages, max_packages, max_products, priority, starts_at
             FROM special_offers
             WHERE slug = :slug
               AND is_active = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $id = (string) $row['id'];
        $parsed = self::parseFilterRows(self::filtersForOffer($id));
        $row['filter_rules'] = $parsed['rules'];
        $row['material_guids'] = self::manualProducts($id);

        return $row;
    }

    /** @return array<string, mixed>|null */
    public static function storeContextBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, selection_mode::text AS selection_mode
             FROM special_offers
             WHERE slug = :slug
               AND is_active = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $id = (string) $row['id'];
        $parsed = self::parseFilterRows(self::filtersForOffer($id));

        return [
            'id' => $id,
            'slug' => (string) $row['slug'],
            'title_ar' => (string) $row['title_ar'],
            'subtitle_ar' => (string) ($row['subtitle_ar'] ?? ''),
            'selection_mode' => (string) ($row['selection_mode'] ?? 'filter'),
            'filter_rules' => $parsed['rules'],
            'material_guids' => self::manualProducts($id),
            'is_offer_section' => true,
        ];
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM special_offers WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public static function toggleActive(string $id, bool $active): bool
    {
        $stmt = Database::pdo()->prepare('UPDATE special_offers SET is_active = :active WHERE id = :id');
        $stmt->execute(['id' => $id, 'active' => $active]);

        return $stmt->rowCount() > 0;
    }

    /** Best active offer for a material on the public store. */
    public static function resolveForMaterial(string $materialGuid, ?array $material = null): ?array
    {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return null;
        }

        if ($material === null) {
            try {
                $response = ApiClient::get('/api/materials/' . rawurlencode($materialGuid));
                if (!$response['ok'] || !is_array($response['data'])) {
                    return null;
                }
                $material = $response['data'];
            } catch (\Throwable) {
                return null;
            }
        }

        $offers = self::activeOffers();
        $best = null;
        $bestScore = null;

        foreach ($offers as $offer) {
            if (!self::offerIncludesMaterial($offer, $materialGuid, $material)) {
                continue;
            }
            $pricing = self::computePricing($material, $offer);
            $score = self::offerSortScore($pricing, $offer);
            if ($best === null || $score < $bestScore) {
                $best = $offer;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $material */
    public static function pricingOverlay(array $material, ?array $offer = null, ?string $offerSlug = null): array
    {
        $guid = trim((string) ($material['materialGuid'] ?? $material['MaterialGuid'] ?? ''));
        if ($offer === null && $offerSlug !== null && trim($offerSlug) !== '') {
            $offer = self::activeOfferBySlug($offerSlug);
            if ($offer !== null && !self::offerIncludesMaterial($offer, $guid, $material)) {
                $offer = null;
            }
        }
        $offer ??= self::resolveForMaterial($guid, $material);
        if ($offer === null) {
            return ['has_offer' => false];
        }

        $pricing = self::computePricing($material, $offer);

        return array_merge([
            'has_offer' => true,
            'offer' => $offer,
            'offer_badge' => trim((string) ($offer['badge_text_ar'] ?? '')) ?: self::defaultBadge($offer),
        ], $pricing);
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $offer
     * @return list<array<string, mixed>>
     */
    public static function attachOfferPricing(array $products, array $offer): array
    {
        $result = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $overlay = self::computePricing($product, $offer);
            $result[] = array_merge($product, [
                'offer' => $offer,
                'has_offer' => true,
                'offer_badge' => trim((string) ($offer['badge_text_ar'] ?? '')) ?: self::defaultBadge($offer),
            ], $overlay);
        }

        return $result;
    }

    /** @return array{ok: bool, message: string, quantity: float} */
    public static function validatePackageQuantity(string $materialGuid, float $quantity, ?array $offer = null): array
    {
        $quantity = max(0.0, round($quantity));
        if ($quantity <= 0) {
            return ['ok' => true, 'message' => '', 'quantity' => 0.0];
        }

        $globalMax = StorePolicyService::maxPackagesPerMaterial();
        if ($globalMax !== null && $quantity > $globalMax) {
            return [
                'ok' => false,
                'message' => 'الحد الأقصى للطلب هو ' . self::formatQty($globalMax) . ' طرد لهذه المادة.',
                'quantity' => $globalMax,
            ];
        }

        if ($offer !== null) {
            $min = isset($offer['min_packages']) && is_numeric((string) $offer['min_packages'])
                ? (float) $offer['min_packages'] : null;
            $max = isset($offer['max_packages']) && is_numeric((string) $offer['max_packages'])
                ? (float) $offer['max_packages'] : null;
            if ($min !== null && $quantity < $min) {
                return [
                    'ok' => false,
                    'message' => 'الحد الأدنى لهذا العرض هو ' . self::formatQty($min) . ' طرد.',
                    'quantity' => $min,
                ];
            }
            if ($max !== null && $quantity > $max) {
                return [
                    'ok' => false,
                    'message' => 'الحد الأقصى لهذا العرض هو ' . self::formatQty($max) . ' طرد.',
                    'quantity' => $max,
                ];
            }
        }

        return ['ok' => true, 'message' => '', 'quantity' => $quantity];
    }

    /** @param array<string, mixed> $material @param array<string, mixed> $offer */
    public static function computePricing(array $material, array $offer): array
    {
        $packaging = ShareCartService::packaging($material);
        $baseUnitSp = ShareCartService::unitSalePriceSp($material);
        $baseUnitUsd = ShareCartService::unitSalePriceUsd($material);
        $basePackSp = $baseUnitSp * $packaging;
        $basePackUsd = $baseUnitUsd * $packaging;

        $discountType = (string) ($offer['discount_type'] ?? 'percent');
        if ($discountType === 'fixed_price') {
            $effPackSp = is_numeric((string) ($offer['fixed_price_syp'] ?? ''))
                ? (float) $offer['fixed_price_syp'] : $basePackSp;
            $effPackUsd = is_numeric((string) ($offer['fixed_price_usd'] ?? ''))
                ? (float) $offer['fixed_price_usd'] : $basePackUsd;
            if ($offer['fixed_price_syp'] === null || $offer['fixed_price_syp'] === '') {
                $effPackSp = $basePackSp;
            }
            if ($offer['fixed_price_usd'] === null || $offer['fixed_price_usd'] === '') {
                $effPackUsd = $basePackUsd;
            }
        } else {
            $pct = min(100.0, max(0.0, (float) ($offer['discount_percent'] ?? 0)));
            $factor = 1.0 - ($pct / 100.0);
            $effPackSp = $basePackSp * $factor;
            $effPackUsd = $basePackUsd * $factor;
        }

        $effUnitSp = $packaging > 0 ? $effPackSp / $packaging : $effPackSp;
        $effUnitUsd = $packaging > 0 ? $effPackUsd / $packaging : $effPackUsd;

        return [
            'original_unit_sale_price_sp' => $baseUnitSp,
            'original_unit_sale_price_usd' => $baseUnitUsd,
            'original_package_sale_price_sp' => $basePackSp,
            'original_package_sale_price_usd' => $basePackUsd,
            'effective_unit_sale_price_sp' => $effUnitSp,
            'effective_unit_sale_price_usd' => $effUnitUsd,
            'effective_package_sale_price_sp' => $effPackSp,
            'effective_package_sale_price_usd' => $effPackUsd,
            'unitSalePriceSyp' => $effUnitSp,
            'unitSalePriceUsd' => $effUnitUsd,
        ];
    }

    /** @param array<string, mixed> $offer */
    public static function applyToCartLine(array $line, array $offer): array
    {
        $material = [
            'unitSalePriceSyp' => (float) ($line['unit_sale_price_sp'] ?? 0),
            'unitSalePriceUsd' => (float) ($line['unit_sale_price_usd'] ?? 0),
            'packageConversionFactor' => (float) ($line['packaging'] ?? $line['package_factor'] ?? 1),
        ];
        $pricing = self::computePricing($material, $offer);
        $line['original_unit_sale_price_sp'] = $pricing['original_unit_sale_price_sp'];
        $line['original_unit_sale_price_usd'] = $pricing['original_unit_sale_price_usd'];
        $line['original_sale_price_sp'] = $pricing['original_package_sale_price_sp'];
        $line['original_sale_price_usd'] = $pricing['original_package_sale_price_usd'];
        $line['unit_sale_price_sp'] = $pricing['effective_unit_sale_price_sp'];
        $line['unit_sale_price_usd'] = $pricing['effective_unit_sale_price_usd'];
        $line['sale_price_sp'] = $pricing['effective_package_sale_price_sp'];
        $line['sale_price_usd'] = $pricing['effective_package_sale_price_usd'];
        $line['special_offer_id'] = (string) ($offer['id'] ?? '');
        $line['offer_badge'] = trim((string) ($offer['badge_text_ar'] ?? '')) ?: self::defaultBadge($offer);

        return ShareCartService::normalizeLine($line);
    }

    // --- internals ---

    /** @return list<array<string, mixed>> */
    private static function activeOffers(): array
    {
        $rows = Database::pdo()->query(
            'SELECT id::text AS id, slug, title_ar, badge_text_ar, selection_mode::text AS selection_mode,
                    discount_type::text AS discount_type, discount_percent, fixed_price_syp, fixed_price_usd,
                    priority, min_packages, max_packages, starts_at
             FROM special_offers
             WHERE is_active = TRUE AND starts_at <= NOW() AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY priority DESC, starts_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $id = (string) $row['id'];
            $parsed = self::parseFilterRows(self::filtersForOffer($id));
            $row['filter_rules'] = $parsed['rules'];
            $row['material_guids'] = self::manualProducts($id);
        }

        return $rows;
    }

    /** @param array<string, mixed> $offer */
    public static function offerIncludesMaterial(array $offer, string $guid, array $material): bool
    {
        if ((string) ($offer['selection_mode'] ?? '') === 'manual') {
            return in_array($guid, $offer['material_guids'] ?? [], true);
        }

        return self::materialMatchesRules($material, is_array($offer['filter_rules'] ?? null) ? $offer['filter_rules'] : []);
    }

    /** @param array<string, mixed> $rules */
    private static function materialMatchesRules(array $material, array $rules): bool
    {
        $field = static function (string ...$keys) use ($material): string {
            foreach ($keys as $key) {
                $v = trim((string) ($material[$key] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }

            return '';
        };

        foreach ($rules['material_types'] ?? [] as $value) {
            if ($field('materialType', 'MaterialType', 'color', 'Color') !== (string) $value) {
                return false;
            }
        }
        foreach ($rules['age_categories'] ?? [] as $value) {
            if ($field('ageCategory', 'AgeCategory', 'provenance', 'Provenance') !== (string) $value) {
                return false;
            }
        }
        foreach ($rules['manufacturers'] ?? [] as $value) {
            if ($field('manufacturer', 'Manufacturer', 'company', 'Company') !== (string) $value) {
                return false;
            }
        }
        foreach ($rules['size_ranges'] ?? [] as $value) {
            if ($field('sizeRange', 'SizeRange', 'dim', 'Dim') !== (string) $value) {
                return false;
            }
        }
        foreach ($rules['country_origins'] ?? [] as $value) {
            if ($field('countryOfOrigin', 'CountryOfOrigin', 'origin', 'Origin') !== (string) $value) {
                return false;
            }
        }
        $groupGuid = strtolower($field('groupGuid', 'GroupGuid'));
        foreach ($rules['group_guids'] ?? [] as $value) {
            if ($groupGuid !== strtolower((string) $value)) {
                return false;
            }
        }

        if (($rules['is_available'] ?? null) === true && !(bool) ($material['isAvailable'] ?? $material['IsAvailable'] ?? true)) {
            return false;
        }
        if (($rules['has_image'] ?? null) === true) {
            $img = $field('productImageGuid', 'ProductImageGuid', 'pictureGuid', 'PictureGUID');
            if ($img === '') {
                return false;
            }
        }

        $keyword = trim((string) ($rules['keyword'] ?? ''));
        if ($keyword !== '') {
            $hay = strtolower($field('name', 'Name') . ' ' . $field('materialCode', 'MaterialCode'));
            if (!str_contains($hay, strtolower($keyword))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $pricing @param array<string, mixed> $offer */
    private static function offerSortScore(array $pricing, array $offer): float
    {
        $usd = (float) ($pricing['effective_package_sale_price_usd'] ?? 0);
        $sp = (float) ($pricing['effective_package_sale_price_sp'] ?? 0);
        $priceScore = $usd > 0 ? $usd : ($sp > 0 ? $sp / 10000.0 : PHP_FLOAT_MAX);
        $priority = (int) ($offer['priority'] ?? 0);

        return $priceScore - ($priority * 0.000001);
    }

    /** @param array<string, mixed> $offer */
    private static function defaultBadge(array $offer): string
    {
        if ((string) ($offer['discount_type'] ?? '') === 'percent') {
            $pct = (float) ($offer['discount_percent'] ?? 0);
            if ($pct > 0) {
                return '-' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
            }
        }

        return 'عرض';
    }

    /** @param array<string, mixed> $offer */
    private static function loadOfferProducts(array $offer): array
    {
        $max = max(1, (int) ($offer['max_products'] ?? 12));
        if ((string) ($offer['selection_mode'] ?? '') === 'manual') {
            return self::pickManual(is_array($offer['material_guids'] ?? null) ? $offer['material_guids'] : [], $max);
        }

        $rules = is_array($offer['filter_rules'] ?? null) ? $offer['filter_rules'] : [];

        return self::pickFiltered($rules, $max);
    }

    /** @param list<string> $guids */
    private static function pickManual(array $guids, int $max): array
    {
        $guids = array_values(array_unique(array_filter(array_map('strval', $guids), static fn ($g) => trim($g) !== '')));
        shuffle($guids);
        $items = [];
        foreach ($guids as $guid) {
            if (count($items) >= $max) {
                break;
            }
            try {
                $r = ApiClient::get('/api/materials/' . rawurlencode($guid));
                if ($r['ok'] && is_array($r['data'])) {
                    $items[] = $r['data'];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        shuffle($items);

        return array_slice($items, 0, $max);
    }

    /** @param array<string, mixed> $rules */
    private static function pickFiltered(array $rules, int $max): array
    {
        $pool = min(200, max($max * 8, 48));
        $query = self::buildApiQuery($rules, $pool);
        try {
            $r = ApiClient::get('/api/materials', $query);
            if (!$r['ok']) {
                return [];
            }
            $items = $r['data']['items'] ?? [];
            if (!is_array($items)) {
                return [];
            }
            shuffle($items);

            return array_slice($items, 0, $max);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<string> $guids @return list<array{guid: string, name: string, code: string}> */
    private static function loadManualProductDetails(array $guids): array
    {
        $items = [];
        foreach ($guids as $guid) {
            try {
                $r = ApiClient::get('/api/materials/' . rawurlencode($guid));
                if ($r['ok'] && is_array($r['data'])) {
                    $d = $r['data'];
                    $items[] = [
                        'guid' => $guid,
                        'name' => trim((string) ($d['name'] ?? '')),
                        'code' => trim((string) ($d['materialCode'] ?? '')),
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $rules */
    private static function syncFilters(string $offerId, array $rules): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM special_offer_filters WHERE offer_id = :id')->execute(['id' => $offerId]);
        $stmt = $pdo->prepare(
            'INSERT INTO special_offer_filters (offer_id, filter_type, value_ar) VALUES (:offer_id, :filter_type, :value_ar)'
        );
        $insert = static function (string $type, string $value) use ($stmt, $offerId): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            $stmt->execute(['offer_id' => $offerId, 'filter_type' => $type, 'value_ar' => $value]);
        };
        $insertList = static function (string $type, mixed $list) use ($insert): void {
            foreach (self::stringList($list) as $value) {
                $insert($type, $value);
            }
        };

        $insert(self::FILTER_KEYWORD, trim((string) ($rules['keyword'] ?? '')));
        $insertList(self::FILTER_MATERIAL_TYPE, $rules['material_types'] ?? []);
        $insertList(self::FILTER_AGE_CATEGORY, $rules['age_categories'] ?? []);
        $insertList(self::FILTER_MANUFACTURER, $rules['manufacturers'] ?? []);
        $insertList(self::FILTER_SIZE_RANGE, $rules['size_ranges'] ?? []);
        $insertList(self::FILTER_COUNTRY_ORIGIN, $rules['country_origins'] ?? []);
        $insertList(self::FILTER_STORE_GUID, $rules['store_guids'] ?? []);
        $insertList(self::FILTER_GROUP_GUID, $rules['group_guids'] ?? []);
        if (($rules['is_available'] ?? null) === true) {
            $insert(self::FILTER_IS_AVAILABLE, '1');
        } elseif (($rules['is_available'] ?? null) === false) {
            $insert(self::FILTER_IS_AVAILABLE, '0');
        }
        if (($rules['has_image'] ?? null) === true) {
            $insert(self::FILTER_HAS_IMAGE, '1');
        } elseif (($rules['has_image'] ?? null) === false) {
            $insert(self::FILTER_HAS_IMAGE, '0');
        }
    }

    /** @param list<string> $guids */
    private static function syncManualProducts(string $offerId, array $guids): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM special_offer_products WHERE offer_id = :id')->execute(['id' => $offerId]);
        $stmt = $pdo->prepare(
            'INSERT INTO special_offer_products (offer_id, material_guid, sort_order) VALUES (:offer_id, :guid, :sort)'
        );
        $sort = 0;
        foreach (self::stringList($guids) as $guid) {
            $stmt->execute(['offer_id' => $offerId, 'guid' => $guid, 'sort' => $sort++]);
        }
    }

    /** @return list<array{filter_type: string, value_ar: string}> */
    private static function filtersForOffer(string $offerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT filter_type, value_ar FROM special_offer_filters WHERE offer_id = :id'
        );
        $stmt->execute(['id' => $offerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array{filter_type: string, value_ar: string}> $rows @return array{rules: array<string, mixed>} */
    private static function parseFilterRows(array $rows): array
    {
        $rules = [
            'keyword' => '',
            'material_types' => [],
            'age_categories' => [],
            'manufacturers' => [],
            'size_ranges' => [],
            'country_origins' => [],
            'store_guids' => [],
            'group_guids' => [],
            'is_available' => null,
            'has_image' => null,
        ];
        foreach ($rows as $row) {
            $type = (string) ($row['filter_type'] ?? '');
            $value = (string) ($row['value_ar'] ?? '');
            match ($type) {
                self::FILTER_KEYWORD => $rules['keyword'] = $value,
                self::FILTER_MATERIAL_TYPE => $rules['material_types'][] = $value,
                self::FILTER_AGE_CATEGORY => $rules['age_categories'][] = $value,
                self::FILTER_MANUFACTURER => $rules['manufacturers'][] = $value,
                self::FILTER_SIZE_RANGE => $rules['size_ranges'][] = $value,
                self::FILTER_COUNTRY_ORIGIN => $rules['country_origins'][] = $value,
                self::FILTER_STORE_GUID => $rules['store_guids'][] = $value,
                self::FILTER_GROUP_GUID => $rules['group_guids'][] = $value,
                self::FILTER_IS_AVAILABLE => $rules['is_available'] = self::toNullableBool($value),
                self::FILTER_HAS_IMAGE => $rules['has_image'] = self::toNullableBool($value),
                default => null,
            };
        }

        return ['rules' => $rules];
    }

    /** @return list<string> */
    private static function manualProducts(string $offerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT material_guid::text FROM special_offer_products WHERE offer_id = :id ORDER BY sort_order'
        );
        $stmt->execute(['id' => $offerId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string, mixed> $rules */
    private static function buildApiQuery(array $rules, int $pageSize): array
    {
        $query = ['page' => 1, 'pageSize' => $pageSize, 'sort' => 'number:asc'];
        $keyword = trim((string) ($rules['keyword'] ?? ''));
        if ($keyword !== '') {
            $query['search'] = $keyword;
        }
        $csv = static fn (?string $e, string $v): string => $e === null || $e === '' ? $v : $e . ',' . $v;
        foreach ($rules['material_types'] ?? [] as $v) {
            $query['materialTypes'] = $csv($query['materialTypes'] ?? null, (string) $v);
        }
        foreach ($rules['age_categories'] ?? [] as $v) {
            $query['ageCategories'] = $csv($query['ageCategories'] ?? null, (string) $v);
        }
        foreach ($rules['manufacturers'] ?? [] as $v) {
            $query['manufacturers'] = $csv($query['manufacturers'] ?? null, (string) $v);
        }
        foreach ($rules['size_ranges'] ?? [] as $v) {
            $query['sizeRanges'] = $csv($query['sizeRanges'] ?? null, (string) $v);
        }
        foreach ($rules['country_origins'] ?? [] as $v) {
            $query['countryOfOrigins'] = $csv($query['countryOfOrigins'] ?? null, (string) $v);
        }
        foreach ($rules['store_guids'] ?? [] as $v) {
            $query['storeGuids'] = $csv($query['storeGuids'] ?? null, (string) $v);
        }
        foreach ($rules['group_guids'] ?? [] as $v) {
            $query['groupGuids'] = $csv($query['groupGuids'] ?? null, (string) $v);
        }
        if (($rules['is_available'] ?? null) === true) {
            $query['isAvailable'] = 'true';
        } elseif (($rules['is_available'] ?? null) === false) {
            $query['isAvailable'] = 'false';
        }
        if (($rules['has_image'] ?? null) === true) {
            $query['hasImage'] = 'true';
        } elseif (($rules['has_image'] ?? null) === false) {
            $query['hasImage'] = 'false';
        }

        return $query;
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
        return is_numeric($value) ? (float) $value : null;
    }

    private static function normalizeSlug(string $value): string
    {
        $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
        $value = preg_replace('/[\s_]+/u', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9\-]/u', '', $value) ?? '';

        return trim($value, '-');
    }

    private static function formatQty(float $qty): string
    {
        $formatted = number_format($qty, 2, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function formatQuantityLabel(float $qty): string
    {
        return self::formatQty($qty);
    }
}
