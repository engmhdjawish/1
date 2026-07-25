<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ApiClient;
use Portal\Services\ShareCartService;
use Portal\Services\ShareLinkService;
use Portal\Support\SharePageAccess;
use Portal\Support\Text;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$shareLink = $token !== '' ? ShareLinkService::getByPublicToken($token) : null;
$error = null;
$apiError = null;
$cartNotice = null;

if ($token === '') {
    $error = 'يرجى فتح الصفحة باستخدام رابط مشاركة صحيح يحتوي على token.';
}

if ($token !== '' && $shareLink === null) {
    $error = 'الرابط غير صالح أو غير نشط أو منتهي الصلاحية.';
}

$requiresPassword = (bool) (is_array($shareLink) && (($shareLink['require_password'] ?? 0) ? true : false));
if (!isset($_SESSION['share_link_access']) || !is_array($_SESSION['share_link_access'])) {
    $_SESSION['share_link_access'] = [];
}
$hasAccess = !$requiresPassword || !empty($_SESSION['share_link_access'][$token]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock' && $shareLink !== null) {
    $userName = trim((string) ($_POST['access_username'] ?? ''));
    $password = trim((string) ($_POST['access_password'] ?? ''));
    if (SharePageAccess::unlock($token, $userName, $password)) {
        $hasAccess = true;
    } else {
        $error = 'بيانات الدخول غير صحيحة.';
    }
}

$policyFlags = SharePageAccess::policyFlags($shareLink);
$allowCart = $policyFlags['allow_cart'];
$allowOrder = $policyFlags['allow_order'];

if ($shareLink !== null && $hasAccess && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart' && $allowCart) {
    $quantity = max(1, (int) round((float) ($_POST['quantity'] ?? 1)));
    $capturePrices = (bool) (($shareLink['show_price'] ?? 0) ? true : false);
    $line = ShareCartService::lineFromForm($_POST, $capturePrices);
    if ($line['material_guid'] !== '') {
        $result = ShareCartService::add($token, (string) ($shareLink['id'] ?? ''), $line, (float) $quantity);
        if ($result['ok']) {
            $cartNotice = $result['message'] !== '' ? $result['message'] : 'تمت إضافة الطرد إلى السلة.';
        } else {
            $cartNotice = $result['message'] !== '' ? $result['message'] : 'تعذر الإضافة إلى السلة.';
        }
    }
}

$parseList = static function (string $key): array {
    $raw = $_GET[$key] ?? [];
    $values = is_array($raw) ? $raw : explode(',', (string) $raw);
    $result = [];
    foreach ($values as $value) {
        $item = trim((string) $value);
        if ($item !== '') {
            $result[] = $item;
        }
    }
    return array_values(array_unique($result));
};
$parseNullableFloat = static function (string $key): ?float {
    $value = trim((string) ($_GET[$key] ?? ''));
    return $value !== '' && is_numeric($value) ? (float) $value : null;
};
$parseNullableBool = static function (string $key): ?bool {
    $value = trim(strtolower((string) ($_GET[$key] ?? '')));
    return match ($value) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => null,
    };
};

$shareOptions = is_array($shareLink) ? (array) ($shareLink['options'] ?? []) : [];
$visibleClientFilters = array_values(array_map(
    'strval',
    is_array($shareOptions['visible_client_filters'] ?? null)
        ? $shareOptions['visible_client_filters']
        : []
));
$allowClientFilters = $visibleClientFilters !== []
    || (bool) (($shareOptions['allow_client_filters'] ?? false) ? true : false);
$allowSorting = (bool) (($shareOptions['allow_sorting'] ?? true) ? true : false);
$useDynamicResultFilters = $allowClientFilters && (bool) (($shareOptions['include_result_filters'] ?? true) ? true : false);
$defaultSort = trim((string) ($shareOptions['default_sort'] ?? 'number:asc'));
$clientSortFields = array_values(array_map('strval', is_array($shareOptions['client_sort_fields'] ?? null) ? $shareOptions['client_sort_fields'] : []));
if ($clientSortFields === []) {
    $clientSortFields = ['number', 'materialType', 'manufacturer'];
}
$sortFieldLabels = [
    'number' => 'رقم المادة',
    'materialType' => 'نوع المادة',
    'ageCategory' => 'الفئة العمرية',
    'manufacturer' => 'الشركة',
    'sizeRange' => 'القياس',
    'countryOfOrigin' => 'بلد المنشأ',
    'unitSalePriceSyp' => 'سعر البيع ل.س',
    'unitSalePriceUsd' => 'سعر البيع $',
];
$clientSortFields = array_values(array_filter($clientSortFields, static fn (string $field): bool => isset($sortFieldLabels[$field])));
if ($clientSortFields === []) {
    $clientSortFields = ['number'];
}
$defaultSort = $defaultSort !== '' ? $defaultSort : 'number:asc';
$defaultGroupBy = trim((string) ($shareOptions['default_group_by'] ?? 'none'));
$defaultGroupBy = in_array($defaultGroupBy, ['none', 'ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
    ? $defaultGroupBy
    : 'none';
$isClientFilterVisible = static function (string $code) use ($visibleClientFilters): bool {
    return in_array($code, $visibleClientFilters, true);
};

$forcedMaterialTypes = array_map('strval', is_array($shareLink) ? ($shareLink['forced_material_types'] ?? []) : []);
$forcedAgeCategories = array_map('strval', is_array($shareLink) ? ($shareLink['forced_age_categories'] ?? []) : []);
$forcedManufacturers = array_map('strval', is_array($shareLink) ? ($shareLink['forced_manufacturers'] ?? []) : []);
$forcedSizeRanges = array_map('strval', is_array($shareLink) ? ($shareLink['forced_size_ranges'] ?? []) : []);
$forcedCountryOrigins = array_map('strval', is_array($shareLink) ? ($shareLink['forced_country_origins'] ?? []) : []);
$forcedStoreGuids = array_map('strval', is_array($shareLink) ? ($shareLink['forced_store_guids'] ?? []) : []);
$forcedGroupGuids = array_map('strval', is_array($shareLink) ? ($shareLink['forced_group_guids'] ?? []) : []);
$constraints = is_array($shareLink) && is_array($shareLink['constraints'] ?? null) ? $shareLink['constraints'] : [];
$forcedIsAvailable = array_key_exists('is_available', $constraints) ? $constraints['is_available'] : null;
$forcedHasImage = array_key_exists('has_image', $constraints) ? $constraints['has_image'] : null;
$forcedMinWarehouseQuantity = isset($constraints['min_warehouse_quantity']) && is_numeric((string) $constraints['min_warehouse_quantity'])
    ? (float) $constraints['min_warehouse_quantity']
    : null;
$forcedMaxWarehouseQuantity = isset($constraints['max_warehouse_quantity']) && is_numeric((string) $constraints['max_warehouse_quantity'])
    ? (float) $constraints['max_warehouse_quantity']
    : null;
$forcedMinUnitSalePriceSyp = isset($constraints['min_unit_sale_price_syp']) && is_numeric((string) $constraints['min_unit_sale_price_syp'])
    ? (float) $constraints['min_unit_sale_price_syp']
    : null;
$forcedMaxUnitSalePriceSyp = isset($constraints['max_unit_sale_price_syp']) && is_numeric((string) $constraints['max_unit_sale_price_syp'])
    ? (float) $constraints['max_unit_sale_price_syp']
    : null;
$forcedMinUnitSalePriceUsd = isset($constraints['min_unit_sale_price_usd']) && is_numeric((string) $constraints['min_unit_sale_price_usd'])
    ? (float) $constraints['min_unit_sale_price_usd']
    : null;
$forcedMaxUnitSalePriceUsd = isset($constraints['max_unit_sale_price_usd']) && is_numeric((string) $constraints['max_unit_sale_price_usd'])
    ? (float) $constraints['max_unit_sale_price_usd']
    : null;
$forcedMinUnitPurchasePriceUsd = isset($constraints['min_unit_purchase_price_usd']) && is_numeric((string) $constraints['min_unit_purchase_price_usd'])
    ? (float) $constraints['min_unit_purchase_price_usd']
    : null;
$forcedMaxUnitPurchasePriceUsd = isset($constraints['max_unit_purchase_price_usd']) && is_numeric((string) $constraints['max_unit_purchase_price_usd'])
    ? (float) $constraints['max_unit_purchase_price_usd']
    : null;

$selectedMaterialTypes = ($allowClientFilters && $isClientFilterVisible('materialTypes')) ? $parseList('materialTypes') : [];
$selectedAgeCategories = ($allowClientFilters && $isClientFilterVisible('ageCategories')) ? $parseList('ageCategories') : [];
$selectedManufacturers = ($allowClientFilters && $isClientFilterVisible('manufacturers')) ? $parseList('manufacturers') : [];
$selectedSizeRanges = ($allowClientFilters && $isClientFilterVisible('sizeRanges')) ? $parseList('sizeRanges') : [];
$selectedCountryOrigins = ($allowClientFilters && $isClientFilterVisible('countryOfOrigins')) ? $parseList('countryOfOrigins') : [];
$selectedStoreGuids = ($allowClientFilters && $isClientFilterVisible('stores')) ? $parseList('storeGuids') : [];
$selectedGroupGuids = ($allowClientFilters && $isClientFilterVisible('groups')) ? $parseList('groupGuids') : [];
$selectedIsAvailable = ($allowClientFilters && $isClientFilterVisible('availability')) ? $parseNullableBool('isAvailable') : null;
$selectedMinWarehouseQuantity = ($allowClientFilters && $isClientFilterVisible('warehouseRange')) ? $parseNullableFloat('minWarehouseQuantity') : null;
$selectedMaxWarehouseQuantity = ($allowClientFilters && $isClientFilterVisible('warehouseRange')) ? $parseNullableFloat('maxWarehouseQuantity') : null;
$selectedMinUnitSalePriceSyp = ($allowClientFilters && $isClientFilterVisible('priceSaleSyp')) ? $parseNullableFloat('minUnitSalePriceSyp') : null;
$selectedMaxUnitSalePriceSyp = ($allowClientFilters && $isClientFilterVisible('priceSaleSyp')) ? $parseNullableFloat('maxUnitSalePriceSyp') : null;
$selectedMinUnitSalePriceUsd = ($allowClientFilters && $isClientFilterVisible('priceSaleUsd')) ? $parseNullableFloat('minUnitSalePriceUsd') : null;
$selectedMaxUnitSalePriceUsd = ($allowClientFilters && $isClientFilterVisible('priceSaleUsd')) ? $parseNullableFloat('maxUnitSalePriceUsd') : null;
$selectedMinUnitPurchasePriceUsd = ($allowClientFilters && $isClientFilterVisible('pricePurchaseUsd')) ? $parseNullableFloat('minUnitPurchasePriceUsd') : null;
$selectedMaxUnitPurchasePriceUsd = ($allowClientFilters && $isClientFilterVisible('pricePurchaseUsd')) ? $parseNullableFloat('maxUnitPurchasePriceUsd') : null;

$mergeConstrainedValues = static function (array $forced, array $selected, bool &$hasConflict): array {
    if ($forced === []) {
        return $selected;
    }
    if ($selected === []) {
        return $forced;
    }

    $forcedMap = [];
    foreach ($forced as $value) {
        $forcedMap[strtolower($value)] = $value;
    }
    $intersection = [];
    foreach ($selected as $value) {
        $key = strtolower($value);
        if (isset($forcedMap[$key])) {
            $intersection[] = $forcedMap[$key];
        }
    }
    $intersection = array_values(array_unique($intersection));
    if ($intersection === []) {
        $hasConflict = true;
    }
    return $intersection;
};

$hasConstraintConflict = false;
$queryMaterialTypes = $mergeConstrainedValues($forcedMaterialTypes, $selectedMaterialTypes, $hasConstraintConflict);
$queryAgeCategories = $mergeConstrainedValues($forcedAgeCategories, $selectedAgeCategories, $hasConstraintConflict);
$queryManufacturers = $mergeConstrainedValues($forcedManufacturers, $selectedManufacturers, $hasConstraintConflict);
$querySizeRanges = $mergeConstrainedValues($forcedSizeRanges, $selectedSizeRanges, $hasConstraintConflict);
$queryCountryOrigins = $mergeConstrainedValues($forcedCountryOrigins, $selectedCountryOrigins, $hasConstraintConflict);
$queryStoreGuids = $mergeConstrainedValues($forcedStoreGuids, $selectedStoreGuids, $hasConstraintConflict);
$queryGroupGuids = $mergeConstrainedValues($forcedGroupGuids, $selectedGroupGuids, $hasConstraintConflict);

$mergeMin = static function (?float $forced, ?float $selected): ?float {
    if ($forced === null) {
        return $selected;
    }
    if ($selected === null) {
        return $forced;
    }

    return max($forced, $selected);
};
$mergeMax = static function (?float $forced, ?float $selected): ?float {
    if ($forced === null) {
        return $selected;
    }
    if ($selected === null) {
        return $forced;
    }

    return min($forced, $selected);
};
$validateRange = static function (?float $min, ?float $max, bool &$hasConflict): void {
    if ($min !== null && $max !== null && $min > $max) {
        $hasConflict = true;
    }
};
$mergeBool = static function (?bool $forced, ?bool $selected, bool &$hasConflict): ?bool {
    if ($forced === null) {
        return $selected;
    }
    if ($selected === null) {
        return $forced;
    }
    if ($forced !== $selected) {
        $hasConflict = true;
    }
    return $forced;
};

$queryIsAvailable = $mergeBool(is_bool($forcedIsAvailable) ? $forcedIsAvailable : null, $selectedIsAvailable, $hasConstraintConflict);
$queryHasImage = is_bool($forcedHasImage) ? $forcedHasImage : null;
$queryMinWarehouseQuantity = $mergeMin($forcedMinWarehouseQuantity, $selectedMinWarehouseQuantity);
$queryMaxWarehouseQuantity = $mergeMax($forcedMaxWarehouseQuantity, $selectedMaxWarehouseQuantity);
$queryMinUnitSalePriceSyp = $mergeMin($forcedMinUnitSalePriceSyp, $selectedMinUnitSalePriceSyp);
$queryMaxUnitSalePriceSyp = $mergeMax($forcedMaxUnitSalePriceSyp, $selectedMaxUnitSalePriceSyp);
$queryMinUnitSalePriceUsd = $mergeMin($forcedMinUnitSalePriceUsd, $selectedMinUnitSalePriceUsd);
$queryMaxUnitSalePriceUsd = $mergeMax($forcedMaxUnitSalePriceUsd, $selectedMaxUnitSalePriceUsd);
$queryMinUnitPurchasePriceUsd = $mergeMin($forcedMinUnitPurchasePriceUsd, $selectedMinUnitPurchasePriceUsd);
$queryMaxUnitPurchasePriceUsd = $mergeMax($forcedMaxUnitPurchasePriceUsd, $selectedMaxUnitPurchasePriceUsd);

$validateRange($queryMinWarehouseQuantity, $queryMaxWarehouseQuantity, $hasConstraintConflict);
$validateRange($queryMinUnitSalePriceSyp, $queryMaxUnitSalePriceSyp, $hasConstraintConflict);
$validateRange($queryMinUnitSalePriceUsd, $queryMaxUnitSalePriceUsd, $hasConstraintConflict);
$validateRange($queryMinUnitPurchasePriceUsd, $queryMaxUnitPurchasePriceUsd, $hasConstraintConflict);

$baseKeyword = trim((string) (is_array($shareLink) ? ($shareLink['keyword'] ?? '') : ''));
$userKeyword = ($allowClientFilters && $isClientFilterVisible('search')) ? trim((string) ($_GET['q'] ?? '')) : '';
$search = trim($baseKeyword . ' ' . $userKeyword);
$search = $search !== '' ? $search : null;

$parseSortClause = static function (string $clause): array {
    $clause = trim($clause);
    if ($clause === '') {
        return ['field' => 'number', 'dir' => 'asc'];
    }
    if (str_starts_with($clause, '-')) {
        return ['field' => trim(substr($clause, 1)), 'dir' => 'desc'];
    }
    $parts = explode(':', $clause, 2);

    return [
        'field' => trim($parts[0]) !== '' ? trim($parts[0]) : 'number',
        'dir' => strtolower(trim($parts[1] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
    ];
};
$defaultSortParsed = $parseSortClause(explode(',', $defaultSort)[0] ?? 'number:asc');
$requestedSort = $allowSorting ? trim((string) ($_GET['sort'] ?? '')) : '';
$activeSortParsed = $requestedSort !== ''
    ? $parseSortClause(explode(',', $requestedSort)[0] ?? $requestedSort)
    : $defaultSortParsed;
if (!in_array($activeSortParsed['field'], $clientSortFields, true)) {
    $activeSortParsed = ['field' => $clientSortFields[0], 'dir' => 'asc'];
}
$selectedSort = $activeSortParsed['field'] . ':' . $activeSortParsed['dir'];
$buildNextSortValue = static function (string $field) use ($activeSortParsed): string {
    if ($activeSortParsed['field'] !== $field) {
        return $field . ':asc';
    }

    return $activeSortParsed['dir'] === 'asc' ? $field . ':desc' : $field . ':asc';
};
$selectedGroupBy = ($allowClientFilters && $isClientFilterVisible('groupBy'))
    ? trim((string) ($_GET['groupBy'] ?? $defaultGroupBy))
    : $defaultGroupBy;
$selectedGroupBy = in_array($selectedGroupBy, ['none', 'ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
    ? $selectedGroupBy
    : 'none';

$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 24;
$totalCount = 0;
$products = [];
$resultFilters = [];
$filterOptions = [
    'materialTypes' => [],
    'ageCategories' => [],
    'manufacturers' => [],
    'sizeRanges' => [],
    'countryOfOrigins' => [],
    'stores' => [],
    'groups' => [],
    'priceRanges' => [
        'unitSalePriceSyp' => null,
        'unitSalePriceUsd' => null,
        'unitPurchasePriceUsd' => null,
    ],
];

if ($shareLink !== null && $hasAccess && !$hasConstraintConflict) {
    try {
        $optionsResponse = ApiClient::get('/api/materials/filter-options');
        if ($optionsResponse['ok']) {
            $optionsData = is_array($optionsResponse['data']) ? $optionsResponse['data'] : [];
            $stores = is_array($optionsData['stores'] ?? null) ? $optionsData['stores'] : (is_array($optionsData['Stores'] ?? null) ? $optionsData['Stores'] : []);
            $groups = is_array($optionsData['groups'] ?? null) ? $optionsData['groups'] : (is_array($optionsData['Groups'] ?? null) ? $optionsData['Groups'] : []);
            $priceRanges = is_array($optionsData['priceRanges'] ?? null) ? $optionsData['priceRanges'] : (is_array($optionsData['PriceRanges'] ?? null) ? $optionsData['PriceRanges'] : null);
            $filterOptions = [
                'materialTypes' => array_values(array_map('strval', is_array($optionsData['materialTypes'] ?? null) ? $optionsData['materialTypes'] : ($optionsData['MaterialTypes'] ?? []))),
                'ageCategories' => array_values(array_map('strval', is_array($optionsData['ageCategories'] ?? null) ? $optionsData['ageCategories'] : ($optionsData['AgeCategories'] ?? []))),
                'manufacturers' => array_values(array_map('strval', is_array($optionsData['manufacturers'] ?? null) ? $optionsData['manufacturers'] : ($optionsData['Manufacturers'] ?? []))),
                'sizeRanges' => array_values(array_map('strval', is_array($optionsData['sizeRanges'] ?? null) ? $optionsData['sizeRanges'] : ($optionsData['SizeRanges'] ?? []))),
                'countryOfOrigins' => array_values(array_map('strval', is_array($optionsData['countryOfOrigins'] ?? null) ? $optionsData['countryOfOrigins'] : ($optionsData['CountryOfOrigins'] ?? []))),
                'stores' => array_values(array_filter($stores, static fn ($row) => is_array($row))),
                'groups' => array_values(array_filter($groups, static fn ($row) => is_array($row))),
                'priceRanges' => is_array($priceRanges)
                    ? $priceRanges
                    : [
                        'unitSalePriceSyp' => null,
                        'unitSalePriceUsd' => null,
                        'unitPurchasePriceUsd' => null,
                    ],
            ];
        }

        $params = array_filter([
            'page' => $page,
            'pageSize' => 24,
            'keyword' => $search,
            'storeGuids' => $queryStoreGuids !== [] ? implode(',', $queryStoreGuids) : null,
            'materialTypes' => $queryMaterialTypes !== [] ? implode(',', $queryMaterialTypes) : null,
            'ageCategories' => $queryAgeCategories !== [] ? implode(',', $queryAgeCategories) : null,
            'manufacturers' => $queryManufacturers !== [] ? implode(',', $queryManufacturers) : null,
            'sizeRanges' => $querySizeRanges !== [] ? implode(',', $querySizeRanges) : null,
            'countryOfOrigins' => $queryCountryOrigins !== [] ? implode(',', $queryCountryOrigins) : null,
            'groupGuids' => $queryGroupGuids !== [] ? implode(',', $queryGroupGuids) : null,
            'isAvailable' => $queryIsAvailable === null ? null : ($queryIsAvailable ? 'true' : 'false'),
            'hasImage' => $queryHasImage === null ? null : ($queryHasImage ? 'true' : 'false'),
            'minWarehouseQuantity' => $queryMinWarehouseQuantity,
            'maxWarehouseQuantity' => $queryMaxWarehouseQuantity,
            'minUnitSalePriceSyp' => $queryMinUnitSalePriceSyp,
            'maxUnitSalePriceSyp' => $queryMaxUnitSalePriceSyp,
            'minUnitSalePriceUsd' => $queryMinUnitSalePriceUsd,
            'maxUnitSalePriceUsd' => $queryMaxUnitSalePriceUsd,
            'minUnitPurchasePriceUsd' => $queryMinUnitPurchasePriceUsd,
            'maxUnitPurchasePriceUsd' => $queryMaxUnitPurchasePriceUsd,
            'groupBy' => $selectedGroupBy !== 'none' ? $selectedGroupBy : null,
            'sort' => $selectedSort,
            'includeResultFilters' => $useDynamicResultFilters ? 'true' : 'false',
        ], static fn ($value) => $value !== null && $value !== '');

        $materials = ApiClient::get('/api/materials', $params);
        if (!$materials['ok'] && (int) ($materials['status'] ?? 0) === 400) {
            // Fallback to a safer query if strict filters are rejected.
            $fallbackParams = array_filter([
                'page' => $page,
                'pageSize' => 24,
                'keyword' => $search,
                'materialTypes' => $queryMaterialTypes !== [] ? implode(',', $queryMaterialTypes) : null,
                'ageCategories' => $queryAgeCategories !== [] ? implode(',', $queryAgeCategories) : null,
                'manufacturers' => $queryManufacturers !== [] ? implode(',', $queryManufacturers) : null,
                'sizeRanges' => $querySizeRanges !== [] ? implode(',', $querySizeRanges) : null,
                'countryOfOrigins' => $queryCountryOrigins !== [] ? implode(',', $queryCountryOrigins) : null,
                'sort' => 'number:asc',
                'includeResultFilters' => $useDynamicResultFilters ? 'true' : 'false',
            ], static fn ($value) => $value !== null && $value !== '');

            $retry = ApiClient::get('/api/materials', $fallbackParams);
            if ($retry['ok']) {
                $materials = $retry;
                $apiError = 'تم تجاهل بعض قيود الرابط لعدم توافقها مع API وتم عرض النتائج بالوضع الآمن.';
            }
        }

        $extractApiError = static function (array $response): string {
            $status = (int) ($response['status'] ?? 0);
            $data = $response['data'] ?? null;
            if (is_array($data)) {
                $messages = [];
                if (!empty($data['title']) && is_string($data['title'])) {
                    $messages[] = trim($data['title']);
                }
                if (!empty($data['detail']) && is_string($data['detail'])) {
                    $messages[] = trim($data['detail']);
                }
                if (isset($data['errors']) && is_array($data['errors'])) {
                    foreach ($data['errors'] as $field => $fieldErrors) {
                        if (!is_array($fieldErrors)) {
                            continue;
                        }
                        foreach ($fieldErrors as $fieldError) {
                            $errorText = trim((string) $fieldError);
                            if ($errorText !== '') {
                                $messages[] = $field . ': ' . $errorText;
                            }
                        }
                    }
                }
                $messages = array_values(array_unique(array_filter($messages, static fn ($value) => trim((string) $value) !== '')));
                if ($messages !== []) {
                    return 'تعذر جلب المواد من API (رمز ' . $status . '): ' . implode(' | ', $messages);
                }
            }

            $raw = trim((string) ($response['raw'] ?? ''));
            if ($raw !== '') {
                return 'تعذر جلب المواد من API (رمز ' . $status . '): ' . substr($raw, 0, 260);
            }

            return 'تعذر جلب المواد من API (رمز ' . $status . ')';
        };

        if ($materials['ok']) {
            $products = $materials['data']['items'] ?? [];
            $totalCount = max(0, (int) ($materials['data']['totalCount'] ?? 0));
            $page = max(1, (int) ($materials['data']['page'] ?? $page));
            $pageSize = max(1, (int) ($materials['data']['pageSize'] ?? $pageSize));
            $resultFilters = $materials['data']['resultFilters'] ?? [];
            if (!is_array($resultFilters)) {
                $resultFilters = [];
            }
            $normalizeFacetKey = static fn (string $value): string => Text::lower($value);
            $scopeStringFacets = static function (array $facets, array $forced) use ($normalizeFacetKey): array {
                $withResults = array_values(array_filter($facets, static function (array $facet): bool {
                    $count = $facet['count'] ?? null;

                    return $count !== null && (int) $count > 0;
                }));
                if ($forced === []) {
                    return $withResults;
                }
                $allowed = [];
                foreach ($forced as $value) {
                    $allowed[$normalizeFacetKey((string) $value)] = true;
                }

                return array_values(array_filter($withResults, static function (array $facet) use ($allowed, $normalizeFacetKey): bool {
                    return isset($allowed[$normalizeFacetKey((string) ($facet['value'] ?? ''))]);
                }));
            };
            $scopeGroupFacets = static function (array $facets, array $forcedGuids): array {
                $withResults = array_values(array_filter($facets, static function (array $facet): bool {
                    $count = $facet['count'] ?? null;

                    return $count !== null && (int) $count > 0;
                }));
                if ($forcedGuids === []) {
                    return $withResults;
                }
                $allowed = array_flip(array_map('strtolower', $forcedGuids));

                return array_values(array_filter($withResults, static function (array $facet) use ($allowed): bool {
                    $guid = strtolower((string) ($facet['guid'] ?? ''));

                    return $guid !== '' && isset($allowed[$guid]);
                }));
            };

            $resultFilters['materialTypes'] = $scopeStringFacets(
                is_array($resultFilters['materialTypes'] ?? null) ? $resultFilters['materialTypes'] : [],
                $forcedMaterialTypes
            );
            $resultFilters['ageCategories'] = $scopeStringFacets(
                is_array($resultFilters['ageCategories'] ?? null) ? $resultFilters['ageCategories'] : [],
                $forcedAgeCategories
            );
            $resultFilters['manufacturers'] = $scopeStringFacets(
                is_array($resultFilters['manufacturers'] ?? null) ? $resultFilters['manufacturers'] : [],
                $forcedManufacturers
            );
            $resultFilters['sizeRanges'] = $scopeStringFacets(
                is_array($resultFilters['sizeRanges'] ?? null) ? $resultFilters['sizeRanges'] : [],
                $forcedSizeRanges
            );
            $resultFilters['countryOfOrigins'] = $scopeStringFacets(
                is_array($resultFilters['countryOfOrigins'] ?? null) ? $resultFilters['countryOfOrigins'] : [],
                $forcedCountryOrigins
            );
            $resultFilters['groups'] = $scopeGroupFacets(
                is_array($resultFilters['groups'] ?? null) ? $resultFilters['groups'] : [],
                $forcedGroupGuids
            );
        } else {
            $apiError = $extractApiError($materials);
        }
    } catch (\Throwable $exception) {
        $apiError = $exception->getMessage();
    }
}

$showImages = (bool) (($shareOptions['show_images'] ?? true) ? true : false);
$priceMode = (string) ($shareOptions['price_mode'] ?? 'both');
if (!(is_array($shareLink) && (($shareLink['show_price'] ?? 0) ? true : false))) {
    $priceMode = 'none';
}
$showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
$showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
$showQuantity = (bool) (is_array($shareLink) && (($shareLink['show_quantity'] ?? 0) ? true : false));

$storeOptions = array_values(array_filter($filterOptions['stores'] ?? [], static function ($row): bool {
    if (!is_array($row)) {
        return false;
    }
    return trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== '';
}));
$groupOptions = array_values(array_filter($filterOptions['groups'] ?? [], static function ($row): bool {
    if (!is_array($row)) {
        return false;
    }
    return trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== '';
}));
if ($forcedStoreGuids !== []) {
    $forcedStoreMap = array_flip(array_map('strtolower', $forcedStoreGuids));
    $storeOptions = array_values(array_filter($storeOptions, static function (array $store) use ($forcedStoreMap): bool {
        $guid = strtolower((string) ($store['guid'] ?? $store['Guid'] ?? ''));

        return $guid !== '' && isset($forcedStoreMap[$guid]);
    }));
}
if (isset($resultFilters['groups']) && is_array($resultFilters['groups']) && $resultFilters['groups'] !== []) {
    $groupOptions = [];
    foreach ($resultFilters['groups'] as $groupFacet) {
        if (!is_array($groupFacet)) {
            continue;
        }
        $groupGuid = trim((string) ($groupFacet['guid'] ?? ''));
        if ($groupGuid === '') {
            continue;
        }
        $groupOptions[] = [
            'guid' => $groupGuid,
            'name' => (string) ($groupFacet['name'] ?? $groupFacet['code'] ?? $groupGuid),
            'code' => $groupFacet['code'] ?? null,
        ];
    }
} elseif ($forcedGroupGuids !== []) {
    $forcedGroupMap = array_flip(array_map('strtolower', $forcedGroupGuids));
    $groupOptions = array_values(array_filter($groupOptions, static function (array $group) use ($forcedGroupMap): bool {
        $guid = strtolower((string) ($group['guid'] ?? $group['Guid'] ?? ''));

        return $guid !== '' && isset($forcedGroupMap[$guid]);
    }));
} else {
    $groupOptions = [];
}

foreach ($selectedStoreGuids as $guid) {
    $exists = false;
    foreach ($storeOptions as $store) {
        if ((string) ($store['guid'] ?? $store['Guid'] ?? '') === $guid) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $storeOptions[] = ['guid' => $guid, 'name' => $guid];
    }
}
foreach ($selectedGroupGuids as $guid) {
    $exists = false;
    foreach ($groupOptions as $group) {
        if ((string) ($group['guid'] ?? $group['Guid'] ?? '') === $guid) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $groupOptions[] = ['guid' => $guid, 'name' => $guid];
    }
}

$totalPages = max(1, (int) ceil($totalCount / max(1, $pageSize)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $pageSize + 1);
$rangeEnd = min($totalCount, $page * $pageSize);

$lockedClientFilters = [];
if ($forcedMaterialTypes !== []) {
    $lockedClientFilters[] = 'materialTypes';
}
if ($forcedAgeCategories !== []) {
    $lockedClientFilters[] = 'ageCategories';
}
if ($forcedManufacturers !== []) {
    $lockedClientFilters[] = 'manufacturers';
}
if ($forcedSizeRanges !== []) {
    $lockedClientFilters[] = 'sizeRanges';
}
if ($forcedCountryOrigins !== []) {
    $lockedClientFilters[] = 'countryOfOrigins';
}
if ($forcedStoreGuids !== []) {
    $lockedClientFilters[] = 'stores';
}
if ($forcedGroupGuids !== []) {
    $lockedClientFilters[] = 'groups';
}
if ($forcedIsAvailable !== null) {
    $lockedClientFilters[] = 'availability';
}
if ($forcedMinWarehouseQuantity !== null || $forcedMaxWarehouseQuantity !== null) {
    $lockedClientFilters[] = 'warehouseRange';
}
if ($forcedMinUnitSalePriceSyp !== null || $forcedMaxUnitSalePriceSyp !== null) {
    $lockedClientFilters[] = 'priceSaleSyp';
}
if ($forcedMinUnitSalePriceUsd !== null || $forcedMaxUnitSalePriceUsd !== null) {
    $lockedClientFilters[] = 'priceSaleUsd';
}
if ($forcedMinUnitPurchasePriceUsd !== null || $forcedMaxUnitPurchasePriceUsd !== null) {
    $lockedClientFilters[] = 'pricePurchaseUsd';
}

$filterOptions['stores'] = $storeOptions;
$filterOptions['groups'] = $groupOptions;

$catalog = [
    'products' => $products,
    'totalCount' => $totalCount,
    'page' => $page,
    'pageSize' => $pageSize,
    'totalPages' => $totalPages,
    'rangeStart' => $rangeStart,
    'rangeEnd' => $rangeEnd,
    'resultFilters' => $resultFilters,
    'filterOptions' => $filterOptions,
    'apiError' => $apiError,
    'allow_client_filters' => $allowClientFilters && $hasAccess && !$hasConstraintConflict,
    'filters_deferred' => false,
    'locked_client_filters' => array_values(array_unique($lockedClientFilters)),
    'store_options' => [
        'allow_sorting' => $allowSorting,
        'client_sort_fields' => $clientSortFields,
        'visible_client_filters' => $visibleClientFilters,
    ],
    'filters' => [
        'q' => $userKeyword,
        'sort' => $selectedSort,
        'materialTypes' => $selectedMaterialTypes,
        'ageCategories' => $selectedAgeCategories,
        'manufacturers' => $selectedManufacturers,
        'sizeRanges' => $selectedSizeRanges,
        'countryOfOrigins' => $selectedCountryOrigins,
        'storeGuids' => $selectedStoreGuids,
        'groupGuids' => $selectedGroupGuids,
        'isAvailable' => $selectedIsAvailable,
        'minWarehouseQuantity' => $selectedMinWarehouseQuantity,
        'maxWarehouseQuantity' => $selectedMaxWarehouseQuantity,
        'minUnitSalePriceSyp' => $selectedMinUnitSalePriceSyp,
        'maxUnitSalePriceSyp' => $selectedMaxUnitSalePriceSyp,
        'minUnitSalePriceUsd' => $selectedMinUnitSalePriceUsd,
        'maxUnitSalePriceUsd' => $selectedMaxUnitSalePriceUsd,
        'minUnitPurchasePriceUsd' => $selectedMinUnitPurchasePriceUsd,
        'maxUnitPurchasePriceUsd' => $selectedMaxUnitPurchasePriceUsd,
        'groupBy' => $selectedGroupBy,
    ],
];

$displayOptions = [
    'show_images' => $showImages,
    'show_price' => $showPriceSyp || $showPriceUsd,
    'show_quantity' => $showQuantity,
    'allow_cart' => $allowCart && $hasAccess,
    'allow_order' => $allowOrder && $hasAccess,
    'price_mode' => $priceMode,
];

$shareContext = [
    'token' => $token,
    'name_ar' => (string) (is_array($shareLink) ? ($shareLink['name_ar'] ?? 'رابط مشاركة') : 'رابط مشاركة'),
    'access_policy_name_ar' => (string) (is_array($shareLink) ? ($shareLink['access_policy_name_ar'] ?? '') : ''),
    'share_link_id' => (string) (is_array($shareLink) ? ($shareLink['id'] ?? '') : ''),
    'has_access' => $hasAccess,
    'requires_password' => $requiresPassword,
    'error' => $error,
    'constraint_conflict' => $hasConstraintConflict,
];

$shareToken = ($hasAccess && $token !== '') ? $token : null;
$shareLinkId = (string) (is_array($shareLink) ? ($shareLink['id'] ?? '') : '');
$isCustomer = false;

if (is_string($cartNotice) && $cartNotice !== '') {
    $cartNotice = ['ok' => true, 'message' => $cartNotice];
}

$extraHead = '<link href="' . h(portal_asset_url('/css/store-filters.css')) . '" rel="stylesheet">';
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = true;
$metaDescription = 'قائمة مواد مخصصة عبر رابط مشاركة — ' . (string) ($shareContext['name_ar'] ?? '');

ob_start();
require dirname(__DIR__) . '/views/share-page.php';
$content = ob_get_clean();
$title = (string) ($shareContext['name_ar'] ?? 'رابط مشاركة');
require dirname(__DIR__) . '/views/layout.php';
