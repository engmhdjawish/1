<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ApiClient;
use Portal\Services\AccessPolicyService;
use Portal\Services\ShareCartService;
use Portal\Services\ShareLinkService;
use Portal\Services\StoreCatalogService;
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
$policyId = is_array($shareLink) ? trim((string) ($shareLink['access_policy_id'] ?? '')) : '';
$policyRules = $policyId !== '' ? AccessPolicyService::filterRulesForPolicyId($policyId) : AccessPolicyService::defaultFilterRules();
$policyStoreOptions = $policyId !== '' ? AccessPolicyService::storeOptionsForPolicyId($policyId) : AccessPolicyService::defaultStoreOptions();
$effectiveStoreOptions = ShareLinkService::resolveShareStoreOptions($policyStoreOptions, $shareOptions);
$visibleClientFilters = AccessPolicyService::resolvedVisibleClientFilters($effectiveStoreOptions);
$allowClientFilters = (bool) ($shareOptions['allow_client_filters'] ?? true) && $visibleClientFilters !== [];
$allowSorting = (bool) ($effectiveStoreOptions['allow_sorting'] ?? false);
$useDynamicResultFilters = $allowClientFilters && (bool) (($shareOptions['include_result_filters'] ?? true) ? true : false);
$defaultSort = trim((string) ($effectiveStoreOptions['default_sort'] ?? 'number:asc'));
$clientSortFields = array_values(array_map('strval', is_array($effectiveStoreOptions['client_sort_fields'] ?? null) ? $effectiveStoreOptions['client_sort_fields'] : []));
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
$defaultSort = $defaultSort !== '' ? $defaultSort : 'number:asc';
$defaultGroupBy = (string) ($effectiveStoreOptions['default_group_by'] ?? 'none');
$isClientFilterVisible = static function (string $code) use ($visibleClientFilters): bool {
    return in_array($code, $visibleClientFilters, true);
};

$linkRules = ShareLinkService::filterRulesFromLink(is_array($shareLink) ? $shareLink : []);
$baseRules = StoreCatalogService::mergeShareFilterRules($policyRules, $linkRules);
$rulesList = static function (array $rules, string $key): array {
    $values = is_array($rules[$key] ?? null) ? $rules[$key] : [];

    return array_values(array_map('strval', $values));
};
$scopeMaterialTypes = $rulesList($baseRules, 'material_types');
$scopeAgeCategories = $rulesList($baseRules, 'age_categories');
$scopeManufacturers = $rulesList($baseRules, 'manufacturers');
$scopeSizeRanges = $rulesList($baseRules, 'size_ranges');
$scopeCountryOrigins = $rulesList($baseRules, 'country_origins');
$scopeStoreGuids = $rulesList($baseRules, 'store_guids');
$scopeGroupGuids = $rulesList($baseRules, 'group_guids');

$scopeStringOptionList = static function (array $options, array $forced): array {
    if ($forced === []) {
        return $options;
    }
    $allowed = [];
    foreach ($forced as $value) {
        $allowed[Text::lower((string) $value)] = true;
    }

    return array_values(array_filter($options, static function (string $option) use ($allowed): bool {
        return isset($allowed[Text::lower($option)]);
    }));
};
$applyScopedShareFilterOptions = static function (array $options) use (
    $scopeStringOptionList,
    $scopeMaterialTypes,
    $scopeAgeCategories,
    $scopeManufacturers,
    $scopeSizeRanges,
    $scopeCountryOrigins
): array {
    $options['materialTypes'] = $scopeStringOptionList($options['materialTypes'] ?? [], $scopeMaterialTypes);
    $options['ageCategories'] = $scopeStringOptionList($options['ageCategories'] ?? [], $scopeAgeCategories);
    $options['manufacturers'] = $scopeStringOptionList($options['manufacturers'] ?? [], $scopeManufacturers);
    $options['sizeRanges'] = $scopeStringOptionList($options['sizeRanges'] ?? [], $scopeSizeRanges);
    $options['countryOfOrigins'] = $scopeStringOptionList($options['countryOfOrigins'] ?? [], $scopeCountryOrigins);

    return $options;
};

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
$userKeyword = ($allowClientFilters && $isClientFilterVisible('search')) ? trim((string) ($_GET['q'] ?? '')) : '';

$mergedFilters = StoreCatalogService::mergeShareCatalogFilters($baseRules, [
    'search' => $userKeyword,
    'materialTypes' => $selectedMaterialTypes,
    'manufacturers' => $selectedManufacturers,
    'ageCategories' => $selectedAgeCategories,
    'sizeRanges' => $selectedSizeRanges,
    'countryOfOrigins' => $selectedCountryOrigins,
    'groupGuids' => $selectedGroupGuids,
    'storeGuids' => $selectedStoreGuids,
    'isAvailable' => $selectedIsAvailable,
    'hasImage' => null,
    'minWarehouseQuantity' => $selectedMinWarehouseQuantity,
    'maxWarehouseQuantity' => $selectedMaxWarehouseQuantity,
    'minUnitSalePriceSyp' => $selectedMinUnitSalePriceSyp,
    'maxUnitSalePriceSyp' => $selectedMaxUnitSalePriceSyp,
    'minUnitSalePriceUsd' => $selectedMinUnitSalePriceUsd,
    'maxUnitSalePriceUsd' => $selectedMaxUnitSalePriceUsd,
    'minUnitPurchasePriceUsd' => $selectedMinUnitPurchasePriceUsd,
    'maxUnitPurchasePriceUsd' => $selectedMaxUnitPurchasePriceUsd,
]);
$hasConstraintConflict = (bool) ($mergedFilters['has_conflict'] ?? false);
$queryMaterialTypes = $mergedFilters['materialTypes'] ?? [];
$queryAgeCategories = $mergedFilters['ageCategories'] ?? [];
$queryManufacturers = $mergedFilters['manufacturers'] ?? [];
$querySizeRanges = $mergedFilters['sizeRanges'] ?? [];
$queryCountryOrigins = $mergedFilters['countryOfOrigins'] ?? [];
$queryStoreGuids = $mergedFilters['storeGuids'] ?? [];
$queryGroupGuids = $mergedFilters['groupGuids'] ?? [];
$queryIsAvailable = $mergedFilters['isAvailable'] ?? null;
$queryHasImage = $mergedFilters['hasImage'] ?? null;
$queryMinWarehouseQuantity = $mergedFilters['minWarehouseQuantity'] ?? null;
$queryMaxWarehouseQuantity = $mergedFilters['maxWarehouseQuantity'] ?? null;
$queryMinUnitSalePriceSyp = $mergedFilters['minUnitSalePriceSyp'] ?? null;
$queryMaxUnitSalePriceSyp = $mergedFilters['maxUnitSalePriceSyp'] ?? null;
$queryMinUnitSalePriceUsd = $mergedFilters['minUnitSalePriceUsd'] ?? null;
$queryMaxUnitSalePriceUsd = $mergedFilters['maxUnitSalePriceUsd'] ?? null;
$queryMinUnitPurchasePriceUsd = $mergedFilters['minUnitPurchasePriceUsd'] ?? null;
$queryMaxUnitPurchasePriceUsd = $mergedFilters['maxUnitPurchasePriceUsd'] ?? null;
$search = trim((string) ($mergedFilters['search'] ?? ''));
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
$requestedSort = $allowSorting && $clientSortFields !== [] ? trim((string) ($_GET['sort'] ?? '')) : '';
$activeSortParsed = $requestedSort !== ''
    ? $parseSortClause(explode(',', $requestedSort)[0] ?? $requestedSort)
    : $defaultSortParsed;
if ($clientSortFields === [] || !in_array($activeSortParsed['field'], $clientSortFields, true)) {
    $activeSortParsed = ['field' => $clientSortFields[0] ?? 'number', 'dir' => 'asc'];
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
            $filterOptions = $applyScopedShareFilterOptions($filterOptions);
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
                $scopeMaterialTypes
            );
            $resultFilters['ageCategories'] = $scopeStringFacets(
                is_array($resultFilters['ageCategories'] ?? null) ? $resultFilters['ageCategories'] : [],
                $scopeAgeCategories
            );
            $resultFilters['manufacturers'] = $scopeStringFacets(
                is_array($resultFilters['manufacturers'] ?? null) ? $resultFilters['manufacturers'] : [],
                $scopeManufacturers
            );
            $resultFilters['sizeRanges'] = $scopeStringFacets(
                is_array($resultFilters['sizeRanges'] ?? null) ? $resultFilters['sizeRanges'] : [],
                $scopeSizeRanges
            );
            $resultFilters['countryOfOrigins'] = $scopeStringFacets(
                is_array($resultFilters['countryOfOrigins'] ?? null) ? $resultFilters['countryOfOrigins'] : [],
                $scopeCountryOrigins
            );
            $resultFilters['groups'] = $scopeGroupFacets(
                is_array($resultFilters['groups'] ?? null) ? $resultFilters['groups'] : [],
                $scopeGroupGuids
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
if ($scopeStoreGuids !== []) {
    $forcedStoreMap = array_flip(array_map('strtolower', $scopeStoreGuids));
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
} elseif ($scopeGroupGuids !== []) {
    $forcedGroupMap = array_flip(array_map('strtolower', $scopeGroupGuids));
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

$lockedClientFilters = array_values(array_unique(array_merge(
    StoreCatalogService::lockedClientFiltersForRules($baseRules),
    ShareLinkService::lockedClientFiltersFromLink(is_array($shareLink) ? $shareLink : [])
)));

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
    'locked_client_filters' => $lockedClientFilters,
    'store_options' => $effectiveStoreOptions,
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
$shareCartUrl = ($shareToken !== null && ($displayOptions['allow_cart'] ?? false))
    ? '/cart.php?token=' . rawurlencode($shareToken)
    : null;
$storeAllowCart = (bool) ($displayOptions['allow_cart'] ?? false);
$storeCartCount = ($shareToken !== null && $storeAllowCart) ? ShareCartService::itemCount($shareToken) : 0;
$storeCartPackageCount = ($shareToken !== null && $storeAllowCart) ? ShareCartService::packageCount($shareToken) : 0.0;
$storeCartBootstrap = ($shareToken !== null && $storeAllowCart)
    ? array_merge(ShareCartService::bootstrapPayload($shareToken), [
        'share_token' => $shareToken,
        'cart_url' => $shareCartUrl,
    ])
    : null;
$enableStoreCartJs = $storeAllowCart && $hasAccess;
$deferStoreCartJs = false;
$metaDescription = 'قائمة مواد مخصصة عبر رابط مشاركة — ' . (string) ($shareContext['name_ar'] ?? '');

ob_start();
require dirname(__DIR__) . '/views/share-page.php';
$content = ob_get_clean();
$title = (string) ($shareContext['name_ar'] ?? 'رابط مشاركة');
require dirname(__DIR__) . '/views/layout.php';
