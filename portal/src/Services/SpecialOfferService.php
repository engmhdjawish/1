<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\PriceFx;
use Portal\Support\ResponseCache;
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
    private const FILTER_MIN_WAREHOUSE_QUANTITY = 'min_warehouse_quantity';
    private const FILTER_MAX_WAREHOUSE_QUANTITY = 'max_warehouse_quantity';
    private const FILTER_MIN_UNIT_SALE_PRICE_SYP = 'min_unit_sale_price_syp';
    private const FILTER_MAX_UNIT_SALE_PRICE_SYP = 'max_unit_sale_price_syp';
    private const FILTER_MIN_UNIT_SALE_PRICE_USD = 'min_unit_sale_price_usd';
    private const FILTER_MAX_UNIT_SALE_PRICE_USD = 'max_unit_sale_price_usd';
    private const FILTER_MIN_UNIT_PURCHASE_PRICE_USD = 'min_unit_purchase_price_usd';
    private const FILTER_MAX_UNIT_PURCHASE_PRICE_USD = 'max_unit_purchase_price_usd';

    private const OPTION_SHOW_IMAGES = 'option_show_images';
    private const OPTION_PRICE_MODE = 'option_price_mode';

    /** @return array{show_images: bool, price_mode: string} */
    public static function defaultDisplayOptions(): array
    {
        return [
            'show_images' => true,
            'price_mode' => 'both',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function activeHomeSections(): array
    {
        $cacheKey = self::activeHomeSectionsCacheKey();

        return ResponseCache::remember($cacheKey, 600, static fn (): array => self::buildActiveHomeSections());
    }

    /** @return list<array<string, mixed>> */
    public static function activeHomeSectionShells(): array
    {
        return self::buildActiveHomeSectionShells();
    }

    /** @return list<array<string, mixed>> */
    private static function buildActiveHomeSectionShells(): array
    {
        $pricingCols = self::hasExtendedOfferPricingColumns()
            ? 'selection_mode::text AS selection_mode, pricing_scope,
                    discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,
                    fixed_amount_syp, fixed_amount_usd,'
            : 'selection_mode::text AS selection_mode,
                    discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,';

        $stmt = Database::pdo()->query(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    ' . $pricingCols . '
                    min_packages, max_packages, max_products, home_sort_order,
                    ends_at::text AS ends_at
             FROM special_offers
             WHERE is_active = TRUE
               AND show_on_home = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY home_sort_order ASC, created_at ASC'
        );
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($sections === []) {
            return [];
        }

        $offerIds = array_map(static fn (array $section): string => (string) $section['id'], $sections);
        $filtersByOffer = self::batchFiltersForOffers($offerIds);
        $manualByOffer = self::batchManualProductRowsForOffers($offerIds);

        foreach ($sections as &$section) {
            $offerId = (string) $section['id'];
            $parsed = self::parseFilterRows($filtersByOffer[$offerId] ?? []);
            $manual = $manualByOffer[$offerId] ?? ['guids' => [], 'overrides' => []];
            $section['filter_rules'] = $parsed['rules'];
            $section['material_guids'] = $manual['guids'];
            $section['product_overrides'] = $manual['overrides'];
            $section['pricing_scope'] = (string) ($section['pricing_scope'] ?? 'offer');
            $section['is_offer_section'] = true;
            $section['products'] = [];
            $section['display_options'] = $parsed['display_options'];
        }

        return $sections;
    }

    /** @return list<array<string, mixed>> */
    private static function buildActiveHomeSections(): array
    {
        $sections = self::buildActiveHomeSectionShells();
        foreach ($sections as &$section) {
            $products = self::loadOfferProducts($section);
            $section['products'] = self::attachOfferPricing($products, $section);
        }
        unset($section);

        return $sections;
    }

    private static function activeHomeSectionsCacheKey(): string
    {
        $rows = Database::pdo()->query(
            'SELECT id::text AS id, updated_at::text AS updated_at, home_sort_order, max_products,
                    starts_at::text AS starts_at, ends_at::text AS ends_at
             FROM special_offers
             WHERE is_active = TRUE
               AND show_on_home = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        // v2: shells include pricing_scope + per-material overrides for correct home prices.
        return 'home_offers_v2:' . hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Highest percent discount in an offer (header or per-material overrides).
     *
     * @param array<string, mixed> $offer
     */
    public static function maxPercentDiscount(array $offer): float
    {
        $max = 0.0;
        $headerType = (string) ($offer['discount_type'] ?? 'percent');
        if ($headerType === 'percent') {
            $max = max($max, (float) ($offer['discount_percent'] ?? 0));
        }

        if ((string) ($offer['pricing_scope'] ?? 'offer') !== 'per_material') {
            return min(100.0, max(0.0, $max));
        }

        $overrides = is_array($offer['product_overrides'] ?? null) ? $offer['product_overrides'] : [];
        foreach ($overrides as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['discount_type'] ?? ''));
            if ($type !== '' && $type !== 'percent') {
                continue;
            }
            if ($type === '' && $headerType !== 'percent') {
                continue;
            }
            $pct = (float) ($row['discount_percent'] ?? 0);
            if ($pct > $max) {
                $max = $pct;
            }
        }

        return min(100.0, max(0.0, $max));
    }

    /**
     * Whether per-material percent discounts differ (so the header should say "حتى").
     *
     * @param array<string, mixed> $offer
     */
    public static function hasVariedPercentDiscounts(array $offer): bool
    {
        if ((string) ($offer['pricing_scope'] ?? 'offer') !== 'per_material') {
            return false;
        }
        $overrides = is_array($offer['product_overrides'] ?? null) ? $offer['product_overrides'] : [];
        $percents = [];
        foreach ($overrides as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['discount_type'] ?? 'percent'));
            if ($type !== 'percent') {
                continue;
            }
            $pct = round((float) ($row['discount_percent'] ?? 0), 2);
            if ($pct > 0) {
                $percents[$pct] = true;
            }
        }

        return count($percents) > 1;
    }

    /** @return list<array{show_images: bool, price_mode: string}> */
    public static function activeHomeOfferDisplayOptions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $offerIds = Database::pdo()->query(
            'SELECT id::text AS id
             FROM special_offers
             WHERE is_active = TRUE
               AND show_on_home = TRUE
               AND starts_at <= NOW()
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY home_sort_order ASC, created_at ASC'
        )->fetchAll(PDO::FETCH_COLUMN);

        $options = [];
        foreach ($offerIds as $offerId) {
            $parsed = self::parseFilterRows(self::filtersForOffer((string) $offerId));
            $options[] = $parsed['display_options'];
        }

        $cache = $options;

        return $options;
    }

    /** @return list<array<string, mixed>> */
    public static function adminList(): array
    {
        $pricingCols = self::hasExtendedOfferPricingColumns()
            ? 'so.pricing_scope, so.discount_type::text AS discount_type,
                    so.discount_percent, so.fixed_price_syp, so.fixed_price_usd,
                    so.fixed_amount_syp, so.fixed_amount_usd,'
            : 'so.discount_type::text AS discount_type,
                    so.discount_percent, so.fixed_price_syp, so.fixed_price_usd,';

        return Database::pdo()->query(
            'SELECT so.id::text AS id, so.slug, so.title_ar, so.subtitle_ar, so.badge_text_ar,
                    so.selection_mode::text AS selection_mode, ' . $pricingCols . '
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
                FROM special_offer_filters
                WHERE filter_type NOT IN (\'option_show_images\', \'option_price_mode\')
                GROUP BY offer_id
             ) fp ON fp.offer_id = so.id
             LEFT JOIN (
                SELECT offer_id, COUNT(*)::int AS products_count
                FROM special_offer_products GROUP BY offer_id
             ) mp ON mp.offer_id = so.id
             ORDER BY so.home_sort_order ASC, so.created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{total: int, active: int, manual: int, filter: int, on_home: int} */
    public static function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active,
                COUNT(*) FILTER (WHERE selection_mode = \'manual\')::int AS manual,
                COUNT(*) FILTER (WHERE selection_mode = \'filter\')::int AS filter,
                COUNT(*) FILTER (WHERE show_on_home = TRUE)::int AS on_home
             FROM special_offers'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'manual' => (int) ($row['manual'] ?? 0),
            'filter' => (int) ($row['filter'] ?? 0),
            'on_home' => (int) ($row['on_home'] ?? 0),
        ];
    }

    /**
     * @param array{include_preview?: bool} $options
     * @return array<string, mixed>|null
     */
    public static function getById(string $id, array $options = []): ?array
    {
        $pricingCols = self::hasExtendedOfferPricingColumns()
            ? 'selection_mode::text AS selection_mode, pricing_scope,
                    discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,
                    fixed_amount_syp, fixed_amount_usd,'
            : 'selection_mode::text AS selection_mode,
                    discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,';

        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    ' . $pricingCols . '
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
        $row['display_options'] = $parsed['display_options'];
        $manual = self::batchManualProductRowsForOffers([$id])[$id]
            ?? ['guids' => [], 'overrides' => []];
        $row['material_guids'] = $manual['guids'];
        $row['product_overrides'] = $manual['overrides'];
        $row['pricing_scope'] = (string) ($row['pricing_scope'] ?? 'offer');
        $row['manual_products'] = self::loadManualProductDetails($row['material_guids']);
        // Skip API-heavy storefront preview unless explicitly requested (edit form invoice already has labels).
        if (!empty($options['include_preview'])) {
            $row['preview_products'] = self::attachOfferPricing(self::loadOfferProducts($row), $row);
        } else {
            $row['preview_products'] = [];
        }

        return $row;
    }

    /**
     * Membership of materials in existing special offers (manual products).
     *
     * @param list<string> $guids
     * @return array<string, list<array{id: string, title_ar: string, status: string, discount_label: string, is_active: int}>>
     */
    public static function membershipForMaterials(array $guids): array
    {
        $normalized = [];
        foreach ($guids as $guid) {
            $guid = strtolower(trim((string) $guid));
            if ($guid !== '') {
                $normalized[$guid] = true;
            }
        }
        $guids = array_keys($normalized);
        $grouped = [];
        foreach ($guids as $guid) {
            $grouped[$guid] = [];
        }
        if ($guids === []) {
            return $grouped;
        }

        $placeholders = [];
        $params = [];
        foreach ($guids as $index => $guid) {
            $key = 'g' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $guid;
        }

        $hasExtended = self::hasExtendedOfferPricingColumns();
        $productDiscountCols = $hasExtended
            ? 'p.discount_type::text AS product_discount_type, p.discount_percent AS product_discount_percent,
               p.fixed_price_syp AS product_fixed_price_syp, p.fixed_price_usd AS product_fixed_price_usd,
               p.fixed_amount_syp AS product_fixed_amount_syp, p.fixed_amount_usd AS product_fixed_amount_usd,'
            : 'p.discount_type::text AS product_discount_type, p.discount_percent AS product_discount_percent,
               p.fixed_price_syp AS product_fixed_price_syp, p.fixed_price_usd AS product_fixed_price_usd,';
        $offerDiscountCols = $hasExtended
            ? 'o.discount_type::text AS offer_discount_type, o.discount_percent AS offer_discount_percent,
               o.fixed_price_syp AS offer_fixed_price_syp, o.fixed_price_usd AS offer_fixed_price_usd,
               o.fixed_amount_syp AS offer_fixed_amount_syp, o.fixed_amount_usd AS offer_fixed_amount_usd,'
            : 'o.discount_type::text AS offer_discount_type, o.discount_percent AS offer_discount_percent,
               o.fixed_price_syp AS offer_fixed_price_syp, o.fixed_price_usd AS offer_fixed_price_usd,';

        $selectSql = 'SELECT o.id::text AS id, o.title_ar,
                    CASE WHEN o.is_active THEN 1 ELSE 0 END AS is_active,
                    o.ends_at::text AS ends_at,
                    lower(p.material_guid::text) AS material_guid,
                    ' . $productDiscountCols . '
                    ' . $offerDiscountCols . '
                    1 AS _pad
             FROM special_offer_products p
             INNER JOIN special_offers o ON o.id = p.offer_id
             WHERE lower(p.material_guid::text) IN (' . implode(', ', $placeholders) . ')';
        try {
            $stmt = Database::pdo()->prepare(
                $selectSql . ' ORDER BY o.is_active DESC, o.updated_at DESC NULLS LAST, o.created_at DESC'
            );
            $stmt->execute($params);
        } catch (\Throwable) {
            // Older schemas without updated_at — retry without that sort key.
            $stmt = Database::pdo()->prepare(
                $selectSql . ' ORDER BY o.is_active DESC, o.created_at DESC'
            );
            $stmt->execute($params);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $now = time();

        foreach ($rows as $row) {
            $guid = strtolower(trim((string) ($row['material_guid'] ?? '')));
            if ($guid === '' || !isset($grouped[$guid])) {
                continue;
            }
            $ends = trim((string) ($row['ends_at'] ?? ''));
            $endsTs = $ends !== '' ? strtotime($ends) : false;
            $isActive = !empty($row['is_active']);
            if ($endsTs !== false && $endsTs <= $now) {
                $status = 'ended';
            } elseif ($isActive) {
                $status = 'active';
            } else {
                $status = 'paused';
            }

            $productType = trim((string) ($row['product_discount_type'] ?? ''));
            if ($productType !== '') {
                $discountLabel = self::formatMembershipDiscountLabel(
                    $productType,
                    $row['product_discount_percent'] ?? null,
                    $row['product_fixed_price_usd'] ?? null,
                    $row['product_fixed_price_syp'] ?? null,
                    $row['product_fixed_amount_usd'] ?? null,
                    $row['product_fixed_amount_syp'] ?? null
                );
            } else {
                $discountLabel = self::formatMembershipDiscountLabel(
                    (string) ($row['offer_discount_type'] ?? 'percent'),
                    $row['offer_discount_percent'] ?? null,
                    $row['offer_fixed_price_usd'] ?? null,
                    $row['offer_fixed_price_syp'] ?? null,
                    $row['offer_fixed_amount_usd'] ?? null,
                    $row['offer_fixed_amount_syp'] ?? null
                );
            }

            $grouped[$guid][] = [
                'id' => (string) ($row['id'] ?? ''),
                'title_ar' => (string) ($row['title_ar'] ?? ''),
                'status' => $status,
                'discount_label' => $discountLabel,
                'is_active' => $isActive ? 1 : 0,
            ];
        }

        return $grouped;
    }

    private static function formatMembershipDiscountLabel(
        string $type,
        mixed $percent,
        mixed $fixedUsd,
        mixed $fixedSyp,
        mixed $amountUsd = null,
        mixed $amountSyp = null
    ): string {
        $fmtNum = static function (mixed $value, int $decimals = 2): string {
            if ($value === null || $value === '' || !is_numeric((string) $value)) {
                return '';
            }
            $text = rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');

            return $text !== '' ? $text : '0';
        };

        return match ($type) {
            'fixed_price' => ($usd = $fmtNum($fixedUsd)) !== ''
                ? ('$' . $usd)
                : (($syp = $fmtNum($fixedSyp, 0)) !== '' ? ($syp . ' ل.س') : 'سعر جديد'),
            'fixed_amount' => ($usd = $fmtNum($amountUsd)) !== ''
                ? ('−$' . $usd)
                : (($syp = $fmtNum($amountSyp, 0)) !== '' ? ('−' . $syp . ' ل.س') : 'مبلغ مقطوع'),
            default => ($pct = $fmtNum($percent)) !== '' ? ($pct . '%') : '',
        };
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
        if (!in_array($discountType, ['percent', 'fixed_price', 'fixed_amount'], true)) {
            $discountType = 'percent';
        }

        $selectionMode = (string) ($payload['selection_mode'] ?? 'filter');
        if (!in_array($selectionMode, ['manual', 'filter'], true)) {
            $selectionMode = 'filter';
        }

        $pricingScope = (string) ($payload['pricing_scope'] ?? 'offer');
        if (!in_array($pricingScope, ['offer', 'per_material'], true)) {
            $pricingScope = 'offer';
        }
        if ($selectionMode !== 'manual') {
            $pricingScope = 'offer';
        }

        $startsAt = self::normalizeDateTime((string) ($payload['starts_at'] ?? '')) ?? date('Y-m-d H:i:s');
        $endsAt = self::normalizeDateTime((string) ($payload['ends_at'] ?? ''));

        try {
            $pdo = Database::pdo();
            $slug = self::ensureUniqueSlug($slug, $id !== '' ? $id : null);
            $hasExtended = self::hasExtendedOfferPricingColumns();
            if (!$hasExtended && $discountType === 'fixed_amount') {
                $discountType = 'percent';
            }
            if (!$hasExtended) {
                $pricingScope = 'offer';
            }

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
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => !empty($payload['is_active']) ? 'true' : 'false',
                'priority' => (int) ($payload['priority'] ?? 0),
                'min_packages' => self::toNullableFloat((string) ($payload['min_packages'] ?? '')),
                'max_packages' => self::toNullableFloat((string) ($payload['max_packages'] ?? '')),
                'max_products' => max(1, min(48, (int) ($payload['max_products'] ?? 12))),
                'show_on_home' => !empty($payload['show_on_home']) ? 'true' : 'false',
                'home_sort_order' => (int) ($payload['home_sort_order'] ?? 0),
                'updated_by_web_user_id' => $userId ?: null,
            ];
            if ($hasExtended) {
                $params['pricing_scope'] = $pricingScope;
                $params['fixed_amount_syp'] = $discountType === 'fixed_amount'
                    ? self::toNullableFloat((string) ($payload['fixed_amount_syp'] ?? ''))
                    : null;
                $params['fixed_amount_usd'] = $discountType === 'fixed_amount'
                    ? self::toNullableFloat((string) ($payload['fixed_amount_usd'] ?? ''))
                    : null;
            }

            if ($id === '') {
                if ($hasExtended) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO special_offers (
                            slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                            selection_mode, pricing_scope, discount_type, discount_percent,
                            fixed_price_syp, fixed_price_usd, fixed_amount_syp, fixed_amount_usd,
                            starts_at, ends_at, is_active, priority, min_packages, max_packages,
                            max_products, show_on_home, home_sort_order, updated_by_web_user_id
                         ) VALUES (
                            :slug, :title_ar, :subtitle_ar, :badge_text_ar, :banner_image_url,
                            :selection_mode, :pricing_scope, :discount_type, :discount_percent,
                            :fixed_price_syp, :fixed_price_usd, :fixed_amount_syp, :fixed_amount_usd,
                            :starts_at, :ends_at, :is_active, :priority, :min_packages, :max_packages,
                            :max_products, :show_on_home, :home_sort_order, :updated_by_web_user_id
                         ) RETURNING id::text'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO special_offers (
                            slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                            selection_mode, discount_type, discount_percent,
                            fixed_price_syp, fixed_price_usd,
                            starts_at, ends_at, is_active, priority, min_packages, max_packages,
                            max_products, show_on_home, home_sort_order, updated_by_web_user_id
                         ) VALUES (
                            :slug, :title_ar, :subtitle_ar, :badge_text_ar, :banner_image_url,
                            :selection_mode, :discount_type, :discount_percent,
                            :fixed_price_syp, :fixed_price_usd,
                            :starts_at, :ends_at, :is_active, :priority, :min_packages, :max_packages,
                            :max_products, :show_on_home, :home_sort_order, :updated_by_web_user_id
                         ) RETURNING id::text'
                    );
                }
                $stmt->execute($params);
                $id = (string) $stmt->fetchColumn();
            } else {
                $params['id'] = $id;
                if ($hasExtended) {
                    $stmt = $pdo->prepare(
                        'UPDATE special_offers SET
                            slug = :slug, title_ar = :title_ar, subtitle_ar = :subtitle_ar,
                            badge_text_ar = :badge_text_ar, banner_image_url = :banner_image_url,
                            selection_mode = :selection_mode, pricing_scope = :pricing_scope,
                            discount_type = :discount_type, discount_percent = :discount_percent,
                            fixed_price_syp = :fixed_price_syp, fixed_price_usd = :fixed_price_usd,
                            fixed_amount_syp = :fixed_amount_syp, fixed_amount_usd = :fixed_amount_usd,
                            starts_at = :starts_at, ends_at = :ends_at, is_active = :is_active,
                            priority = :priority, min_packages = :min_packages, max_packages = :max_packages,
                            max_products = :max_products, show_on_home = :show_on_home,
                            home_sort_order = :home_sort_order, updated_by_web_user_id = :updated_by_web_user_id
                         WHERE id = :id'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE special_offers SET
                            slug = :slug, title_ar = :title_ar, subtitle_ar = :subtitle_ar,
                            badge_text_ar = :badge_text_ar, banner_image_url = :banner_image_url,
                            selection_mode = :selection_mode,
                            discount_type = :discount_type, discount_percent = :discount_percent,
                            fixed_price_syp = :fixed_price_syp, fixed_price_usd = :fixed_price_usd,
                            starts_at = :starts_at, ends_at = :ends_at, is_active = :is_active,
                            priority = :priority, min_packages = :min_packages, max_packages = :max_packages,
                            max_products = :max_products, show_on_home = :show_on_home,
                            home_sort_order = :home_sort_order, updated_by_web_user_id = :updated_by_web_user_id
                         WHERE id = :id'
                    );
                }
                $stmt->execute($params);
            }

            $displayOptions = is_array($payload['display_options'] ?? null)
                ? $payload['display_options']
                : self::defaultDisplayOptions();
            self::syncFilters(
                $id,
                is_array($payload['filter_rules'] ?? null) ? $payload['filter_rules'] : [],
                $displayOptions
            );
            $materialGuids = is_array($payload['material_guids'] ?? null) ? $payload['material_guids'] : [];
            $productOverrides = is_array($payload['product_overrides'] ?? null) ? $payload['product_overrides'] : [];
            // Always sync manual product rows with overrides (discount + qty caps).
            // Previously overrides were dropped when pricing_scope was forced to "offer"
            // (missing extended columns), which left qty_mode at DB default "unlimited".
            self::syncManualProducts(
                $id,
                $materialGuids,
                $selectionMode === 'manual' ? $productOverrides : []
            );
            self::syncOfferNotification($id);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'تعذر حفظ العرض: ' . $e->getMessage(),
                'id' => null,
            ];
        }

        return ['ok' => true, 'message' => 'تم حفظ العرض.', 'id' => $id];
    }

    /** Full active offer row (pricing + rules) for storefront deep-links. */
    public static function activeOfferBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $pricingCols = self::hasExtendedOfferPricingColumns()
            ? 'selection_mode::text AS selection_mode, pricing_scope,
                    discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,
                    fixed_amount_syp, fixed_amount_usd,'
            : 'selection_mode::text AS selection_mode,
                    discount_type::text AS discount_type,
                    discount_percent, fixed_price_syp, fixed_price_usd,';

        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, badge_text_ar, banner_image_url,
                    ' . $pricingCols . '
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
        $manual = self::batchManualProductRowsForOffers([$id])[$id]
            ?? ['guids' => [], 'overrides' => []];
        $row['material_guids'] = $manual['guids'];
        $row['product_overrides'] = $manual['overrides'];
        $row['pricing_scope'] = (string) ($row['pricing_scope'] ?? 'offer');

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
            'display_options' => $parsed['display_options'],
            'material_guids' => self::manualProducts($id),
            'is_offer_section' => true,
        ];
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM special_offers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            NotificationService::syncSpecialOfferNotification([
                'id' => $id,
                'is_active' => false,
            ]);
        }

        return $deleted;
    }

    public static function toggleActive(string $id, bool $active): bool
    {
        $stmt = Database::pdo()->prepare('UPDATE special_offers SET is_active = :active WHERE id = :id');
        $stmt->execute(['id' => $id, 'active' => $active ? 'true' : 'false']);
        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            self::syncOfferNotification($id);
        }

        return $updated;
    }

    private static function syncOfferNotification(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT id::text AS id, slug, title_ar, subtitle_ar, ends_at::text AS ends_at, is_active
                 FROM special_offers
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                NotificationService::syncSpecialOfferNotification([
                    'id' => $id,
                    'is_active' => false,
                ]);

                return;
            }
            NotificationService::syncSpecialOfferNotification($row);
        } catch (\Throwable) {
        }
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
        if (!empty($material['has_offer'])
            && isset($material['effective_unit_sale_price_sp'], $material['original_unit_sale_price_sp'])) {
            return array_merge([
                'has_offer' => true,
                'offer' => is_array($material['offer'] ?? null) ? $material['offer'] : null,
                'offer_badge' => trim((string) ($material['offer_badge'] ?? '')),
                'offer_slug' => trim((string) ($material['offer_slug'] ?? '')),
            ], array_intersect_key($material, array_flip([
                'original_unit_sale_price_sp',
                'original_unit_sale_price_usd',
                'original_package_sale_price_sp',
                'original_package_sale_price_usd',
                'effective_unit_sale_price_sp',
                'effective_unit_sale_price_usd',
                'effective_package_sale_price_sp',
                'effective_package_sale_price_usd',
                'unitSalePriceSyp',
                'unitSalePriceUsd',
            ])));
        }

        $guid = trim((string) (
            $material['materialGuid'] ?? $material['MaterialGuid'] ?? $material['guid'] ?? $material['Guid'] ?? ''
        ));
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
            'offer_badge' => trim((string) ($offer['badge_text_ar'] ?? '')) ?: self::defaultBadge($offer, $guid),
            'offer_slug' => trim((string) ($offer['slug'] ?? '')),
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
            $guid = trim((string) ($product['materialGuid'] ?? $product['MaterialGuid'] ?? ''));
            $result[] = array_merge($product, [
                'offer' => $offer,
                'has_offer' => true,
                'offer_badge' => trim((string) ($offer['badge_text_ar'] ?? '')) ?: self::defaultBadge($offer, $guid),
                'offer_slug' => trim((string) ($offer['slug'] ?? '')),
            ], $overlay);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed>|null $contextOffer
     * @return list<array<string, mixed>>
     */
    public static function applyPricingOverlays(array $products, ?array $contextOffer = null): array
    {
        if ($products === []) {
            return [];
        }

        if ($contextOffer !== null) {
            return self::attachOfferPricing($products, $contextOffer);
        }

        $offers = self::activeOffers();
        if ($offers === []) {
            return $products;
        }

        $result = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $guid = trim((string) ($product['materialGuid'] ?? $product['MaterialGuid'] ?? ''));
            $best = null;
            $bestScore = null;
            foreach ($offers as $offer) {
                if (!self::offerIncludesMaterial($offer, $guid, $product)) {
                    continue;
                }
                $pricing = self::computePricing($product, $offer);
                $score = self::offerSortScore($pricing, $offer);
                if ($best === null || $score < $bestScore) {
                    $best = $offer;
                    $bestScore = $score;
                }
            }
            if ($best === null) {
                $result[] = $product;
                continue;
            }
            $result[] = array_merge($product, [
                'has_offer' => true,
                'offer' => $best,
                'offer_badge' => trim((string) ($best['badge_text_ar'] ?? '')) ?: self::defaultBadge($best, $guid),
                'offer_slug' => trim((string) ($best['slug'] ?? '')),
            ], self::computePricing($product, $best));
        }

        return $result;
    }

    /** @return array{ok: bool, message: string, quantity: float} */
    public static function validatePackageQuantity(string $materialGuid, float $quantity, ?array $offer = null): array
    {
        $quantity = max(0.0, round($quantity, 4));
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

        if ($offer === null) {
            $offer = self::resolveForMaterial($materialGuid);
        }

        if ($offer !== null) {
            $min = isset($offer['min_packages']) && is_numeric((string) $offer['min_packages'])
                ? (float) $offer['min_packages'] : null;
            $max = isset($offer['max_packages']) && is_numeric((string) $offer['max_packages'])
                ? (float) $offer['max_packages'] : null;
            $materialForQty = null;
            $guidLower = strtolower(trim($materialGuid));
            $qtyOverride = is_array($offer['product_overrides'][$guidLower] ?? null)
                ? $offer['product_overrides'][$guidLower]
                : [];
            if (trim((string) ($qtyOverride['qty_mode'] ?? '')) === 'stock') {
                try {
                    $fetched = MaterialBatchService::fetchByGuids([$guidLower], 12);
                    foreach ($fetched as $payload) {
                        if (is_array($payload)) {
                            $materialForQty = $payload;
                            break;
                        }
                    }
                } catch (\Throwable) {
                    $materialForQty = null;
                }
            }
            $remaining = self::remainingOfferPackages($offer, $materialGuid, $materialForQty);
            if ($remaining !== null) {
                $max = $max === null ? $remaining : min($max, $remaining);
            }
            if ($min !== null && $quantity < $min) {
                return [
                    'ok' => false,
                    'message' => 'الحد الأدنى لهذا العرض هو ' . self::formatQty($min) . ' طرد.',
                    'quantity' => $min,
                ];
            }
            if ($max !== null && $quantity > $max) {
                $msg = $remaining !== null && $max === $remaining
                    ? 'لم يتبقَّ من كمية هذا العرض سوى ' . self::formatQty($max) . ' طرد.'
                    : 'الحد الأقصى لهذا العرض هو ' . self::formatQty($max) . ' طرد.';

                return [
                    'ok' => false,
                    'message' => $msg,
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
        $baseUnitSp = isset($material['original_unit_sale_price_sp']) && is_numeric((string) $material['original_unit_sale_price_sp'])
            ? (float) $material['original_unit_sale_price_sp']
            : ShareCartService::unitSalePriceSp($material);
        $baseUnitUsd = isset($material['original_unit_sale_price_usd']) && is_numeric((string) $material['original_unit_sale_price_usd'])
            ? (float) $material['original_unit_sale_price_usd']
            : ShareCartService::unitSalePriceUsd($material);
        $basePackSp = $baseUnitSp * $packaging;
        $basePackUsd = $baseUnitUsd * $packaging;

        $materialGuid = trim((string) (
            $material['materialGuid']
            ?? $material['MaterialGuid']
            ?? $material['material_guid']
            ?? ''
        ));
        $spec = self::resolveDiscountSpec($offer, $materialGuid);
        $discountType = (string) ($spec['discount_type'] ?? 'percent');

        // USD is the fixed sale price. SYP after discount is always derived from
        // discounted USD × exchange rate, rounded UP to the nearest 5 (Jawish rule).
        $rate = PriceFx::inferRate(
            $basePackSp,
            $basePackUsd,
            isset($material['currencyVal']) && is_numeric((string) $material['currencyVal'])
                ? (float) $material['currencyVal']
                : (isset($material['CurrencyVal']) && is_numeric((string) $material['CurrencyVal'])
                    ? (float) $material['CurrencyVal']
                    : null)
        );

        if ($discountType === 'fixed_price') {
            $hasUsd = $spec['fixed_price_usd'] !== null && $spec['fixed_price_usd'] !== ''
                && is_numeric((string) $spec['fixed_price_usd']);
            $hasSyp = $spec['fixed_price_syp'] !== null && $spec['fixed_price_syp'] !== ''
                && is_numeric((string) $spec['fixed_price_syp']);
            $effPackUsd = $hasUsd ? max(0.0, (float) $spec['fixed_price_usd']) : $basePackUsd;
            if ($hasSyp) {
                $effPackSp = max(0.0, (float) $spec['fixed_price_syp']);
            } elseif ($hasUsd && $rate > 0.0) {
                // Fixed offer prices are package-level; still round the implied unit SYP to 5.
                $unitUsd = $packaging > 0 ? $effPackUsd / $packaging : $effPackUsd;
                $effUnitSp = PriceFx::sypFromUsd($unitUsd, $rate);
                $effPackSp = $effUnitSp * ($packaging > 0 ? $packaging : 1.0);
            } else {
                $effPackSp = $basePackSp;
            }
        } elseif ($discountType === 'fixed_amount') {
            $amountUsd = is_numeric((string) ($spec['fixed_amount_usd'] ?? ''))
                ? max(0.0, (float) $spec['fixed_amount_usd']) : 0.0;
            $hasUsdAmount = $spec['fixed_amount_usd'] !== null && $spec['fixed_amount_usd'] !== ''
                && $amountUsd > 0;
            if ($hasUsdAmount) {
                $effPackUsd = max(0.0, $basePackUsd - $amountUsd);
                if ($rate > 0.0) {
                    $unitUsd = $packaging > 0 ? $effPackUsd / $packaging : $effPackUsd;
                    $effUnitSp = PriceFx::sypFromUsd($unitUsd, $rate);
                    $effPackSp = $effUnitSp * ($packaging > 0 ? $packaging : 1.0);
                } else {
                    $effPackSp = max(0.0, $basePackSp - (is_numeric((string) ($spec['fixed_amount_syp'] ?? ''))
                        ? max(0.0, (float) $spec['fixed_amount_syp']) : 0.0));
                }
            } else {
                $amountSp = is_numeric((string) ($spec['fixed_amount_syp'] ?? ''))
                    ? max(0.0, (float) $spec['fixed_amount_syp']) : 0.0;
                $effPackSp = max(0.0, $basePackSp - $amountSp);
                $effPackUsd = $basePackUsd;
            }
        } else {
            $pct = min(100.0, max(0.0, (float) ($spec['discount_percent'] ?? 0)));
            $factor = 1.0 - ($pct / 100.0);
            $effPackUsd = max(0.0, $basePackUsd * $factor);
            if ($rate > 0.0) {
                $unitUsd = $packaging > 0 ? $effPackUsd / $packaging : $effPackUsd;
                $effUnitSp = PriceFx::sypFromUsd($unitUsd, $rate);
                $effPackSp = $effUnitSp * ($packaging > 0 ? $packaging : 1.0);
            } else {
                $effPackSp = max(0.0, $basePackSp * $factor);
            }
        }

        $effUnitSp = isset($effUnitSp)
            ? $effUnitSp
            : ($packaging > 0 ? $effPackSp / $packaging : $effPackSp);
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

    /**
     * Resolve effective discount fields for a material (offer-level or per-material override).
     *
     * @param array<string, mixed> $offer
     * @return array{
     *   discount_type: string,
     *   discount_percent: mixed,
     *   fixed_price_syp: mixed,
     *   fixed_price_usd: mixed,
     *   fixed_amount_syp: mixed,
     *   fixed_amount_usd: mixed
     * }
     */
    public static function resolveDiscountSpec(array $offer, string $materialGuid = ''): array
    {
        $header = [
            'discount_type' => (string) ($offer['discount_type'] ?? 'percent'),
            'discount_percent' => $offer['discount_percent'] ?? null,
            'fixed_price_syp' => $offer['fixed_price_syp'] ?? null,
            'fixed_price_usd' => $offer['fixed_price_usd'] ?? null,
            'fixed_amount_syp' => $offer['fixed_amount_syp'] ?? null,
            'fixed_amount_usd' => $offer['fixed_amount_usd'] ?? null,
        ];

        $scope = (string) ($offer['pricing_scope'] ?? 'offer');
        if ($scope !== 'per_material' || $materialGuid === '') {
            return $header;
        }

        $overrides = is_array($offer['product_overrides'] ?? null) ? $offer['product_overrides'] : [];
        $row = $overrides[$materialGuid] ?? $overrides[strtolower($materialGuid)] ?? null;
        if (!is_array($row)) {
            return $header;
        }

        $type = trim((string) ($row['discount_type'] ?? ''));
        if (!in_array($type, ['percent', 'fixed_price', 'fixed_amount'], true)) {
            return $header;
        }

        return [
            'discount_type' => $type,
            'discount_percent' => $row['discount_percent'] ?? null,
            'fixed_price_syp' => $row['fixed_price_syp'] ?? null,
            'fixed_price_usd' => $row['fixed_price_usd'] ?? null,
            'fixed_amount_syp' => $row['fixed_amount_syp'] ?? null,
            'fixed_amount_usd' => $row['fixed_amount_usd'] ?? null,
        ];
    }

    /** @param array<string, mixed> $offer */
    public static function applyToCartLine(array $line, array $offer): array
    {
        $material = [
            'materialGuid' => trim((string) ($line['material_guid'] ?? '')),
            'unitSalePriceSyp' => (float) ($line['unit_sale_price_sp'] ?? 0),
            'unitSalePriceUsd' => (float) ($line['unit_sale_price_usd'] ?? 0),
            'packageConversionFactor' => (float) ($line['packaging'] ?? $line['package_factor'] ?? 1),
        ];
        if (isset($line['original_unit_sale_price_sp']) && is_numeric((string) $line['original_unit_sale_price_sp'])) {
            $material['original_unit_sale_price_sp'] = (float) $line['original_unit_sale_price_sp'];
        }
        if (isset($line['original_unit_sale_price_usd']) && is_numeric((string) $line['original_unit_sale_price_usd'])) {
            $material['original_unit_sale_price_usd'] = (float) $line['original_unit_sale_price_usd'];
        }

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
        $line['offer_badge'] = trim((string) ($offer['badge_text_ar'] ?? '')) ?: self::defaultBadge($offer, (string) ($line['material_guid'] ?? ''));
        $line['has_offer'] = true;
        $line['offer_title_ar'] = trim((string) ($offer['title_ar'] ?? ''));
        $line['offer_slug'] = trim((string) ($offer['slug'] ?? ''));

        return ShareCartService::normalizeLine($line);
    }

    // --- internals ---

    /** Whether migration 014 columns exist (pricing_scope / fixed_amount_*). */
    private static function hasExtendedOfferPricingColumns(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            Database::pdo()->query('SELECT pricing_scope, fixed_amount_syp FROM special_offers LIMIT 0');
            Database::pdo()->query('SELECT fixed_amount_syp FROM special_offer_products LIMIT 0');
            $cached = true;
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    /** @return list<array<string, mixed>> */
    private static function activeOffers(): array
    {
        if (self::hasExtendedOfferPricingColumns()) {
            $rows = Database::pdo()->query(
                'SELECT id::text AS id, slug, title_ar, badge_text_ar, selection_mode::text AS selection_mode,
                        pricing_scope, discount_type::text AS discount_type, discount_percent,
                        fixed_price_syp, fixed_price_usd, fixed_amount_syp, fixed_amount_usd,
                        priority, min_packages, max_packages, starts_at
                 FROM special_offers
                 WHERE is_active = TRUE AND starts_at <= NOW() AND (ends_at IS NULL OR ends_at > NOW())
                 ORDER BY priority DESC, starts_at DESC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $rows = Database::pdo()->query(
                'SELECT id::text AS id, slug, title_ar, badge_text_ar, selection_mode::text AS selection_mode,
                        discount_type::text AS discount_type, discount_percent,
                        fixed_price_syp, fixed_price_usd,
                        priority, min_packages, max_packages, starts_at
                 FROM special_offers
                 WHERE is_active = TRUE AND starts_at <= NOW() AND (ends_at IS NULL OR ends_at > NOW())
                 ORDER BY priority DESC, starts_at DESC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $offerIds = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        $filtersByOffer = self::batchFiltersForOffers($offerIds);
        $manualByOffer = self::batchManualProductRowsForOffers($offerIds);

        foreach ($rows as &$row) {
            $id = (string) $row['id'];
            $parsed = self::parseFilterRows($filtersByOffer[$id] ?? []);
            $row['filter_rules'] = $parsed['rules'];
            $manual = $manualByOffer[$id] ?? ['guids' => [], 'overrides' => []];
            $row['material_guids'] = $manual['guids'];
            $row['product_overrides'] = $manual['overrides'];
            $row['pricing_scope'] = (string) ($row['pricing_scope'] ?? 'offer');
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $offer */
    public static function offerIncludesMaterial(array $offer, string $guid, array $material): bool
    {
        $guidLower = strtolower(trim($guid));
        if ((string) ($offer['selection_mode'] ?? '') === 'manual') {
            $listed = false;
            foreach ($offer['material_guids'] ?? [] as $offerGuid) {
                if (strtolower(trim((string) $offerGuid)) === $guidLower) {
                    $listed = true;
                    break;
                }
            }
            if (!$listed) {
                return false;
            }
        } elseif (!self::materialMatchesRules($material, is_array($offer['filter_rules'] ?? null) ? $offer['filter_rules'] : [])) {
            return false;
        }

        $override = is_array($offer['product_overrides'][$guidLower] ?? null)
            ? $offer['product_overrides'][$guidLower]
            : [];
        if (!empty($override['is_exhausted'])) {
            return false;
        }
        $remaining = self::remainingOfferPackages($offer, $guidLower, $material);
        if ($remaining !== null && $remaining <= 0.0) {
            return false;
        }

        return true;
    }

    /**
     * Remaining packages that can still be sold under this offer for a material.
     * null = unlimited.
     *
     * @param array<string, mixed> $offer
     * @param array<string, mixed>|null $material
     */
    public static function remainingOfferPackages(array $offer, string $materialGuid, ?array $material = null): ?float
    {
        $materialGuid = strtolower(trim($materialGuid));
        if ($materialGuid === '') {
            return null;
        }
        $override = is_array($offer['product_overrides'][$materialGuid] ?? null)
            ? $offer['product_overrides'][$materialGuid]
            : (is_array($offer['product_overrides'][strtolower($materialGuid)] ?? null)
                ? $offer['product_overrides'][strtolower($materialGuid)]
                : []);
        $mode = trim((string) ($override['qty_mode'] ?? 'unlimited'));
        if (!in_array($mode, ['stock', 'fixed'], true)) {
            return null;
        }

        if ($mode === 'fixed') {
            $cap = isset($override['qty_cap_packages']) && is_numeric((string) $override['qty_cap_packages'])
                ? max(0.0, (float) $override['qty_cap_packages'])
                : 0.0;
            $offerId = trim((string) ($offer['id'] ?? ''));
            $sold = $offerId !== '' ? SpecialOfferAnalyticsService::soldPackages($offerId, $materialGuid) : 0.0;

            return max(0.0, round($cap - $sold, 4));
        }

        // stock: current warehouse packages.
        if ($material === null) {
            return null;
        }
        $packaging = max(0.0001, ShareCartService::packaging($material));
        $warehouse = 0.0;
        foreach (['warehouseQuantity', 'WarehouseQuantity', 'warehouse_quantity', 'stagnantRemainingQty'] as $key) {
            if (isset($material[$key]) && is_numeric((string) $material[$key])) {
                $warehouse = max(0.0, (float) $material[$key]);
                break;
            }
        }

        return max(0.0, round($warehouse / $packaging, 4));
    }

    /** Mark products exhausted when remaining qty hits zero (fixed/stock modes). */
    public static function refreshExhaustedFlags(string $offerId): void
    {
        $offerId = trim($offerId);
        if ($offerId === '' || !self::hasProductQtyColumns()) {
            return;
        }
        $offer = self::getById($offerId);
        if ($offer === null) {
            return;
        }
        $pdo = Database::pdo();
        $mark = $pdo->prepare(
            'UPDATE special_offer_products
             SET is_exhausted = :ex, exhausted_at = CASE WHEN :ex2 THEN COALESCE(exhausted_at, NOW()) ELSE NULL END
             WHERE offer_id = :offer_id AND lower(material_guid::text) = :guid'
        );
        foreach ($offer['material_guids'] ?? [] as $guid) {
            $guid = strtolower(trim((string) $guid));
            if ($guid === '') {
                continue;
            }
            $override = is_array($offer['product_overrides'][$guid] ?? null) ? $offer['product_overrides'][$guid] : [];
            $mode = trim((string) ($override['qty_mode'] ?? 'unlimited'));
            if (!in_array($mode, ['stock', 'fixed'], true)) {
                $mark->execute(['ex' => 'false', 'ex2' => 'false', 'offer_id' => $offerId, 'guid' => $guid]);
                continue;
            }
            $material = [];
            if ($mode === 'stock') {
                try {
                    $fetched = MaterialBatchService::fetchByGuids([$guid], 12);
                    foreach ($fetched as $payload) {
                        if (is_array($payload)) {
                            $material = $payload;
                            break;
                        }
                    }
                } catch (\Throwable) {
                    $material = [];
                }
            }
            $remaining = self::remainingOfferPackages($offer, $guid, $material);
            $exhausted = $remaining !== null && $remaining <= 0.0;
            $mark->execute([
                'ex' => $exhausted ? 'true' : 'false',
                'ex2' => $exhausted ? 'true' : 'false',
                'offer_id' => $offerId,
                'guid' => $guid,
            ]);
        }
    }

    /** Whether migration 031 qty columns exist. */
    private static function hasProductQtyColumns(bool $refresh = false): bool
    {
        static $cached = null;
        if ($refresh) {
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }
        try {
            Database::pdo()->query('SELECT qty_mode, qty_cap_packages, is_exhausted FROM special_offer_products LIMIT 0');
            $cached = true;
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * Best-effort apply of migration 031 column adds (IF NOT EXISTS) when the
     * runner never registered 031. Safe to call repeatedly.
     */
    private static function ensureProductQtyColumns(): bool
    {
        if (self::hasProductQtyColumns()) {
            return true;
        }
        try {
            $pdo = Database::pdo();
            $pdo->exec(
                'ALTER TABLE special_offer_products
                    ADD COLUMN IF NOT EXISTS qty_mode VARCHAR(20) NOT NULL DEFAULT \'unlimited\',
                    ADD COLUMN IF NOT EXISTS qty_cap_packages NUMERIC(18, 4),
                    ADD COLUMN IF NOT EXISTS is_exhausted BOOLEAN NOT NULL DEFAULT FALSE,
                    ADD COLUMN IF NOT EXISTS exhausted_at TIMESTAMPTZ'
            );
            $pdo->exec(
                'DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint WHERE conname = \'chk_special_offer_products_qty_mode\'
                    ) THEN
                        ALTER TABLE special_offer_products
                            ADD CONSTRAINT chk_special_offer_products_qty_mode
                            CHECK (qty_mode IN (\'unlimited\', \'stock\', \'fixed\'));
                    END IF;
                END $$'
            );
        } catch (\Throwable) {
            return self::hasProductQtyColumns(true);
        }

        return self::hasProductQtyColumns(true);
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

        if (!self::fieldMatchesAnyContains($field('materialType', 'MaterialType', 'color', 'Color'), $rules['material_types'] ?? [])) {
            return false;
        }
        if (!self::fieldMatchesAnyContains($field('ageCategory', 'AgeCategory', 'provenance', 'Provenance'), $rules['age_categories'] ?? [])) {
            return false;
        }
        if (!self::fieldMatchesAnyContains($field('manufacturer', 'Manufacturer', 'company', 'Company'), $rules['manufacturers'] ?? [])) {
            return false;
        }
        if (!self::fieldMatchesAnyContains($field('sizeRange', 'SizeRange', 'dim', 'Dim'), $rules['size_ranges'] ?? [])) {
            return false;
        }
        if (!self::fieldMatchesAnyContains($field('countryOfOrigin', 'CountryOfOrigin', 'origin', 'Origin'), $rules['country_origins'] ?? [])) {
            return false;
        }

        $groupGuid = strtolower($field('groupGuid', 'GroupGuid'));
        $groupRules = is_array($rules['group_guids'] ?? null) ? $rules['group_guids'] : [];
        if ($groupRules !== []) {
            $groupMatched = false;
            foreach ($groupRules as $value) {
                if ($groupGuid !== '' && $groupGuid === strtolower(trim((string) $value))) {
                    $groupMatched = true;
                    break;
                }
            }
            if (!$groupMatched) {
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

    /** @param list<mixed> $ruleValues */
    private static function fieldMatchesAnyContains(string $fieldValue, array $ruleValues): bool
    {
        if ($ruleValues === []) {
            return true;
        }

        $fieldValue = trim($fieldValue);
        if ($fieldValue === '') {
            return false;
        }

        foreach ($ruleValues as $ruleValue) {
            $needle = trim((string) $ruleValue);
            if ($needle === '') {
                continue;
            }
            if (function_exists('mb_stripos')) {
                if (mb_stripos($fieldValue, $needle) !== false) {
                    return true;
                }
            } elseif (stripos($fieldValue, $needle) !== false) {
                return true;
            }
        }

        return false;
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
    private static function defaultBadge(array $offer, string $materialGuid = ''): string
    {
        $spec = self::resolveDiscountSpec($offer, $materialGuid);
        $type = (string) ($spec['discount_type'] ?? '');
        if ($type === 'percent') {
            $pct = (float) ($spec['discount_percent'] ?? 0);
            if ($pct > 0) {
                return '-' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
            }
        }
        if ($type === 'fixed_amount') {
            $amountSp = (float) ($spec['fixed_amount_syp'] ?? 0);
            if ($amountSp > 0) {
                return '-' . rtrim(rtrim(number_format($amountSp, 0, '.', ''), '0'), '.') . ' ل.س';
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
        $tryGuids = array_slice($guids, 0, min(count($guids), max($max * 3, $max)));
        $materialsByGuid = MaterialBatchService::fetchByGuids($tryGuids, 20);
        $items = [];
        foreach ($tryGuids as $guid) {
            if (count($items) >= $max) {
                break;
            }
            $item = $materialsByGuid[$guid] ?? null;
            if (is_array($item)) {
                $items[] = $item;
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

    /**
     * @param list<string> $guids
     * @return list<array{guid: string, name: string, code: string, image: string, unitSalePriceUsd: ?float, unitSalePriceSyp: ?float, unitPurchasePriceUsd: ?float, currencyVal: ?float}>
     */
    private static function loadManualProductDetails(array $guids): array
    {
        $ordered = [];
        foreach ($guids as $guid) {
            $guid = strtolower(trim((string) $guid));
            if ($guid !== '' && !isset($ordered[$guid])) {
                $ordered[$guid] = true;
            }
        }
        $guidList = array_keys($ordered);
        if ($guidList === []) {
            return [];
        }

        $materialsByGuid = [];
        try {
            $fetched = MaterialBatchService::fetchByGuids($guidList, 25);
            foreach ($fetched as $key => $payload) {
                if (!is_array($payload)) {
                    continue;
                }
                $materialsByGuid[strtolower(trim((string) $key))] = $payload;
            }
        } catch (\Throwable) {
            $materialsByGuid = [];
        }

        $items = [];
        foreach ($guidList as $guid) {
            $d = $materialsByGuid[$guid] ?? null;
            if (!is_array($d)) {
                $items[] = [
                    'guid' => $guid,
                    'name' => $guid,
                    'code' => '',
                    'image' => '',
                    'unitSalePriceUsd' => null,
                    'unitSalePriceSyp' => null,
                    'unitPurchasePriceUsd' => null,
                    'currencyVal' => null,
                ];
                continue;
            }

            $saleUsd = self::toNullableFloat((string) ($d['unitSalePriceUsd'] ?? $d['UnitSalePriceUsd'] ?? $d['half'] ?? $d['Half'] ?? ''));
            $saleSyp = self::toNullableFloat((string) ($d['unitSalePriceSyp'] ?? $d['UnitSalePriceSyp'] ?? $d['whole'] ?? $d['Whole'] ?? ''));
            $purchaseUsd = self::toNullableFloat((string) ($d['unitPurchasePriceUsd'] ?? $d['UnitPurchasePriceUsd'] ?? $d['endUser'] ?? $d['EndUser'] ?? ''));
            $currencyVal = self::toNullableFloat((string) ($d['currencyVal'] ?? $d['CurrencyVal'] ?? ''));
            $imageGuid = trim((string) ($d['productImageGuid'] ?? $d['ProductImageGuid'] ?? $d['pictureGuid'] ?? $d['PictureGuid'] ?? ''));
            $warehouseQty = self::toNullableFloat((string) ($d['warehouseQuantity'] ?? $d['WarehouseQuantity'] ?? ''));
            $packaging = ShareCartService::packaging($d);
            $availablePackages = $warehouseQty !== null
                ? max(0.0, round($warehouseQty / max(0.0001, $packaging), 4))
                : null;
            $items[] = [
                'guid' => $guid,
                'name' => trim((string) ($d['name'] ?? $d['Name'] ?? '')),
                'code' => trim((string) ($d['materialCode'] ?? $d['MaterialCode'] ?? $d['code'] ?? $d['Code'] ?? '')),
                'image' => $imageGuid !== '' && $imageGuid !== '00000000-0000-0000-0000-000000000000'
                    ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1'
                    : '',
                'unitSalePriceUsd' => $saleUsd,
                'unitSalePriceSyp' => $saleSyp,
                'unitPurchasePriceUsd' => $purchaseUsd,
                'currencyVal' => $currencyVal,
                'warehouseQuantity' => $warehouseQty,
                'packageConversionFactor' => $packaging,
                'availablePackages' => $availablePackages,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $rules @param array{show_images?: bool, price_mode?: string} $displayOptions */
    private static function syncFilters(string $offerId, array $rules, array $displayOptions = []): void
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
        $insertNumber = static function (string $type, mixed $value) use ($insert): void {
            if ($value === null || $value === '') {
                return;
            }
            $insert($type, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        };

        $insert(self::FILTER_KEYWORD, trim((string) ($rules['keyword'] ?? '')));
        $insertList(self::FILTER_MATERIAL_TYPE, $rules['material_types'] ?? []);
        $insertList(self::FILTER_AGE_CATEGORY, $rules['age_categories'] ?? []);
        $insertList(self::FILTER_MANUFACTURER, $rules['manufacturers'] ?? []);
        $insertList(self::FILTER_SIZE_RANGE, $rules['size_ranges'] ?? []);
        $insertList(self::FILTER_COUNTRY_ORIGIN, $rules['country_origins'] ?? []);
        $insertList(self::FILTER_STORE_GUID, $rules['store_guids'] ?? []);
        $insertList(self::FILTER_GROUP_GUID, $rules['group_guids'] ?? []);
        $insertNumber(self::FILTER_IS_AVAILABLE, $rules['is_available'] ?? null);
        $insertNumber(self::FILTER_HAS_IMAGE, $rules['has_image'] ?? null);
        $insertNumber(self::FILTER_MIN_WAREHOUSE_QUANTITY, $rules['min_warehouse_quantity'] ?? null);
        $insertNumber(self::FILTER_MAX_WAREHOUSE_QUANTITY, $rules['max_warehouse_quantity'] ?? null);
        $insertNumber(self::FILTER_MIN_UNIT_SALE_PRICE_SYP, $rules['min_unit_sale_price_syp'] ?? null);
        $insertNumber(self::FILTER_MAX_UNIT_SALE_PRICE_SYP, $rules['max_unit_sale_price_syp'] ?? null);
        $insertNumber(self::FILTER_MIN_UNIT_SALE_PRICE_USD, $rules['min_unit_sale_price_usd'] ?? null);
        $insertNumber(self::FILTER_MAX_UNIT_SALE_PRICE_USD, $rules['max_unit_sale_price_usd'] ?? null);
        $insertNumber(self::FILTER_MIN_UNIT_PURCHASE_PRICE_USD, $rules['min_unit_purchase_price_usd'] ?? null);
        $insertNumber(self::FILTER_MAX_UNIT_PURCHASE_PRICE_USD, $rules['max_unit_purchase_price_usd'] ?? null);

        $defaults = self::defaultDisplayOptions();
        $showImages = array_key_exists('show_images', $displayOptions)
            ? (bool) $displayOptions['show_images']
            : $defaults['show_images'];
        $priceMode = trim((string) ($displayOptions['price_mode'] ?? $defaults['price_mode']));
        if (!in_array($priceMode, ['both', 'syp', 'usd', 'none'], true)) {
            $priceMode = $defaults['price_mode'];
        }
        $insert(self::OPTION_SHOW_IMAGES, $showImages ? '1' : '0');
        $insert(self::OPTION_PRICE_MODE, $priceMode);
    }

    /**
     * @param array<string, mixed> $override
     * @return array{0: string, 1: float|null}
     */
    private static function normalizeQtyOverride(array $override): array
    {
        $mode = trim((string) ($override['qty_mode'] ?? ''));
        if (!in_array($mode, ['unlimited', 'stock', 'fixed'], true)) {
            // Prefer fixed when a package cap is present (stagnant tray / invoice defaults).
            $mode = isset($override['qty_cap_packages']) && is_numeric((string) $override['qty_cap_packages'])
                ? 'fixed'
                : 'unlimited';
        }
        $cap = $mode === 'fixed'
            ? self::toNullableFloat((string) ($override['qty_cap_packages'] ?? ''))
            : null;

        return [$mode, $cap];
    }

    /** @param list<string> $guids @param array<string, array<string, mixed>> $overrides */
    private static function syncManualProducts(string $offerId, array $guids, array $overrides = []): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM special_offer_products WHERE offer_id = :id')->execute(['id' => $offerId]);
        $hasExtended = self::hasExtendedOfferPricingColumns();
        $hasQty = self::ensureProductQtyColumns();
        $normalizedOverrides = [];
        foreach ($overrides as $key => $value) {
            $normalizedOverrides[strtolower(trim((string) $key))] = is_array($value) ? $value : [];
        }

        if (!$hasQty) {
            foreach ($normalizedOverrides as $override) {
                $mode = trim((string) ($override['qty_mode'] ?? ''));
                $hasCap = isset($override['qty_cap_packages'])
                    && is_numeric((string) $override['qty_cap_packages']);
                if ($mode !== '' || $hasCap) {
                    throw new \RuntimeException(
                        'أعمدة كمية العرض غير موجودة. من مجلد المستودع شغّل: PORTAL_DB_SETUP=migrate bash deploy/portal/publish.sh'
                    );
                }
            }
        }

        $rows = [];
        $sort = 0;
        $allowedTypes = $hasExtended
            ? ['percent', 'fixed_price', 'fixed_amount']
            : ['percent', 'fixed_price'];
        foreach (self::stringList($guids) as $guid) {
            $guid = strtolower(trim($guid));
            if ($guid === '') {
                continue;
            }
            $override = $normalizedOverrides[$guid] ?? [];
            $type = trim((string) ($override['discount_type'] ?? ''));
            if (!in_array($type, $allowedTypes, true)) {
                $type = '';
            }

            $row = [
                $offerId,
                $guid,
                $sort++,
                $type !== '' ? $type : null,
                $type === 'percent'
                    ? self::toNullableFloat((string) ($override['discount_percent'] ?? ''))
                    : null,
                $type === 'fixed_price'
                    ? self::toNullableFloat((string) ($override['fixed_price_syp'] ?? ''))
                    : null,
                $type === 'fixed_price'
                    ? self::toNullableFloat((string) ($override['fixed_price_usd'] ?? ''))
                    : null,
            ];
            if ($hasExtended) {
                $row[] = $type === 'fixed_amount'
                    ? self::toNullableFloat((string) ($override['fixed_amount_syp'] ?? ''))
                    : null;
                $row[] = $type === 'fixed_amount'
                    ? self::toNullableFloat((string) ($override['fixed_amount_usd'] ?? ''))
                    : null;
            }
            if ($hasQty) {
                [$qtyMode, $qtyCap] = self::normalizeQtyOverride($override);
                $row[] = $qtyMode;
                $row[] = $qtyCap;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            return;
        }

        // Multi-row inserts cut round-trips when saving large stagnant-offer drafts.
        // Qty columns are written in the same INSERT so caps cannot be left at DB default.
        $chunkSize = 80;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $row) {
                if ($hasExtended && $hasQty) {
                    $placeholders[] = '(:o' . $index . ', :g' . $index . ', :s' . $index
                        . ', :dt' . $index . ', :dp' . $index . ', :fps' . $index . ', :fpu' . $index
                        . ', :fas' . $index . ', :fau' . $index
                        . ', :qm' . $index . ', :qc' . $index . ')';
                    $params['o' . $index] = $row[0];
                    $params['g' . $index] = $row[1];
                    $params['s' . $index] = $row[2];
                    $params['dt' . $index] = $row[3];
                    $params['dp' . $index] = $row[4];
                    $params['fps' . $index] = $row[5];
                    $params['fpu' . $index] = $row[6];
                    $params['fas' . $index] = $row[7];
                    $params['fau' . $index] = $row[8];
                    $params['qm' . $index] = $row[9];
                    $params['qc' . $index] = $row[10];
                } elseif ($hasExtended) {
                    $placeholders[] = '(:o' . $index . ', :g' . $index . ', :s' . $index
                        . ', :dt' . $index . ', :dp' . $index . ', :fps' . $index . ', :fpu' . $index
                        . ', :fas' . $index . ', :fau' . $index . ')';
                    $params['o' . $index] = $row[0];
                    $params['g' . $index] = $row[1];
                    $params['s' . $index] = $row[2];
                    $params['dt' . $index] = $row[3];
                    $params['dp' . $index] = $row[4];
                    $params['fps' . $index] = $row[5];
                    $params['fpu' . $index] = $row[6];
                    $params['fas' . $index] = $row[7];
                    $params['fau' . $index] = $row[8];
                } elseif ($hasQty) {
                    $placeholders[] = '(:o' . $index . ', :g' . $index . ', :s' . $index
                        . ', :dt' . $index . ', :dp' . $index . ', :fps' . $index . ', :fpu' . $index
                        . ', :qm' . $index . ', :qc' . $index . ')';
                    $params['o' . $index] = $row[0];
                    $params['g' . $index] = $row[1];
                    $params['s' . $index] = $row[2];
                    $params['dt' . $index] = $row[3];
                    $params['dp' . $index] = $row[4];
                    $params['fps' . $index] = $row[5];
                    $params['fpu' . $index] = $row[6];
                    $params['qm' . $index] = $row[7];
                    $params['qc' . $index] = $row[8];
                } else {
                    $placeholders[] = '(:o' . $index . ', :g' . $index . ', :s' . $index
                        . ', :dt' . $index . ', :dp' . $index . ', :fps' . $index . ', :fpu' . $index . ')';
                    $params['o' . $index] = $row[0];
                    $params['g' . $index] = $row[1];
                    $params['s' . $index] = $row[2];
                    $params['dt' . $index] = $row[3];
                    $params['dp' . $index] = $row[4];
                    $params['fps' . $index] = $row[5];
                    $params['fpu' . $index] = $row[6];
                }
            }

            if ($hasExtended && $hasQty) {
                $sql = 'INSERT INTO special_offer_products (
                    offer_id, material_guid, sort_order,
                    discount_type, discount_percent, fixed_price_syp, fixed_price_usd,
                    fixed_amount_syp, fixed_amount_usd,
                    qty_mode, qty_cap_packages
                 ) VALUES ' . implode(', ', $placeholders);
            } elseif ($hasExtended) {
                $sql = 'INSERT INTO special_offer_products (
                    offer_id, material_guid, sort_order,
                    discount_type, discount_percent, fixed_price_syp, fixed_price_usd,
                    fixed_amount_syp, fixed_amount_usd
                 ) VALUES ' . implode(', ', $placeholders);
            } elseif ($hasQty) {
                $sql = 'INSERT INTO special_offer_products (
                    offer_id, material_guid, sort_order,
                    discount_type, discount_percent, fixed_price_syp, fixed_price_usd,
                    qty_mode, qty_cap_packages
                 ) VALUES ' . implode(', ', $placeholders);
            } else {
                $sql = 'INSERT INTO special_offer_products (
                    offer_id, material_guid, sort_order,
                    discount_type, discount_percent, fixed_price_syp, fixed_price_usd
                 ) VALUES ' . implode(', ', $placeholders);
            }
            $pdo->prepare($sql)->execute($params);
        }

        // Belt-and-suspenders: re-assert qty columns after INSERT so a missed bind cannot
        // leave DB default "unlimited" for stagnant / invoice caps.
        if ($hasQty) {
            $qtyStmt = $pdo->prepare(
                'UPDATE special_offer_products
                 SET qty_mode = :qty_mode,
                     qty_cap_packages = :qty_cap,
                     is_exhausted = FALSE,
                     exhausted_at = NULL
                 WHERE offer_id = :offer_id AND lower(material_guid::text) = :guid'
            );
            foreach (self::stringList($guids) as $guid) {
                $guid = strtolower(trim($guid));
                if ($guid === '') {
                    continue;
                }
                $override = $normalizedOverrides[$guid] ?? [];
                [$qtyMode, $qtyCap] = self::normalizeQtyOverride($override);
                $qtyStmt->execute([
                    'qty_mode' => $qtyMode,
                    'qty_cap' => $qtyCap,
                    'offer_id' => $offerId,
                    'guid' => $guid,
                ]);
            }
            self::refreshExhaustedFlags($offerId);
        }
    }

    /** @return list<array{filter_type: string, value_ar: string}> */
    private static function filtersForOffer(string $offerId): array
    {
        return self::batchFiltersForOffers([$offerId])[$offerId] ?? [];
    }

    /**
     * @param list<string> $offerIds
     * @return array<string, list<array{filter_type: string, value_ar: string}>>
     */
    private static function batchFiltersForOffers(array $offerIds): array
    {
        $offerIds = array_values(array_unique(array_filter(array_map('strval', $offerIds), static fn (string $id): bool => trim($id) !== '')));
        $grouped = [];
        foreach ($offerIds as $offerId) {
            $grouped[$offerId] = [];
        }
        if ($offerIds === []) {
            return $grouped;
        }

        $placeholders = [];
        $params = [];
        foreach ($offerIds as $index => $offerId) {
            $key = 'o' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $offerId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT offer_id::text AS offer_id, filter_type, value_ar
             FROM special_offer_filters
             WHERE offer_id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $offerId = (string) ($row['offer_id'] ?? '');
            if ($offerId === '') {
                continue;
            }
            $grouped[$offerId][] = [
                'filter_type' => (string) ($row['filter_type'] ?? ''),
                'value_ar' => (string) ($row['value_ar'] ?? ''),
            ];
        }

        return $grouped;
    }

    /** @return list<string> */
    private static function manualProducts(string $offerId): array
    {
        return self::batchManualProductRowsForOffers([$offerId])[$offerId]['guids'] ?? [];
    }

    /**
     * @param list<string> $offerIds
     * @return array<string, list<string>>
     */
    private static function batchManualProductsForOffers(array $offerIds): array
    {
        $rows = self::batchManualProductRowsForOffers($offerIds);
        $grouped = [];
        foreach ($rows as $offerId => $payload) {
            $grouped[$offerId] = $payload['guids'];
        }

        return $grouped;
    }

    /**
     * @param list<string> $offerIds
     * @return array<string, array{guids: list<string>, overrides: array<string, array<string, mixed>>}>
     */
    private static function batchManualProductRowsForOffers(array $offerIds): array
    {
        $offerIds = array_values(array_unique(array_filter(array_map('strval', $offerIds), static fn (string $id): bool => trim($id) !== '')));
        $grouped = [];
        foreach ($offerIds as $offerId) {
            $grouped[$offerId] = ['guids' => [], 'overrides' => []];
        }
        if ($offerIds === []) {
            return $grouped;
        }

        $placeholders = [];
        $params = [];
        foreach ($offerIds as $index => $offerId) {
            $key = 'o' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $offerId;
        }

        $qtyCols = self::hasProductQtyColumns()
            ? ', qty_mode, qty_cap_packages, CASE WHEN is_exhausted THEN 1 ELSE 0 END AS is_exhausted'
            : '';

        if (self::hasExtendedOfferPricingColumns()) {
            $stmt = Database::pdo()->prepare(
                'SELECT offer_id::text AS offer_id, material_guid::text AS material_guid,
                        discount_type::text AS discount_type, discount_percent,
                        fixed_price_syp, fixed_price_usd, fixed_amount_syp, fixed_amount_usd
                        ' . $qtyCols . '
                 FROM special_offer_products
                 WHERE offer_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY offer_id, sort_order ASC'
            );
        } else {
            $stmt = Database::pdo()->prepare(
                'SELECT offer_id::text AS offer_id, material_guid::text AS material_guid,
                        discount_type::text AS discount_type, discount_percent,
                        fixed_price_syp, fixed_price_usd
                        ' . $qtyCols . '
                 FROM special_offer_products
                 WHERE offer_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY offer_id, sort_order ASC'
            );
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $offerId = (string) ($row['offer_id'] ?? '');
            $guid = strtolower(trim((string) ($row['material_guid'] ?? '')));
            if ($offerId === '' || $guid === '' || !isset($grouped[$offerId])) {
                continue;
            }
            $grouped[$offerId]['guids'][] = $guid;
            $type = trim((string) ($row['discount_type'] ?? ''));
            // Only expose qty fields when migration 031 columns exist. Inventing
            // qty_mode=unlimited here forced the edit UI to show «بلا حد» even when
            // the client/JS would otherwise default stagnant rows to fixed+cap.
            $override = [];
            if ($qtyCols !== '') {
                $override['qty_mode'] = (string) ($row['qty_mode'] ?? 'unlimited');
                $override['qty_cap_packages'] = $row['qty_cap_packages'] ?? null;
                $override['is_exhausted'] = !empty($row['is_exhausted']);
            }
            if ($type !== '') {
                $override['discount_type'] = $type;
                $override['discount_percent'] = $row['discount_percent'] ?? null;
                $override['fixed_price_syp'] = $row['fixed_price_syp'] ?? null;
                $override['fixed_price_usd'] = $row['fixed_price_usd'] ?? null;
                $override['fixed_amount_syp'] = $row['fixed_amount_syp'] ?? null;
                $override['fixed_amount_usd'] = $row['fixed_amount_usd'] ?? null;
            }
            $grouped[$offerId]['overrides'][$guid] = $override;
        }

        return $grouped;
    }

    /** @param list<array{filter_type: string, value_ar: string}> $rows @return array{rules: array<string, mixed>, display_options: array{show_images: bool, price_mode: string}} */
    private static function parseFilterRows(array $rows): array
    {
        $displayOptions = self::defaultDisplayOptions();
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
            'min_warehouse_quantity' => null,
            'max_warehouse_quantity' => null,
            'min_unit_sale_price_syp' => null,
            'max_unit_sale_price_syp' => null,
            'min_unit_sale_price_usd' => null,
            'max_unit_sale_price_usd' => null,
            'min_unit_purchase_price_usd' => null,
            'max_unit_purchase_price_usd' => null,
        ];
        foreach ($rows as $row) {
            $type = (string) ($row['filter_type'] ?? '');
            $value = (string) ($row['value_ar'] ?? '');
            match ($type) {
                self::OPTION_SHOW_IMAGES => $displayOptions['show_images'] = self::toNullableBool($value) ?? true,
                self::OPTION_PRICE_MODE => $displayOptions['price_mode'] = in_array($value, ['both', 'syp', 'usd', 'none'], true)
                    ? $value
                    : 'both',
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
                self::FILTER_MIN_WAREHOUSE_QUANTITY => $rules['min_warehouse_quantity'] = self::toNullableFloat($value),
                self::FILTER_MAX_WAREHOUSE_QUANTITY => $rules['max_warehouse_quantity'] = self::toNullableFloat($value),
                self::FILTER_MIN_UNIT_SALE_PRICE_SYP => $rules['min_unit_sale_price_syp'] = self::toNullableFloat($value),
                self::FILTER_MAX_UNIT_SALE_PRICE_SYP => $rules['max_unit_sale_price_syp'] = self::toNullableFloat($value),
                self::FILTER_MIN_UNIT_SALE_PRICE_USD => $rules['min_unit_sale_price_usd'] = self::toNullableFloat($value),
                self::FILTER_MAX_UNIT_SALE_PRICE_USD => $rules['max_unit_sale_price_usd'] = self::toNullableFloat($value),
                self::FILTER_MIN_UNIT_PURCHASE_PRICE_USD => $rules['min_unit_purchase_price_usd'] = self::toNullableFloat($value),
                self::FILTER_MAX_UNIT_PURCHASE_PRICE_USD => $rules['max_unit_purchase_price_usd'] = self::toNullableFloat($value),
                default => null,
            };
        }

        return ['rules' => $rules, 'display_options' => $displayOptions];
    }

    /** @param array<string, mixed> $rules */
    public static function materialsListQuery(array $rules, int $pageSize): array
    {
        return self::buildApiQuery($rules, $pageSize);
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

        foreach ([
            'min_warehouse_quantity' => 'minWarehouseQuantity',
            'max_warehouse_quantity' => 'maxWarehouseQuantity',
            'min_unit_sale_price_syp' => 'minUnitSalePriceSyp',
            'max_unit_sale_price_syp' => 'maxUnitSalePriceSyp',
            'min_unit_sale_price_usd' => 'minUnitSalePriceUsd',
            'max_unit_sale_price_usd' => 'maxUnitSalePriceUsd',
            'min_unit_purchase_price_usd' => 'minUnitPurchasePriceUsd',
            'max_unit_purchase_price_usd' => 'maxUnitPurchasePriceUsd',
        ] as $ruleKey => $apiKey) {
            $value = $rules[$ruleKey] ?? null;
            if ($value !== null && $value !== '') {
                $query[$apiKey] = $value;
            }
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
        $value = preg_replace('/[^a-z0-9\-]+/u', '', $value) ?? '';

        return trim($value, '-');
    }

    private static function ensureUniqueSlug(string $slug, ?string $exceptId = null): string
    {
        $base = substr($slug !== '' ? $slug : 'offer', 0, 64);
        $candidate = $base;
        $attempt = 0;
        while ($attempt < 12) {
            $sql = 'SELECT 1 FROM special_offers WHERE slug = :slug';
            $params = ['slug' => $candidate];
            if ($exceptId !== null && $exceptId !== '') {
                $sql .= ' AND id::text <> :id';
                $params['id'] = $exceptId;
            }
            $sql .= ' LIMIT 1';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
            $attempt++;
            $candidate = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private static function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        $ts = strtotime($value);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
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
