<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class AccessPolicyService
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

    private const OPTION_ALLOW_SORTING = 'option_allow_sorting';
    private const OPTION_DEFAULT_SORT = 'option_default_sort';
    private const OPTION_DEFAULT_GROUP_BY = 'option_default_group_by';
    private const OPTION_VISIBLE_CLIENT_FILTER = 'option_visible_client_filter';
    private const OPTION_CLIENT_SORT_FIELD = 'option_client_sort_field';
    private const VISIBLE_CLIENT_FILTERS_NONE = '__none__';

    /** @var list<string> */
    public const ALLOWED_VISIBLE_CLIENT_FILTERS = [
        'search',
        'availability',
        'warehouseRange',
        'priceSaleSyp',
        'priceSaleUsd',
        'pricePurchaseUsd',
        'materialTypes',
        'ageCategories',
        'manufacturers',
        'sizeRanges',
        'countryOfOrigins',
        'stores',
        'groups',
        'sort',
        'groupBy',
    ];

    /** @var list<string> */
    public const ALLOWED_CLIENT_SORT_FIELDS = [
        'number',
        'materialType',
        'ageCategory',
        'manufacturer',
        'sizeRange',
        'countryOfOrigin',
        'unitSalePriceSyp',
        'unitSalePriceUsd',
    ];

    public static function ensureTable(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS access_policy_filters (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                policy_id UUID NOT NULL REFERENCES access_policies (id) ON DELETE CASCADE,
                filter_type VARCHAR(50) NOT NULL,
                value_ar VARCHAR(500) NOT NULL,
                CONSTRAINT uq_access_policy_filter UNIQUE (policy_id, filter_type, value_ar)
            )'
        );
    }

    /** @return list<array<string, mixed>> */
    public static function list(bool $includeInactive = true): array
    {
        self::ensureTable();

        $sql = 'SELECT
                    id::text AS id,
                    code,
                    name_ar,
                    description_ar,
                    CASE WHEN show_price THEN 1 ELSE 0 END AS show_price,
                    CASE WHEN show_quantity THEN 1 ELSE 0 END AS show_quantity,
                    CASE WHEN allow_cart THEN 1 ELSE 0 END AS allow_cart,
                    CASE WHEN allow_order THEN 1 ELSE 0 END AS allow_order,
                    CASE WHEN is_active THEN 1 ELSE 0 END AS is_active,
                    created_at,
                    updated_at
                FROM access_policies';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = TRUE';
        }
        $sql .= ' ORDER BY name_ar';

        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $parsed = self::parseAllFilterRows(self::loadPolicyFilterRows((string) ($row['id'] ?? '')));
            $row['filter_rules'] = $parsed['rules'];
            $row['store_options'] = $parsed['store_options'];
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $id): ?array
    {
        self::ensureTable();

        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                code,
                name_ar,
                description_ar,
                CASE WHEN show_price THEN 1 ELSE 0 END AS show_price,
                CASE WHEN show_quantity THEN 1 ELSE 0 END AS show_quantity,
                CASE WHEN allow_cart THEN 1 ELSE 0 END AS allow_cart,
                CASE WHEN allow_order THEN 1 ELSE 0 END AS allow_order,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
             FROM access_policies
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $parsed = self::parseAllFilterRows(self::loadPolicyFilterRows($id));
        $row['filter_rules'] = $parsed['rules'];
        $row['store_options'] = $parsed['store_options'];

        return $row;
    }

    /** @return array<string, mixed> */
    public static function filterRulesForPolicyId(string $policyId): array
    {
        return self::parseAllFilterRows(self::loadPolicyFilterRows($policyId))['rules'];
    }

    /** @return array{
     *   visible_client_filters: list<string>,
     *   allow_sorting: bool,
     *   client_sort_fields: list<string>,
     *   default_sort: string,
     *   default_group_by: string
     * } */
    public static function storeOptionsForPolicyId(string $policyId): array
    {
        return self::parseAllFilterRows(self::loadPolicyFilterRows($policyId))['store_options'];
    }

    /**
     * @param list<array{filter_type: string, value_ar: string}> $rows
     * @return array{rules: array<string, mixed>, store_options: array<string, mixed>}
     */
    private static function parseAllFilterRows(array $rows): array
    {
        $parsed = self::parseFilterRows($rows);
        $defaults = self::defaultStoreOptions();
        $storeOptions = $defaults;
        $storeOptions['visible_client_filters'] = [];
        $storeOptions['client_sort_fields'] = [];
        $hasStoreOptions = false;
        $hasVisibleClientFilters = false;
        $hasClientSortFields = false;
        $visibleFilterWildcard = false;

        foreach ($rows as $row) {
            $type = trim((string) ($row['filter_type'] ?? ''));
            $value = trim((string) ($row['value_ar'] ?? ''));
            if ($type === '' || $value === '' || !str_starts_with($type, 'option_')) {
                continue;
            }
            $hasStoreOptions = true;

            switch ($type) {
                case self::OPTION_ALLOW_SORTING:
                    $storeOptions['allow_sorting'] = self::toBool($value, true);
                    break;
                case self::OPTION_DEFAULT_SORT:
                    $storeOptions['default_sort'] = $value;
                    break;
                case self::OPTION_DEFAULT_GROUP_BY:
                    $storeOptions['default_group_by'] = $value;
                    break;
                case self::OPTION_VISIBLE_CLIENT_FILTER:
                    $hasVisibleClientFilters = true;
                    if ($value === '*') {
                        $visibleFilterWildcard = true;
                    } elseif ($value !== self::VISIBLE_CLIENT_FILTERS_NONE) {
                        $storeOptions['visible_client_filters'][] = $value;
                    }
                    break;
                case self::OPTION_CLIENT_SORT_FIELD:
                    $hasClientSortFields = true;
                    $storeOptions['client_sort_fields'][] = $value;
                    break;
            }
        }

        if ($hasVisibleClientFilters) {
            if ($visibleFilterWildcard && $storeOptions['visible_client_filters'] === []) {
                $storeOptions['visible_client_filters'] = self::ALLOWED_VISIBLE_CLIENT_FILTERS;
            } else {
                $storeOptions['visible_client_filters'] = self::normalizeVisibleClientFilters($storeOptions['visible_client_filters']);
            }
        } else {
            $storeOptions['visible_client_filters'] = $defaults['visible_client_filters'];
        }

        if ($hasClientSortFields) {
            $storeOptions['client_sort_fields'] = self::normalizeClientSortFields($storeOptions['client_sort_fields']);
        } else {
            $storeOptions['client_sort_fields'] = $defaults['client_sort_fields'];
        }

        $storeOptions['default_sort'] = self::normalizeDefaultSort((string) $storeOptions['default_sort']);
        $storeOptions['default_group_by'] = self::normalizeDefaultGroupBy((string) ($storeOptions['default_group_by'] ?? 'none'));
        if ($storeOptions['client_sort_fields'] === []) {
            $storeOptions['client_sort_fields'] = self::clientSortFieldsFromDefaultSort((string) $storeOptions['default_sort']);
        }

        return [
            'rules' => $parsed['rules'],
            'store_options' => $storeOptions,
        ];
    }

    /** @return list<array{filter_type: string, value_ar: string}> */
    private static function loadPolicyFilterRows(string $policyId): array
    {
        self::ensureTable();
        $policyId = trim($policyId);
        if ($policyId === '') {
            return [];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT filter_type, value_ar
             FROM access_policy_filters
             WHERE policy_id = :policy_id
             ORDER BY filter_type, value_ar'
        );
        $stmt->execute(['policy_id' => $policyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $filterRules
     * @param array<string, mixed> $storeOptions
     * @return array{ok: bool, message: string, id?: string}
     */
    public static function save(
        ?string $id,
        string $code,
        string $nameAr,
        string $descriptionAr,
        bool $showPrice,
        bool $showQuantity,
        bool $allowCart,
        bool $allowOrder,
        bool $isActive,
        array $filterRules = [],
        array $storeOptions = []
    ): array {
        self::ensureTable();

        $code = strtolower(trim($code));
        $nameAr = trim($nameAr);
        $descriptionAr = trim($descriptionAr);

        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,78}$/', $code)) {
            return ['ok' => false, 'message' => 'رمز السياسة غير صالح (حروف إنجليزية صغيرة وأرقام و _).'];
        }
        if ($nameAr === '') {
            return ['ok' => false, 'message' => 'اسم السياسة مطلوب.'];
        }

        $pdo = Database::pdo();

        if ($id !== null && $id !== '') {
            $duplicate = $pdo->prepare(
                'SELECT id::text FROM access_policies WHERE code = :code AND id <> :id LIMIT 1'
            );
            $duplicate->execute(['code' => $code, 'id' => $id]);
        } else {
            $duplicate = $pdo->prepare(
                'SELECT id::text FROM access_policies WHERE code = :code LIMIT 1'
            );
            $duplicate->execute(['code' => $code]);
        }
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'رمز السياسة مستخدم مسبقًا.'];
        }

        if ($id !== null && $id !== '') {
            $existing = self::getById($id);
            if ($existing === null) {
                return ['ok' => false, 'message' => 'السياسة غير موجودة.'];
            }

            $stmt = $pdo->prepare(
                'UPDATE access_policies
                 SET code = :code,
                     name_ar = :name_ar,
                     description_ar = :description_ar,
                     show_price = CASE WHEN :show_price = 1 THEN TRUE ELSE FALSE END,
                     show_quantity = CASE WHEN :show_quantity = 1 THEN TRUE ELSE FALSE END,
                     allow_cart = CASE WHEN :allow_cart = 1 THEN TRUE ELSE FALSE END,
                     allow_order = CASE WHEN :allow_order = 1 THEN TRUE ELSE FALSE END,
                     is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'code' => $code,
                'name_ar' => $nameAr,
                'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
                'show_price' => $showPrice ? 1 : 0,
                'show_quantity' => $showQuantity ? 1 : 0,
                'allow_cart' => $allowCart ? 1 : 0,
                'allow_order' => $allowOrder ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
            ]);
            self::syncFilters($id, $filterRules, $storeOptions);

            return ['ok' => true, 'message' => 'تم تحديث السياسة.', 'id' => $id];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO access_policies (
                code, name_ar, description_ar, show_price, show_quantity, allow_cart, allow_order, is_active
             ) VALUES (
                :code, :name_ar, :description_ar,
                CASE WHEN :show_price = 1 THEN TRUE ELSE FALSE END,
                CASE WHEN :show_quantity = 1 THEN TRUE ELSE FALSE END,
                CASE WHEN :allow_cart = 1 THEN TRUE ELSE FALSE END,
                CASE WHEN :allow_order = 1 THEN TRUE ELSE FALSE END,
                CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'code' => $code,
            'name_ar' => $nameAr,
            'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
            'show_price' => $showPrice ? 1 : 0,
            'show_quantity' => $showQuantity ? 1 : 0,
            'allow_cart' => $allowCart ? 1 : 0,
            'allow_order' => $allowOrder ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
        ]);
        $newId = (string) $stmt->fetchColumn();
        self::syncFilters($newId, $filterRules, $storeOptions);

        return ['ok' => true, 'message' => 'تم إنشاء السياسة.', 'id' => $newId];
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $storeOptions
     */
    public static function syncFilters(string $policyId, array $payload, array $storeOptions = []): void
    {
        self::ensureTable();
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM access_policy_filters WHERE policy_id = :id')->execute(['id' => $policyId]);

        $insert = $pdo->prepare(
            'INSERT INTO access_policy_filters (policy_id, filter_type, value_ar)
             VALUES (:policy_id, :filter_type, :value_ar)'
        );

        $insertValues = static function (string $type, array $values) use ($insert, $policyId): void {
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $insert->execute([
                    'policy_id' => $policyId,
                    'filter_type' => $type,
                    'value_ar' => $value,
                ]);
            }
        };

        $insertNumber = static function (string $type, mixed $value) use ($insert, $policyId): void {
            if ($value === null || $value === '') {
                return;
            }
            $insert->execute([
                'policy_id' => $policyId,
                'filter_type' => $type,
                'value_ar' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ]);
        };

        $keyword = trim((string) ($payload['keyword'] ?? ''));
        if ($keyword !== '') {
            $insert->execute([
                'policy_id' => $policyId,
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

        $visibleClientFilters = self::normalizeVisibleClientFilters($storeOptions['visible_client_filters'] ?? []);
        if ($visibleClientFilters === []) {
            $insert->execute([
                'policy_id' => $policyId,
                'filter_type' => self::OPTION_VISIBLE_CLIENT_FILTER,
                'value_ar' => self::VISIBLE_CLIENT_FILTERS_NONE,
            ]);
        } else {
            $insertValues(self::OPTION_VISIBLE_CLIENT_FILTER, $visibleClientFilters);
        }

        $insert->execute([
            'policy_id' => $policyId,
            'filter_type' => self::OPTION_ALLOW_SORTING,
            'value_ar' => !empty($storeOptions['allow_sorting']) ? '1' : '0',
        ]);

        $defaultSort = self::normalizeDefaultSort((string) ($storeOptions['default_sort'] ?? 'number:asc'));
        $insert->execute([
            'policy_id' => $policyId,
            'filter_type' => self::OPTION_DEFAULT_SORT,
            'value_ar' => $defaultSort,
        ]);

        $defaultGroupBy = self::normalizeDefaultGroupBy((string) ($storeOptions['default_group_by'] ?? 'none'));
        if ($defaultGroupBy !== 'none') {
            $insert->execute([
                'policy_id' => $policyId,
                'filter_type' => self::OPTION_DEFAULT_GROUP_BY,
                'value_ar' => $defaultGroupBy,
            ]);
        }

        $insertValues(
            self::OPTION_CLIENT_SORT_FIELD,
            self::normalizeClientSortFields($storeOptions['client_sort_fields'] ?? [])
        );
    }

    public static function setActive(string $id, bool $active): bool
    {
        if (!$active) {
            $usage = self::usageSummary($id);
            if (($usage['guest_default'] ?? false) === true) {
                return false;
            }
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE access_policies
             SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'is_active' => $active ? 1 : 0]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(string $id): array
    {
        $usage = self::usageSummary($id);
        $total = (int) ($usage['share_links'] ?? 0) + (int) ($usage['customers'] ?? 0);
        if (($usage['guest_default'] ?? false) === true) {
            return ['ok' => false, 'message' => 'لا يمكن حذف السياسة الافتراضية للزائر. غيّر السياسة الافتراضية أولًا.'];
        }
        if ($total > 0) {
            return ['ok' => false, 'message' => 'السياسة مستخدمة في ' . $total . ' سجل (عملاء أو روابط مشاركة). عطّلها بدل الحذف.'];
        }

        $stmt = Database::pdo()->prepare('DELETE FROM access_policies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'message' => 'السياسة غير موجودة.'];
        }

        return ['ok' => true, 'message' => 'تم حذف السياسة.'];
    }

    /** @return array{guest_default: bool, share_links: int, customers: int} */
    public static function usageSummary(string $id): array
    {
        $pdo = Database::pdo();

        $guestDefault = $pdo->prepare(
            'SELECT COUNT(*) FROM store_guest_settings WHERE id = 1 AND access_policy_id = :id'
        );
        $guestDefault->execute(['id' => $id]);

        $shareLinks = $pdo->prepare('SELECT COUNT(*) FROM share_links WHERE access_policy_id = :id');
        $shareLinks->execute(['id' => $id]);

        $customers = $pdo->prepare('SELECT COUNT(*) FROM web_customers WHERE access_policy_id = :id');
        $customers->execute(['id' => $id]);

        return [
            'guest_default' => (int) $guestDefault->fetchColumn() > 0,
            'share_links' => (int) $shareLinks->fetchColumn(),
            'customers' => (int) $customers->fetchColumn(),
        ];
    }

    /** @return array<string, mixed> */
    public static function defaultFilterRules(): array
    {
        return [
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
    }

    /**
     * @param list<array{filter_type: string, value_ar: string}> $rows
     * @return array{rules: array<string, mixed>}
     */
    private static function parseFilterRows(array $rows): array
    {
        $rules = self::defaultFilterRules();

        foreach ($rows as $row) {
            $type = trim((string) ($row['filter_type'] ?? ''));
            $value = trim((string) ($row['value_ar'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            match ($type) {
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

        return ['rules' => $rules];
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

    /** @return array{
     *   visible_client_filters: list<string>,
     *   allow_sorting: bool,
     *   client_sort_fields: list<string>,
     *   default_sort: string,
     *   default_group_by: string
     * } */
    public static function defaultStoreOptions(): array
    {
        return [
            'visible_client_filters' => ['search', 'materialTypes', 'manufacturers', 'availability', 'sort'],
            'allow_sorting' => true,
            'client_sort_fields' => ['number', 'materialType', 'manufacturer'],
            'default_sort' => 'number:asc',
            'default_group_by' => 'none',
        ];
    }

    /** @param array<string, mixed> $storeOptions @return list<string> */
    public static function resolvedVisibleClientFilters(array $storeOptions): array
    {
        $filters = $storeOptions['visible_client_filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        return self::normalizeVisibleClientFilters($filters);
    }

    /** @param array<int, mixed> $values @return list<string> */
    public static function normalizeVisibleClientFilters(array $values): array
    {
        $normalized = self::normalizeFilterValues($values);
        $allowed = array_flip(self::ALLOWED_VISIBLE_CLIENT_FILTERS);
        $filtered = [];
        foreach ($normalized as $value) {
            if (isset($allowed[$value])) {
                $filtered[] = $value;
            }
        }

        return array_values(array_unique($filtered));
    }

    /** @param array<int, mixed> $values @return list<string> */
    private static function normalizeClientSortFields(array $values): array
    {
        $normalized = self::normalizeFilterValues($values);
        $allowed = array_flip(self::ALLOWED_CLIENT_SORT_FIELDS);
        $filtered = [];
        foreach ($normalized as $value) {
            if (isset($allowed[$value])) {
                $filtered[] = $value;
            }
        }

        return array_values(array_unique($filtered));
    }

    /** @return list<string> */
    private static function clientSortFieldsFromDefaultSort(string $defaultSort): array
    {
        $fields = [];
        foreach (array_filter(array_map('trim', explode(',', $defaultSort))) as $clause) {
            if ($clause === '') {
                continue;
            }
            $field = str_starts_with($clause, '-') ? substr($clause, 1) : explode(':', $clause, 2)[0];
            $field = trim((string) $field);
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        $fields = self::normalizeClientSortFields($fields);

        return $fields !== [] ? $fields : ['number', 'materialType', 'manufacturer'];
    }

    private static function normalizeDefaultSort(string $value): string
    {
        $value = trim($value);
        $allowed = [
            'number:asc',
            'number:desc',
            'name:asc',
            'name:desc',
            '-unitSalePriceSyp',
            'unitSalePriceSyp:desc',
            '-unitSalePriceUsd',
            'unitSalePriceUsd:desc',
        ];

        return in_array($value, $allowed, true) ? $value : 'number:asc';
    }

    private static function normalizeDefaultGroupBy(string $value): string
    {
        $value = trim($value);

        return in_array($value, ['none', 'ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
            ? $value
            : 'none';
    }

    private static function toBool(string $value, bool $default): bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /** @param array<int, mixed> $values @return list<string> */
    private static function normalizeFilterValues(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }
}
