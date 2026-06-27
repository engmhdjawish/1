<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class HomeSectionService
{
    private const DISPLAY_MODES = ['manual', 'filter'];

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
    public static function activeSections(): array
    {
        $pdo = Database::pdo();
        $sections = $pdo->query(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, banner_image_url, display_mode::text AS display_mode, max_products
             FROM home_sections WHERE is_active = TRUE ORDER BY sort_order ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sections as &$section) {
            $sectionId = (string) $section['id'];
            $parsed = self::parseFilterRows(self::filtersForSection($sectionId));
            $section['filters'] = $parsed['rows'];
            $section['filter_rules'] = $parsed['rules'];
            $section['display_options'] = $parsed['display_options'];
            $section['material_guids'] = self::manualProducts($sectionId);
            $section['products'] = self::loadProducts($section);
        }

        return $sections;
    }

    /** @return array<string, mixed>|null */
    public static function storeContextBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, slug, title_ar, subtitle_ar, display_mode::text AS display_mode
             FROM home_sections WHERE slug = :slug AND is_active = TRUE LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $id = (string) $row['id'];
        $parsed = self::parseFilterRows(self::filtersForSection($id));

        return [
            'id' => $id,
            'slug' => (string) $row['slug'],
            'title_ar' => (string) $row['title_ar'],
            'subtitle_ar' => (string) ($row['subtitle_ar'] ?? ''),
            'selection_mode' => (string) ($row['display_mode'] ?? 'filter'),
            'filter_rules' => $parsed['rules'],
            'display_options' => $parsed['display_options'],
            'material_guids' => self::manualProducts($id),
            'is_offer_section' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function adminSections(): array
    {
        return Database::pdo()->query(
            'SELECT
                hs.id::text AS id,
                hs.slug,
                hs.title_ar,
                hs.subtitle_ar,
                hs.banner_image_url,
                hs.display_mode::text AS display_mode,
                hs.max_products,
                hs.sort_order,
                CASE WHEN hs.is_active THEN 1 ELSE 0 END AS is_active,
                hs.updated_at,
                COALESCE(filters.filters_count, 0)::int AS filters_count,
                COALESCE(products.products_count, 0)::int AS products_count
             FROM home_sections hs
             LEFT JOIN (
                SELECT section_id, COUNT(*)::int AS filters_count
                FROM home_section_filters
                WHERE filter_type NOT IN (\'option_show_images\', \'option_price_mode\')
                GROUP BY section_id
             ) filters ON filters.section_id = hs.id
             LEFT JOIN (
                SELECT section_id, COUNT(*)::int AS products_count
                FROM home_section_products
                GROUP BY section_id
             ) products ON products.section_id = hs.id
             ORDER BY hs.sort_order ASC, hs.created_at ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{total: int, active: int, manual: int, filter: int} */
    public static function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active,
                COUNT(*) FILTER (WHERE display_mode = \'manual\')::int AS manual,
                COUNT(*) FILTER (WHERE display_mode = \'filter\')::int AS filter
             FROM home_sections'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'manual' => (int) ($row['manual'] ?? 0),
            'filter' => (int) ($row['filter'] ?? 0),
        ];
    }

    public static function getSectionById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                slug,
                title_ar,
                subtitle_ar,
                banner_image_url,
                display_mode::text AS display_mode,
                max_products,
                sort_order,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
             FROM home_sections
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $parsed = self::parseFilterRows(self::sectionFilters($id));
        $row['filters'] = $parsed['rows'];
        $row['filter_rules'] = $parsed['rules'];
        $row['display_options'] = $parsed['display_options'];
        $row['material_guids'] = self::manualProducts($id);
        $row['manual_products'] = self::loadManualProductDetails($row['material_guids']);
        $row['preview_products'] = self::loadProducts($row);

        return $row;
    }

    /** @return array{ok: bool, message: string, id?: string} */
    public static function saveSection(
        ?string $id,
        string $slug,
        string $titleAr,
        ?string $subtitleAr,
        ?string $bannerImageUrl,
        string $displayMode,
        int $maxProducts,
        int $sortOrder,
        bool $isActive,
        ?string $updatedByUserId
    ): array {
        $titleAr = trim($titleAr);
        if ($titleAr === '') {
            return ['ok' => false, 'message' => 'عنوان القسم مطلوب.'];
        }

        $slug = self::normalizeSlug($slug !== '' ? $slug : $titleAr);
        if ($slug === '') {
            $slug = 'section-' . date('His');
        }

        $displayMode = in_array($displayMode, self::DISPLAY_MODES, true) ? $displayMode : 'filter';
        $maxProducts = max(1, min(48, $maxProducts));
        $sortOrder = max(0, $sortOrder);
        $subtitleAr = trim((string) $subtitleAr);
        $bannerImageUrl = trim((string) $bannerImageUrl);
        $updatedByUserId = $updatedByUserId !== null ? trim($updatedByUserId) : null;
        $updatedByUserId = $updatedByUserId !== '' ? $updatedByUserId : null;

        $pdo = Database::pdo();
        $duplicate = $pdo->prepare(
            'SELECT 1
             FROM home_sections
             WHERE slug = :slug
               AND (:exclude_id_is_empty = \'\' OR id::text <> :exclude_id_value)
             LIMIT 1'
        );
        $duplicate->execute([
            'slug' => $slug,
            'exclude_id_is_empty' => $id !== null ? trim($id) : '',
            'exclude_id_value' => $id !== null ? trim($id) : '',
        ]);
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'Slug مستخدم مسبقًا.'];
        }

        if ($id === null || trim($id) === '') {
            $insert = $pdo->prepare(
                'INSERT INTO home_sections (
                    slug, title_ar, subtitle_ar, banner_image_url, display_mode,
                    max_products, sort_order, is_active, updated_by_user_id
                 ) VALUES (
                    :slug, :title_ar, :subtitle_ar, :banner_image_url, :display_mode,
                    :max_products, :sort_order, CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END, :updated_by_user_id
                 )
                 RETURNING id::text'
            );
            $insert->execute([
                'slug' => $slug,
                'title_ar' => $titleAr,
                'subtitle_ar' => $subtitleAr !== '' ? $subtitleAr : null,
                'banner_image_url' => $bannerImageUrl !== '' ? $bannerImageUrl : null,
                'display_mode' => $displayMode,
                'max_products' => $maxProducts,
                'sort_order' => $sortOrder,
                'is_active' => $isActive ? 1 : 0,
                'updated_by_user_id' => $updatedByUserId,
            ]);

            return [
                'ok' => true,
                'message' => 'تم إنشاء القسم.',
                'id' => (string) $insert->fetchColumn(),
            ];
        }

        $update = $pdo->prepare(
            'UPDATE home_sections SET
                slug = :slug, title_ar = :title_ar, subtitle_ar = :subtitle_ar,
                banner_image_url = :banner_image_url, display_mode = :display_mode,
                max_products = :max_products, sort_order = :sort_order,
                is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                updated_by_user_id = :updated_by_user_id, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'id' => $id,
            'slug' => $slug,
            'title_ar' => $titleAr,
            'subtitle_ar' => $subtitleAr !== '' ? $subtitleAr : null,
            'banner_image_url' => $bannerImageUrl !== '' ? $bannerImageUrl : null,
            'display_mode' => $displayMode,
            'max_products' => $maxProducts,
            'sort_order' => $sortOrder,
            'is_active' => $isActive ? 1 : 0,
            'updated_by_user_id' => $updatedByUserId,
        ]);

        return ['ok' => true, 'message' => 'تم تحديث القسم.', 'id' => $id];
    }

    public static function setActive(string $id, bool $isActive, ?string $updatedByUserId): bool
    {
        $updatedByUserId = $updatedByUserId !== null ? trim($updatedByUserId) : null;
        $updatedByUserId = $updatedByUserId !== '' ? $updatedByUserId : null;

        $stmt = Database::pdo()->prepare(
            'UPDATE home_sections
             SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                 updated_at = NOW(), updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
            'updated_by_user_id' => $updatedByUserId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteSection(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'message' => 'المعرّف غير صالح.'];
        }

        try {
            $stmt = Database::pdo()->prepare('DELETE FROM home_sections WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => 'القسم غير موجود أو تم حذفه مسبقًا.'];
            }

            return ['ok' => true, 'message' => 'تم حذف القسم.'];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'تعذر حذف القسم.'];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{show_images?: bool, price_mode?: string} $displayOptions
     */
    public static function syncFilters(string $sectionId, array $payload, array $displayOptions = []): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM home_section_filters WHERE section_id = :id')->execute(['id' => $sectionId]);

        $insert = $pdo->prepare(
            'INSERT INTO home_section_filters (section_id, filter_type, value_ar)
             VALUES (:section_id, :filter_type, :value_ar)'
        );

        $insertValues = static function (string $type, array $values) use ($insert, $sectionId): void {
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $insert->execute([
                    'section_id' => $sectionId,
                    'filter_type' => $type,
                    'value_ar' => $value,
                ]);
            }
        };

        $insertNumber = static function (string $type, mixed $value) use ($insert, $sectionId): void {
            if ($value === null || $value === '') {
                return;
            }
            $insert->execute([
                'section_id' => $sectionId,
                'filter_type' => $type,
                'value_ar' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ]);
        };

        $keyword = trim((string) ($payload['keyword'] ?? ''));
        if ($keyword !== '') {
            $insert->execute([
                'section_id' => $sectionId,
                'filter_type' => self::FILTER_KEYWORD,
                'value_ar' => $keyword,
            ]);
        }

        $insertValues(self::FILTER_MATERIAL_TYPE, self::stringList($payload['material_types'] ?? []));
        $insertValues(self::FILTER_AGE_CATEGORY, self::stringList($payload['age_categories'] ?? []));
        $insertValues(self::FILTER_MANUFACTURER, self::stringList($payload['manufacturers'] ?? []));
        $insertValues(self::FILTER_SIZE_RANGE, self::stringList($payload['size_ranges'] ?? []));
        $insertValues(self::FILTER_COUNTRY_ORIGIN, self::stringList($payload['country_origins'] ?? []));
        $insertValues(self::FILTER_STORE_GUID, self::stringList($payload['store_guids'] ?? []));
        $insertValues(self::FILTER_GROUP_GUID, self::stringList($payload['group_guids'] ?? []));

        $insertNumber(self::FILTER_IS_AVAILABLE, $payload['is_available'] ?? null);
        $insertNumber(self::FILTER_HAS_IMAGE, $payload['has_image'] ?? null);
        $insertNumber(self::FILTER_MIN_WAREHOUSE_QUANTITY, $payload['min_warehouse_quantity'] ?? null);
        $insertNumber(self::FILTER_MAX_WAREHOUSE_QUANTITY, $payload['max_warehouse_quantity'] ?? null);
        $insertNumber(self::FILTER_MIN_UNIT_SALE_PRICE_SYP, $payload['min_unit_sale_price_syp'] ?? null);
        $insertNumber(self::FILTER_MAX_UNIT_SALE_PRICE_SYP, $payload['max_unit_sale_price_syp'] ?? null);
        $insertNumber(self::FILTER_MIN_UNIT_SALE_PRICE_USD, $payload['min_unit_sale_price_usd'] ?? null);
        $insertNumber(self::FILTER_MAX_UNIT_SALE_PRICE_USD, $payload['max_unit_sale_price_usd'] ?? null);
        $insertNumber(self::FILTER_MIN_UNIT_PURCHASE_PRICE_USD, $payload['min_unit_purchase_price_usd'] ?? null);
        $insertNumber(self::FILTER_MAX_UNIT_PURCHASE_PRICE_USD, $payload['max_unit_purchase_price_usd'] ?? null);

        self::insertDisplayOptions($insert, $sectionId, $displayOptions);
    }

    /**
     * @param array{show_images?: bool, price_mode?: string} $displayOptions
     */
    private static function insertDisplayOptions(\PDOStatement $insert, string $sectionId, array $displayOptions): void
    {
        $defaults = self::defaultDisplayOptions();
        $showImages = array_key_exists('show_images', $displayOptions)
            ? (bool) $displayOptions['show_images']
            : $defaults['show_images'];
        $priceMode = trim((string) ($displayOptions['price_mode'] ?? $defaults['price_mode']));
        if (!in_array($priceMode, ['both', 'syp', 'usd', 'none'], true)) {
            $priceMode = $defaults['price_mode'];
        }

        $insert->execute([
            'section_id' => $sectionId,
            'filter_type' => self::OPTION_SHOW_IMAGES,
            'value_ar' => $showImages ? '1' : '0',
        ]);
        $insert->execute([
            'section_id' => $sectionId,
            'filter_type' => self::OPTION_PRICE_MODE,
            'value_ar' => $priceMode,
        ]);
    }

    /** @param list<string> $guids */
    public static function syncManualProducts(string $sectionId, array $guids): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM home_section_products WHERE section_id = :id')->execute(['id' => $sectionId]);

        $insert = $pdo->prepare(
            'INSERT INTO home_section_products (section_id, material_guid, sort_order)
             VALUES (:section_id, :material_guid, :sort_order)
             ON CONFLICT (section_id, material_guid) DO NOTHING'
        );

        $sortOrder = 0;
        foreach ($guids as $guid) {
            $guid = trim($guid);
            if ($guid === '') {
                continue;
            }
            $insert->execute([
                'section_id' => $sectionId,
                'material_guid' => $guid,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /** @return list<array{id: string, filter_type: string, value_ar: string}> */
    public static function sectionFilters(string $sectionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, filter_type, value_ar
             FROM home_section_filters
             WHERE section_id = :section_id
             ORDER BY filter_type, value_ar'
        );
        $stmt->execute(['section_id' => $sectionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{filter_type: string, value_ar: string}> */
    private static function filtersForSection(string $sectionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT filter_type, value_ar FROM home_section_filters WHERE section_id = :id'
        );
        $stmt->execute(['id' => $sectionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array{filter_type: string, value_ar: string}> $rows
     * @return array{
     *   rows: list<array{filter_type: string, value_ar: string}>,
     *   rules: array<string, mixed>,
     *   display_options: array{show_images: bool, price_mode: string}
     * }
     */
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
            $type = trim((string) ($row['filter_type'] ?? ''));
            $value = trim((string) ($row['value_ar'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            match ($type) {
                self::OPTION_SHOW_IMAGES => $displayOptions['show_images'] = self::toNullableBool($value) ?? true,
                self::OPTION_PRICE_MODE => $displayOptions['price_mode'] = in_array($value, ['both', 'syp', 'usd', 'none'], true)
                    ? $value
                    : 'both',
                self::FILTER_KEYWORD, 'keyword' => $rules['keyword'] = $value,
                self::FILTER_MATERIAL_TYPE, 'material_type' => $rules['material_types'][] = $value,
                self::FILTER_AGE_CATEGORY, 'age_category', 'target_category' => $rules['age_categories'][] = $value,
                self::FILTER_MANUFACTURER, 'manufacturer' => $rules['manufacturers'][] = $value,
                self::FILTER_SIZE_RANGE, 'size_range' => $rules['size_ranges'][] = $value,
                self::FILTER_COUNTRY_ORIGIN, 'country_origin' => $rules['country_origins'][] = $value,
                self::FILTER_STORE_GUID, 'store_guid' => $rules['store_guids'][] = $value,
                self::FILTER_GROUP_GUID, 'group_guid' => $rules['group_guids'][] = $value,
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

        $materialRows = [];
        foreach ($rows as $row) {
            $type = trim((string) ($row['filter_type'] ?? ''));
            if ($type === '' || str_starts_with($type, 'option_')) {
                continue;
            }
            $materialRows[] = $row;
        }

        return ['rows' => $materialRows, 'rules' => $rules, 'display_options' => $displayOptions];
    }

    /** @return list<string> */
    private static function manualProducts(string $sectionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT material_guid::text FROM home_section_products
             WHERE section_id = :id ORDER BY sort_order ASC'
        );
        $stmt->execute(['id' => $sectionId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $guids
     * @return list<array{guid: string, name: string, code: string}>
     */
    private static function loadManualProductDetails(array $guids): array
    {
        $items = [];
        foreach ($guids as $guid) {
            $guid = trim($guid);
            if ($guid === '') {
                continue;
            }
            try {
                $response = ApiClient::get('/api/materials/' . rawurlencode($guid));
                if (!$response['ok'] || !is_array($response['data'])) {
                    continue;
                }
                $data = $response['data'];
                $items[] = [
                    'guid' => $guid,
                    'name' => trim((string) ($data['name'] ?? $data['Name'] ?? '')),
                    'code' => trim((string) ($data['materialCode'] ?? $data['MaterialCode'] ?? '')),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $section */
    private static function loadProducts(array $section): array
    {
        $maxProducts = max(1, (int) ($section['max_products'] ?? 12));
        $displayMode = (string) ($section['display_mode'] ?? 'filter');

        if ($displayMode === 'manual') {
            $guids = is_array($section['material_guids'] ?? null) ? $section['material_guids'] : [];
            return self::pickRandomManualProducts($guids, $maxProducts);
        }

        $rules = is_array($section['filter_rules'] ?? null)
            ? $section['filter_rules']
            : self::parseFilterRows(is_array($section['filters'] ?? null) ? $section['filters'] : [])['rules'];

        return self::pickRandomFilteredProducts($rules, $maxProducts);
    }

    /** @param list<string> $guids */
    private static function pickRandomManualProducts(array $guids, int $maxProducts): array
    {
        if ($guids === []) {
            return [];
        }

        $guids = array_values(array_unique(array_filter(array_map('strval', $guids), static fn ($g) => trim($g) !== '')));
        shuffle($guids);

        $items = [];
        foreach ($guids as $guid) {
            if (count($items) >= $maxProducts) {
                break;
            }
            try {
                $response = ApiClient::get('/api/materials/' . rawurlencode($guid));
                if ($response['ok'] && is_array($response['data'])) {
                    $item = $response['data'];
                    if (StockReservationService::isSellable($item)) {
                        $items[] = $item;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        shuffle($items);

        return array_slice($items, 0, $maxProducts);
    }

    /** @param array<string, mixed> $rules */
    private static function pickRandomFilteredProducts(array $rules, int $maxProducts): array
    {
        $poolSize = min(200, max($maxProducts * 8, 48));
        $query = self::buildApiQuery($rules, $poolSize);

        try {
            $result = ApiClient::get('/api/materials', $query);
            if (!$result['ok']) {
                return [];
            }

            $items = $result['data']['items'] ?? [];
            if (!is_array($items) || $items === []) {
                return [];
            }

            $items = StockReservationService::filterSellableProducts($items);
            if ($items === []) {
                return [];
            }

            shuffle($items);

            return array_slice($items, 0, $maxProducts);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $rules */
    private static function buildApiQuery(array $rules, int $pageSize): array
    {
        $query = [
            'page' => 1,
            'pageSize' => $pageSize,
            'sort' => 'number:asc',
        ];

        $keyword = trim((string) ($rules['keyword'] ?? ''));
        if ($keyword !== '') {
            $query['keyword'] = $keyword;
        }

        $appendCsv = static function (?string $existing, string $value): string {
            return $existing === null || $existing === '' ? $value : $existing . ',' . $value;
        };

        foreach ($rules['material_types'] ?? [] as $value) {
            $query['materialTypes'] = $appendCsv($query['materialTypes'] ?? null, (string) $value);
        }
        foreach ($rules['age_categories'] ?? [] as $value) {
            $query['ageCategories'] = $appendCsv($query['ageCategories'] ?? null, (string) $value);
        }
        foreach ($rules['manufacturers'] ?? [] as $value) {
            $query['manufacturers'] = $appendCsv($query['manufacturers'] ?? null, (string) $value);
        }
        foreach ($rules['size_ranges'] ?? [] as $value) {
            $query['sizeRanges'] = $appendCsv($query['sizeRanges'] ?? null, (string) $value);
        }
        foreach ($rules['country_origins'] ?? [] as $value) {
            $query['countryOfOrigins'] = $appendCsv($query['countryOfOrigins'] ?? null, (string) $value);
        }
        foreach ($rules['store_guids'] ?? [] as $value) {
            $query['storeGuids'] = $appendCsv($query['storeGuids'] ?? null, (string) $value);
        }
        foreach ($rules['group_guids'] ?? [] as $value) {
            $query['groupGuids'] = $appendCsv($query['groupGuids'] ?? null, (string) $value);
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
        $value = preg_replace('/[^a-z0-9\-]/u', '', $value) ?? '';
        $value = trim($value, '-');

        return $value;
    }
}
