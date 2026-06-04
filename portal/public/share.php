<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ApiClient;
use Portal\Services\ShareLinkService;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$shareLink = $token !== '' ? ShareLinkService::getByPublicToken($token) : null;
$error = null;
$apiError = null;

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
    if (ShareLinkService::verifyProtectedAccess($token, $userName, $password)) {
        $_SESSION['share_link_access'][$token] = true;
        $hasAccess = true;
    } else {
        $error = 'بيانات الدخول غير صحيحة.';
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
$allowClientFilters = (bool) (($shareOptions['allow_client_filters'] ?? true) ? true : false);
$allowSorting = (bool) (($shareOptions['allow_sorting'] ?? true) ? true : false);
$includeResultFilters = (bool) (($shareOptions['include_result_filters'] ?? true) ? true : false);
$defaultSort = trim((string) ($shareOptions['default_sort'] ?? 'number:asc'));
$defaultSort = $defaultSort !== '' ? $defaultSort : 'number:asc';
$defaultGroupBy = trim((string) ($shareOptions['default_group_by'] ?? 'none'));
$defaultGroupBy = in_array($defaultGroupBy, ['none', 'ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
    ? $defaultGroupBy
    : 'none';
$visibleClientFilters = array_values(array_map(
    'strval',
    is_array($shareOptions['visible_client_filters'] ?? null)
        ? $shareOptions['visible_client_filters']
        : []
));
if ($visibleClientFilters === []) {
    $visibleClientFilters = ['search', 'materialTypes', 'ageCategories', 'manufacturers', 'sizeRanges', 'countryOfOrigins', 'sort'];
}
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

$baseMinQuantity = (float) (is_array($shareLink) ? ($shareLink['min_quantity'] ?? 0) : 0);
$effectiveMinQuantity = $baseMinQuantity > 0 ? $baseMinQuantity : null;

$queryIsAvailable = $mergeBool(is_bool($forcedIsAvailable) ? $forcedIsAvailable : null, $selectedIsAvailable, $hasConstraintConflict);
$queryMinWarehouseQuantity = $mergeMin($forcedMinWarehouseQuantity, $selectedMinWarehouseQuantity);
$queryMaxWarehouseQuantity = $mergeMax($forcedMaxWarehouseQuantity, $selectedMaxWarehouseQuantity);
$queryMinUnitSalePriceSyp = $mergeMin($forcedMinUnitSalePriceSyp, $selectedMinUnitSalePriceSyp);
$queryMaxUnitSalePriceSyp = $mergeMax($forcedMaxUnitSalePriceSyp, $selectedMaxUnitSalePriceSyp);
$queryMinUnitSalePriceUsd = $mergeMin($forcedMinUnitSalePriceUsd, $selectedMinUnitSalePriceUsd);
$queryMaxUnitSalePriceUsd = $mergeMax($forcedMaxUnitSalePriceUsd, $selectedMaxUnitSalePriceUsd);
$queryMinUnitPurchasePriceUsd = $mergeMin($forcedMinUnitPurchasePriceUsd, $selectedMinUnitPurchasePriceUsd);
$queryMaxUnitPurchasePriceUsd = $mergeMax($forcedMaxUnitPurchasePriceUsd, $selectedMaxUnitPurchasePriceUsd);

if ($effectiveMinQuantity !== null) {
    $queryMinWarehouseQuantity = $queryMinWarehouseQuantity !== null
        ? max($queryMinWarehouseQuantity, $effectiveMinQuantity)
        : $effectiveMinQuantity;
}

$validateRange($queryMinWarehouseQuantity, $queryMaxWarehouseQuantity, $hasConstraintConflict);
$validateRange($queryMinUnitSalePriceSyp, $queryMaxUnitSalePriceSyp, $hasConstraintConflict);
$validateRange($queryMinUnitSalePriceUsd, $queryMaxUnitSalePriceUsd, $hasConstraintConflict);
$validateRange($queryMinUnitPurchasePriceUsd, $queryMaxUnitPurchasePriceUsd, $hasConstraintConflict);

$baseKeyword = trim((string) (is_array($shareLink) ? ($shareLink['keyword'] ?? '') : ''));
$userKeyword = ($allowClientFilters && $isClientFilterVisible('search')) ? trim((string) ($_GET['q'] ?? '')) : '';
$search = trim($baseKeyword . ' ' . $userKeyword);
$search = $search !== '' ? $search : null;

$selectedSort = ($allowSorting && $isClientFilterVisible('sort'))
    ? trim((string) ($_GET['sort'] ?? $defaultSort))
    : $defaultSort;
$selectedSort = $selectedSort !== '' ? $selectedSort : 'number:asc';
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
            'search' => $search,
            'storeGuids' => $queryStoreGuids !== [] ? implode(',', $queryStoreGuids) : null,
            'materialTypes' => $queryMaterialTypes !== [] ? implode(',', $queryMaterialTypes) : null,
            'ageCategories' => $queryAgeCategories !== [] ? implode(',', $queryAgeCategories) : null,
            'manufacturers' => $queryManufacturers !== [] ? implode(',', $queryManufacturers) : null,
            'sizeRanges' => $querySizeRanges !== [] ? implode(',', $querySizeRanges) : null,
            'countryOfOrigins' => $queryCountryOrigins !== [] ? implode(',', $queryCountryOrigins) : null,
            'groupGuids' => $queryGroupGuids !== [] ? implode(',', $queryGroupGuids) : null,
            'isAvailable' => $queryIsAvailable === null ? null : ($queryIsAvailable ? 'true' : 'false'),
            'minWarehouseQuantity' => $queryMinWarehouseQuantity !== null ? $queryMinWarehouseQuantity : $effectiveMinQuantity,
            'maxWarehouseQuantity' => $queryMaxWarehouseQuantity,
            'minUnitSalePriceSyp' => $queryMinUnitSalePriceSyp,
            'maxUnitSalePriceSyp' => $queryMaxUnitSalePriceSyp,
            'minUnitSalePriceUsd' => $queryMinUnitSalePriceUsd,
            'maxUnitSalePriceUsd' => $queryMaxUnitSalePriceUsd,
            'minUnitPurchasePriceUsd' => $queryMinUnitPurchasePriceUsd,
            'maxUnitPurchasePriceUsd' => $queryMaxUnitPurchasePriceUsd,
            'groupBy' => $selectedGroupBy !== 'none' ? $selectedGroupBy : null,
            'sort' => $selectedSort,
            'includeResultFilters' => ($allowClientFilters && $includeResultFilters) ? 'true' : 'false',
        ], static fn ($value) => $value !== null && $value !== '');

        $materials = ApiClient::get('/api/materials', $params);
        if (!$materials['ok'] && (int) ($materials['status'] ?? 0) === 400) {
            // Fallback to a safer query if strict filters are rejected.
            $fallbackParams = array_filter([
                'page' => $page,
                'pageSize' => 24,
                'search' => $search,
                'materialTypes' => $queryMaterialTypes !== [] ? implode(',', $queryMaterialTypes) : null,
                'ageCategories' => $queryAgeCategories !== [] ? implode(',', $queryAgeCategories) : null,
                'manufacturers' => $queryManufacturers !== [] ? implode(',', $queryManufacturers) : null,
                'sizeRanges' => $querySizeRanges !== [] ? implode(',', $querySizeRanges) : null,
                'countryOfOrigins' => $queryCountryOrigins !== [] ? implode(',', $queryCountryOrigins) : null,
                'sort' => 'number:asc',
                'includeResultFilters' => ($allowClientFilters && $includeResultFilters) ? 'true' : 'false',
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
            if ($allowClientFilters && $resultFilters === []) {
                $toFacetValues = static function (array $values): array {
                    $items = [];
                    foreach ($values as $value) {
                        $item = trim((string) $value);
                        if ($item === '') {
                            continue;
                        }
                        $items[] = ['value' => $item, 'count' => null];
                    }
                    return $items;
                };

                $resultFilters = [
                    'materialTypes' => $toFacetValues($filterOptions['materialTypes']),
                    'ageCategories' => $toFacetValues($filterOptions['ageCategories']),
                    'manufacturers' => $toFacetValues($filterOptions['manufacturers']),
                    'sizeRanges' => $toFacetValues($filterOptions['sizeRanges']),
                    'countryOfOrigins' => $toFacetValues($filterOptions['countryOfOrigins']),
                ];
            }
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

$buildShareUrl = static function (int $targetPage) use ($token): string {
    $params = $_GET;
    $params['token'] = $token;
    $params['page'] = max(1, $targetPage);

    return '/share.php?' . http_build_query($params);
};

$renderFilterChips = static function (string $paramName, array $options, array $selectedValues): void {
    if ($options === []) {
        return;
    }

    echo '<div class="flex flex-wrap gap-2 mt-2">';
    foreach ($options as $option) {
        $value = (string) ($option['value'] ?? '');
        if ($value === '') {
            continue;
        }
        $label = (string) ($option['label'] ?? $value);
        $count = $option['count'] ?? null;
        $isChecked = in_array($value, $selectedValues, true);
        $countSuffix = $count !== null ? ' (' . (int) $count . ')' : '';
        echo '<label class="cursor-pointer">';
        echo '<input type="checkbox" class="peer sr-only" name="' . h($paramName) . '[]" value="' . h($value) . '"' . ($isChecked ? ' checked' : '') . '>';
        echo '<span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold border transition ';
        echo 'border-gray-300 bg-white text-gray-700 hover:border-primary ';
        echo 'peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary">';
        echo h($label . $countSuffix);
        echo '</span></label>';
    }
    echo '</div>';
};

ob_start();
?>
<div class="bg-white rounded-xl p-6 shadow-sm border">
  <h1 class="text-2xl font-extrabold mb-2"><?= h((string) (is_array($shareLink) ? ($shareLink['name_ar'] ?? 'رابط مشاركة') : 'رابط مشاركة')) ?></h1>
  <p class="text-sm text-gray-600 mb-4">سياسة الوصول: <?= h((string) (is_array($shareLink) ? ($shareLink['access_policy_name_ar'] ?? '—') : '—')) ?></p>

  <?php if ($error): ?>
    <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if ($apiError): ?>
    <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($apiError) ?></p>
  <?php endif; ?>
  <?php if ($hasConstraintConflict): ?>
    <p class="mb-4 rounded border bg-amber-50 border-amber-200 text-amber-700 px-3 py-2 text-sm">الفلاتر المختارة لا تتطابق مع قيود هذا الرابط.</p>
  <?php endif; ?>

  <?php if ($shareLink !== null && !$hasAccess): ?>
    <form method="post" class="max-w-md rounded-xl border border-gray-200 p-4 space-y-3">
      <input type="hidden" name="action" value="unlock">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label class="block text-sm">
        <span class="text-gray-600 block mb-1">اسم المستخدم</span>
        <input name="access_username" class="h-11 w-full rounded border border-gray-300 px-3">
      </label>
      <label class="block text-sm">
        <span class="text-gray-600 block mb-1">كلمة المرور</span>
        <input type="password" name="access_password" class="h-11 w-full rounded border border-gray-300 px-3">
      </label>
      <button class="h-11 rounded bg-primary text-white px-5 font-bold">دخول للرابط</button>
    </form>
  <?php endif; ?>

  <?php if ($shareLink !== null && $hasAccess): ?>
    <?php if ($allowClientFilters): ?>
      <form method="get" class="mb-5 grid grid-cols-1 md:grid-cols-4 gap-3">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="page" value="1">
        <?php if ($isClientFilterVisible('search')): ?>
          <label class="text-sm md:col-span-2">
            <span class="text-gray-600 block mb-1">بحث</span>
            <input name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3" placeholder="اسم المادة أو الكود">
          </label>
        <?php endif; ?>
        <?php if ($isClientFilterVisible('warehouseRange')): ?>
          <label class="text-sm">
            <span class="text-gray-600 block mb-1">أقل كمية</span>
            <input type="number" name="minWarehouseQuantity" min="0" step="0.01" value="<?= h((string) ($_GET['minWarehouseQuantity'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
          </label>
          <label class="text-sm">
            <span class="text-gray-600 block mb-1">أعلى كمية</span>
            <input type="number" name="maxWarehouseQuantity" min="0" step="0.01" value="<?= h((string) ($_GET['maxWarehouseQuantity'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
          </label>
        <?php endif; ?>
        <?php if ($isClientFilterVisible('availability')): ?>
          <?php $availabilityValue = (string) ($_GET['isAvailable'] ?? ''); ?>
          <div class="text-sm md:col-span-2">
            <span class="text-gray-600 block mb-1">التوفر</span>
            <div class="flex flex-wrap gap-2 mt-1">
              <?php foreach (['' => 'الكل', '1' => 'متوفر', '0' => 'غير متوفر'] as $value => $label): ?>
                <?php $isActive = $availabilityValue === (string) $value; ?>
                <label class="cursor-pointer">
                  <input type="radio" class="peer sr-only" name="isAvailable" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
                  <span class="inline-flex px-3 py-1.5 rounded-full text-sm font-bold border transition border-gray-300 bg-white text-gray-700 hover:border-primary peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary"><?= h($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($allowSorting && $isClientFilterVisible('sort')): ?>
          <label class="text-sm">
            <span class="text-gray-600 block mb-1">الترتيب</span>
            <input name="sort" list="share-sort-presets" value="<?= h($selectedSort) ?>" class="h-11 w-full rounded border border-gray-300 px-3" placeholder="number:asc,materialType:asc">
            <datalist id="share-sort-presets">
              <option value="number:asc"></option>
              <option value="number:desc"></option>
              <option value="materialType:asc,manufacturer:asc"></option>
              <option value="ageCategory:asc,materialType:asc"></option>
              <option value="manufacturer:asc,-unitSalePriceSyp"></option>
            </datalist>
          </label>
        <?php endif; ?>
        <?php if ($isClientFilterVisible('groupBy')): ?>
          <label class="text-sm">
            <span class="text-gray-600 block mb-1">التجميع</span>
            <select name="groupBy" class="h-11 w-full rounded border border-gray-300 px-3">
              <option value="none" <?= $selectedGroupBy === 'none' ? 'selected' : '' ?>>بدون</option>
              <option value="ageCategory" <?= $selectedGroupBy === 'ageCategory' ? 'selected' : '' ?>>الفئة العمرية</option>
              <option value="sizeRange" <?= $selectedGroupBy === 'sizeRange' ? 'selected' : '' ?>>القياس</option>
              <option value="materialType" <?= $selectedGroupBy === 'materialType' ? 'selected' : '' ?>>النوع</option>
              <option value="manufacturer" <?= $selectedGroupBy === 'manufacturer' ? 'selected' : '' ?>>الشركة</option>
              <option value="countryOfOrigin" <?= $selectedGroupBy === 'countryOfOrigin' ? 'selected' : '' ?>>بلد المنشأ</option>
              <option value="group" <?= $selectedGroupBy === 'group' ? 'selected' : '' ?>>المجموعة</option>
            </select>
          </label>
        <?php endif; ?>

        <?php if ($isClientFilterVisible('stores') && $storeOptions !== []): ?>
          <?php
            $storeChipOptions = [];
            foreach ($storeOptions as $store) {
                $storeGuid = (string) ($store['guid'] ?? $store['Guid'] ?? '');
                if ($storeGuid === '') {
                    continue;
                }
                $storeLabel = trim((string) ($store['name'] ?? $store['Name'] ?? '')) !== ''
                    ? (string) ($store['name'] ?? $store['Name'])
                    : ((string) ($store['code'] ?? $store['Code'] ?? '') !== '' ? (string) ($store['code'] ?? $store['Code']) : $storeGuid);
                $storeChipOptions[] = ['value' => $storeGuid, 'label' => $storeLabel];
            }
          ?>
          <fieldset class="md:col-span-4 rounded border border-gray-200 p-3">
            <legend class="text-sm font-bold text-gray-700 px-1">المخازن</legend>
            <?php $renderFilterChips('storeGuids', $storeChipOptions, $selectedStoreGuids); ?>
          </fieldset>
        <?php endif; ?>

        <?php if ($isClientFilterVisible('groups') && $groupOptions !== []): ?>
          <?php
            $groupChipOptions = [];
            foreach ($groupOptions as $group) {
                $groupGuid = (string) ($group['guid'] ?? $group['Guid'] ?? '');
                if ($groupGuid === '') {
                    continue;
                }
                $groupLabel = trim((string) ($group['name'] ?? $group['Name'] ?? '')) !== ''
                    ? (string) ($group['name'] ?? $group['Name'])
                    : ((string) ($group['code'] ?? $group['Code'] ?? '') !== '' ? (string) ($group['code'] ?? $group['Code']) : $groupGuid);
                $groupChipOptions[] = ['value' => $groupGuid, 'label' => $groupLabel];
            }
          ?>
          <fieldset class="md:col-span-4 rounded border border-gray-200 p-3">
            <legend class="text-sm font-bold text-gray-700 px-1">المجموعات</legend>
            <?php $renderFilterChips('groupGuids', $groupChipOptions, $selectedGroupGuids); ?>
          </fieldset>
        <?php endif; ?>

        <?php if ($isClientFilterVisible('priceSaleSyp') || $isClientFilterVisible('priceSaleUsd') || $isClientFilterVisible('pricePurchaseUsd')): ?>
          <fieldset class="md:col-span-4 rounded border border-gray-200 p-3">
            <legend class="text-sm text-gray-600 px-1">المدى السعري (اختياري)</legend>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-2">
              <?php if ($isClientFilterVisible('priceSaleSyp')): ?>
                <label class="text-sm">
                  <span class="text-gray-600 block mb-1">سعر البيع ل.س (من)</span>
                  <input type="number" name="minUnitSalePriceSyp" min="0" step="0.01" value="<?= h((string) ($_GET['minUnitSalePriceSyp'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
                </label>
                <label class="text-sm">
                  <span class="text-gray-600 block mb-1">سعر البيع ل.س (إلى)</span>
                  <input type="number" name="maxUnitSalePriceSyp" min="0" step="0.01" value="<?= h((string) ($_GET['maxUnitSalePriceSyp'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
                </label>
              <?php endif; ?>
              <?php if ($isClientFilterVisible('priceSaleUsd')): ?>
                <label class="text-sm">
                  <span class="text-gray-600 block mb-1">سعر البيع $ (من)</span>
                  <input type="number" name="minUnitSalePriceUsd" min="0" step="0.01" value="<?= h((string) ($_GET['minUnitSalePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
                </label>
                <label class="text-sm">
                  <span class="text-gray-600 block mb-1">سعر البيع $ (إلى)</span>
                  <input type="number" name="maxUnitSalePriceUsd" min="0" step="0.01" value="<?= h((string) ($_GET['maxUnitSalePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
                </label>
              <?php endif; ?>
              <?php if ($isClientFilterVisible('pricePurchaseUsd')): ?>
                <label class="text-sm">
                  <span class="text-gray-600 block mb-1">سعر الشراء $ (من)</span>
                  <input type="number" name="minUnitPurchasePriceUsd" min="0" step="0.01" value="<?= h((string) ($_GET['minUnitPurchasePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
                </label>
                <label class="text-sm">
                  <span class="text-gray-600 block mb-1">سعر الشراء $ (إلى)</span>
                  <input type="number" name="maxUnitPurchasePriceUsd" min="0" step="0.01" value="<?= h((string) ($_GET['maxUnitPurchasePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded border border-gray-300 px-3">
                </label>
              <?php endif; ?>
            </div>
          </fieldset>
        <?php endif; ?>

        <?php
          $facetMap = [
              'materialTypes' => ['label' => 'نوع المادة', 'visible' => $isClientFilterVisible('materialTypes')],
              'ageCategories' => ['label' => 'الفئة العمرية', 'visible' => $isClientFilterVisible('ageCategories')],
              'manufacturers' => ['label' => 'الشركة', 'visible' => $isClientFilterVisible('manufacturers')],
              'sizeRanges' => ['label' => 'القياس', 'visible' => $isClientFilterVisible('sizeRanges')],
              'countryOfOrigins' => ['label' => 'بلد المنشأ', 'visible' => $isClientFilterVisible('countryOfOrigins')],
          ];
        ?>
        <?php foreach ($facetMap as $facetKey => $facetConfig): ?>
          <?php if (empty($facetConfig['visible'])) {
              continue;
          } ?>
          <?php $values = $resultFilters[$facetKey] ?? []; ?>
          <?php if ($values !== []): ?>
            <?php
              $facetChipOptions = [];
              $selectedFacetValues = $parseList($facetKey);
              foreach ($values as $facet) {
                  $facetValue = (string) ($facet['value'] ?? '');
                  if ($facetValue === '') {
                      continue;
                  }
                  $facetChipOptions[] = [
                      'value' => $facetValue,
                      'label' => $facetValue,
                      'count' => $facet['count'] ?? null,
                  ];
              }
            ?>
            <fieldset class="md:col-span-4 rounded border border-gray-200 p-3">
              <legend class="text-sm font-bold text-gray-700 px-1"><?= h((string) $facetConfig['label']) ?></legend>
              <?php $renderFilterChips($facetKey, $facetChipOptions, $selectedFacetValues); ?>
            </fieldset>
          <?php endif; ?>
        <?php endforeach; ?>

        <div class="md:col-span-4 flex gap-2 justify-end">
          <button class="h-11 rounded bg-primary text-white px-6 font-bold">تطبيق الفلاتر</button>
          <a href="/share.php?token=<?= urlencode($token) ?>" class="h-11 inline-flex items-center rounded border border-gray-300 px-6 text-sm">إعادة ضبط</a>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($totalCount > 0): ?>
      <p class="text-sm text-gray-600 mb-3">
        عرض <?= (int) $rangeStart ?>–<?= (int) $rangeEnd ?> من <?= (int) $totalCount ?> مادة
        <?php if ($totalPages > 1): ?>
          <span class="text-gray-400">(صفحة <?= (int) $page ?> من <?= (int) $totalPages ?>)</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($products as $item): ?>
        <article class="border rounded-lg p-3 bg-white">
          <?php if ($showImages): ?>
            <div class="h-24 rounded bg-gray-100 flex items-center justify-center text-gray-500 text-xs mb-3">
              <?php if (!empty($item['productImageGuid'])): ?>
                <img
                  src="/api/image.php?id=<?= urlencode((string) $item['productImageGuid']) ?>&thumb=1"
                  alt="<?= h((string) ($item['name'] ?? 'صورة مادة')) ?>"
                  class="h-24 w-full object-cover rounded"
                  loading="lazy"
                >
              <?php else: ?>
                بدون صورة
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="font-semibold"><?= h((string) ($item['name'] ?? '-')) ?></div>
          <div class="text-xs text-gray-500"><?= h((string) ($item['materialCode'] ?? '')) ?></div>
          <div class="text-xs text-gray-500 mt-1">
            <?= h((string) ($item['manufacturer'] ?? '')) ?><?= !empty($item['materialType']) ? ' • ' . h((string) $item['materialType']) : '' ?>
          </div>

          <?php if ($showPriceSyp): ?>
            <div class="text-primary font-bold mt-2">
              <?= format_money((float) ($item['unitSalePriceSyp'] ?? 0), true) ?> ل.س
            </div>
          <?php endif; ?>
          <?php if ($showPriceUsd): ?>
            <div class="text-emerald-700 font-bold mt-1">
              $<?= number_format((float) ($item['unitSalePriceUsd'] ?? 0), 2, '.', ',') ?>
            </div>
          <?php endif; ?>
          <?php if ($showQuantity): ?>
            <div class="text-xs text-gray-500 mt-1">
              الكمية: <?= number_format((float) ($item['warehouseQuantity'] ?? 0), 2, '.', ',') ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($products === [] && !$apiError && !$hasConstraintConflict): ?>
      <p class="text-gray-500 mt-4">لا توجد نتائج مطابقة.</p>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
      <nav class="mt-8 flex flex-wrap items-center justify-center gap-2" aria-label="ترقيم الصفحات">
        <?php if ($page > 1): ?>
          <a href="<?= h($buildShareUrl($page - 1)) ?>" class="h-10 inline-flex items-center px-4 rounded-full border border-gray-300 text-sm font-bold hover:border-primary">السابق</a>
        <?php endif; ?>
        <?php
          $windowStart = max(1, $page - 2);
          $windowEnd = min($totalPages, $page + 2);
          if ($windowEnd - $windowStart < 4) {
              $windowStart = max(1, $windowEnd - 4);
              $windowEnd = min($totalPages, $windowStart + 4);
          }
          for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++):
            $isCurrent = $pageNumber === $page;
        ?>
          <a
            href="<?= h($buildShareUrl($pageNumber)) ?>"
            class="h-10 min-w-10 inline-flex items-center justify-center px-3 rounded-full text-sm font-bold border <?= $isCurrent ? 'bg-primary text-white border-primary' : 'border-gray-300 hover:border-primary' ?>"
            <?= $isCurrent ? 'aria-current="page"' : '' ?>
          ><?= (int) $pageNumber ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?= h($buildShareUrl($page + 1)) ?>" class="h-10 inline-flex items-center px-4 rounded-full border border-gray-300 text-sm font-bold hover:border-primary">التالي</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'رابط مشاركة';
require dirname(__DIR__) . '/views/layout.php';
