<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;
use Portal\Services\HomeSectionService;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$parseValues = static function (mixed $value): array {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[,|\n]+/u', (string) $value) ?: [];
    }
    $result = [];
    foreach ($parts as $part) {
        $item = trim((string) $part);
        if ($item !== '') {
            $result[] = $item;
        }
    }
    return array_values(array_unique($result));
};
$parseNullableFloat = static function (mixed $value): ?float {
    if (is_array($value)) {
        return null;
    }
    $text = trim((string) $value);
    return $text !== '' && is_numeric($text) ? (float) $text : null;
};
$parseNullableBool = static function (mixed $value): ?bool {
    if (is_array($value)) {
        return null;
    }
    $text = trim(strtolower((string) $value));
    return match ($text) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => null,
    };
};
$buildFilterPayload = static function () use ($parseValues, $parseNullableFloat, $parseNullableBool): array {
    return [
        'keyword' => trim((string) ($_POST['filter_keyword'] ?? '')),
        'material_types' => $parseValues($_POST['filter_material_types'] ?? []),
        'age_categories' => $parseValues($_POST['filter_age_categories'] ?? []),
        'manufacturers' => $parseValues($_POST['filter_manufacturers'] ?? []),
        'size_ranges' => $parseValues($_POST['filter_size_ranges'] ?? []),
        'country_origins' => $parseValues($_POST['filter_country_origins'] ?? []),
        'store_guids' => $parseValues($_POST['filter_store_guids'] ?? []),
        'group_guids' => $parseValues($_POST['filter_group_guids'] ?? []),
        'is_available' => $parseNullableBool($_POST['filter_is_available'] ?? null),
        'min_warehouse_quantity' => $parseNullableFloat($_POST['filter_min_warehouse_quantity'] ?? null),
        'max_warehouse_quantity' => $parseNullableFloat($_POST['filter_max_warehouse_quantity'] ?? null),
        'min_unit_sale_price_syp' => $parseNullableFloat($_POST['filter_min_unit_sale_price_syp'] ?? null),
        'max_unit_sale_price_syp' => $parseNullableFloat($_POST['filter_max_unit_sale_price_syp'] ?? null),
        'min_unit_sale_price_usd' => $parseNullableFloat($_POST['filter_min_unit_sale_price_usd'] ?? null),
        'max_unit_sale_price_usd' => $parseNullableFloat($_POST['filter_max_unit_sale_price_usd'] ?? null),
        'min_unit_purchase_price_usd' => $parseNullableFloat($_POST['filter_min_unit_purchase_price_usd'] ?? null),
        'max_unit_purchase_price_usd' => $parseNullableFloat($_POST['filter_max_unit_purchase_price_usd'] ?? null),
    ];
};

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$user = WebSession::user();
$materialSearchQ = trim((string) ($_GET['material_q'] ?? ''));
$materialSearchResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_section') {
        $displayMode = trim((string) ($_POST['display_mode'] ?? 'filter'));
        $result = HomeSectionService::saveSection(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['slug'] ?? '')),
            trim((string) ($_POST['title_ar'] ?? '')),
            trim((string) ($_POST['subtitle_ar'] ?? '')),
            trim((string) ($_POST['banner_image_url'] ?? '')),
            $displayMode,
            (int) ($_POST['max_products'] ?? 12),
            (int) ($_POST['sort_order'] ?? 0),
            isset($_POST['is_active']),
            isset($user['id']) ? (string) $user['id'] : null
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $sectionId = (string) ($result['id'] ?? '');
            $editId = $sectionId;
            if ($displayMode === 'manual') {
                HomeSectionService::syncManualProducts($sectionId, $parseValues($_POST['manual_material_guids'] ?? []));
                HomeSectionService::syncFilters($sectionId, []);
            } else {
                HomeSectionService::syncFilters($sectionId, $buildFilterPayload());
                HomeSectionService::syncManualProducts($sectionId, []);
            }
        }
    } elseif ($action === 'toggle_section') {
        $ok = HomeSectionService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1',
            isset($user['id']) ? (string) $user['id'] : null
        );
        $flash = $ok ? 'تم تحديث حالة القسم.' : 'تعذر تحديث حالة القسم.';
        $flashType = $ok ? 'success' : 'error';
    }
}

$stats = HomeSectionService::stats();
$sections = HomeSectionService::adminSections();
$editSection = $editId !== '' ? HomeSectionService::getSectionById($editId) : null;
if ($editSection === null) {
    $editId = '';
}

if ($materialSearchQ !== '') {
    try {
        $searchResponse = ApiClient::get('/api/materials', [
            'page' => 1,
            'pageSize' => 24,
            'search' => $materialSearchQ,
        ]);
        if ($searchResponse['ok']) {
            $materialSearchResults = is_array($searchResponse['data']['items'] ?? null)
                ? $searchResponse['data']['items']
                : [];
        }
    } catch (\Throwable) {
        $materialSearchResults = [];
    }
}

$materialFilterOptions = [
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
$materialFilterOptionsError = null;
try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok']) {
        $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
        $stores = is_array($data['stores'] ?? null) ? $data['stores'] : (is_array($data['Stores'] ?? null) ? $data['Stores'] : []);
        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : (is_array($data['Groups'] ?? null) ? $data['Groups'] : []);
        $priceRanges = is_array($data['priceRanges'] ?? null) ? $data['priceRanges'] : (is_array($data['PriceRanges'] ?? null) ? $data['PriceRanges'] : null);
        $materialFilterOptions = [
            'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
            'ageCategories' => array_values(array_map('strval', is_array($data['ageCategories'] ?? null) ? $data['ageCategories'] : ($data['AgeCategories'] ?? []))),
            'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
            'sizeRanges' => array_values(array_map('strval', is_array($data['sizeRanges'] ?? null) ? $data['sizeRanges'] : ($data['SizeRanges'] ?? []))),
            'countryOfOrigins' => array_values(array_map('strval', is_array($data['countryOfOrigins'] ?? null) ? $data['countryOfOrigins'] : ($data['CountryOfOrigins'] ?? []))),
            'stores' => array_values(array_filter($stores, static fn ($row) => is_array($row))),
            'groups' => array_values(array_filter($groups, static fn ($row) => is_array($row))),
            'priceRanges' => is_array($priceRanges) ? $priceRanges : $materialFilterOptions['priceRanges'],
        ];
    } else {
        $materialFilterOptionsError = 'تعذر جلب فلاتر المواد من API (رمز ' . (int) ($filtersResponse['status'] ?? 0) . ').';
    }
} catch (\Throwable $exception) {
    $materialFilterOptionsError = $exception->getMessage();
}

$currentRoute = '/dashboard/home-sections.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/home-sections.php';
$content = ob_get_clean();
$title = 'أقسام الرئيسية';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
