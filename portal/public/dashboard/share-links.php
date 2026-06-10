<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Services\ApiClient;
use Portal\Services\ShareLinkService;

WebSession::requirePermission('share_links.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$isNew = ($_GET['new'] ?? '') === '1';
$showForm = $editId !== '' || $isNew;
$editLink = null;
$user = WebSession::user();

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'تم حفظ رابط المشاركة.';
    $flashType = 'success';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $flash = 'تم حذف رابط المشاركة.';
    $flashType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save') {
        $parseValues = static function (mixed $value): array {
            if (is_array($value)) {
                $parts = $value;
            } else {
                $parts = preg_split('/[,|\n]+/u', (string) $value) ?: [];
            }
            $values = [];
            foreach ($parts as $part) {
                $item = trim((string) $part);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
            return array_values(array_unique($values));
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
        $clientSortFields = $parseValues($_POST['option_client_sort_fields'] ?? []);
        $defaultSortField = $clientSortFields[0] ?? 'number';
        $defaultSortValue = $defaultSortField . ':asc';
        $visibleClientFilters = $parseValues($_POST['option_visible_client_filters'] ?? []);
        $allowClientFilters = isset($_POST['option_allow_client_filters']);
        $result = ShareLinkService::save(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['name_ar'] ?? '')),
            trim((string) ($_POST['access_policy_id'] ?? '')),
            isset($_POST['require_password']),
            trim((string) ($_POST['access_username'] ?? '')),
            trim((string) ($_POST['plain_password'] ?? '')),
            trim((string) ($_POST['keyword'] ?? '')),
            0,
            trim((string) ($_POST['expires_at'] ?? '')),
            isset($_POST['is_active']),
            isset($user['id']) ? (string) $user['id'] : null,
            $parseValues($_POST['forced_material_types'] ?? []),
            $parseValues($_POST['forced_age_categories'] ?? []),
            $parseValues($_POST['forced_manufacturers'] ?? []),
            $parseValues($_POST['forced_size_ranges'] ?? []),
            $parseValues($_POST['forced_country_origins'] ?? []),
            $parseValues($_POST['forced_store_guids'] ?? []),
            $parseValues($_POST['forced_group_guids'] ?? []),
            $parseNullableBool($_POST['forced_is_available'] ?? null),
            $parseNullableBool($_POST['forced_has_image'] ?? null),
            $parseNullableFloat($_POST['forced_min_warehouse_quantity'] ?? null),
            $parseNullableFloat($_POST['forced_max_warehouse_quantity'] ?? null),
            $parseNullableFloat($_POST['forced_min_unit_sale_price_syp'] ?? null),
            $parseNullableFloat($_POST['forced_max_unit_sale_price_syp'] ?? null),
            $parseNullableFloat($_POST['forced_min_unit_sale_price_usd'] ?? null),
            $parseNullableFloat($_POST['forced_max_unit_sale_price_usd'] ?? null),
            $parseNullableFloat($_POST['forced_min_unit_purchase_price_usd'] ?? null),
            $parseNullableFloat($_POST['forced_max_unit_purchase_price_usd'] ?? null),
            isset($_POST['option_show_images']),
            trim((string) ($_POST['option_price_mode'] ?? 'both')),
            $allowClientFilters,
            isset($_POST['option_allow_sorting']),
            $allowClientFilters,
            $visibleClientFilters,
            $clientSortFields,
            $defaultSortValue,
            trim((string) ($_POST['option_default_group_by'] ?? 'none'))
        );
        if ($result['ok']) {
            header('Location: /dashboard/share-links.php?saved=1');
            exit;
        }
        $flash = $result['message'];
        $flashType = 'error';
        $showForm = true;
        $editId = trim((string) ($_POST['id'] ?? ''));
        $isNew = $editId === '';
    } elseif ($action === 'toggle') {
        $ok = ShareLinkService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1'
        );
        $flash = $ok ? 'تم تحديث حالة الرابط.' : 'تعذر تحديث حالة الرابط.';
        $flashType = $ok ? 'success' : 'error';
    } elseif ($action === 'delete') {
        $deleteResult = ShareLinkService::delete(trim((string) ($_POST['id'] ?? '')));
        $flash = $deleteResult['message'];
        $flashType = $deleteResult['ok'] ? 'success' : 'error';
        if ($deleteResult['ok']) {
            header('Location: /dashboard/share-links.php?deleted=1');
            exit;
        }
    }
}

$filters = [
    'active' => trim((string) ($_GET['active'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 100),
];

$links = ShareLinkService::list($filters);
if ($editId !== '') {
    $editLink = ShareLinkService::getById($editId);
    if ($editLink === null) {
        $editId = '';
        $showForm = $isNew;
    }
}
if ($showForm && $editLink === null) {
    $editLink = [
        'id' => '',
        'name_ar' => '',
        'access_policy_id' => '',
        'require_password' => 0,
        'access_username' => '',
        'keyword' => '',
        'min_quantity' => 0,
        'expires_at' => null,
        'is_active' => 1,
        'forced_material_types' => [],
        'forced_age_categories' => [],
        'forced_manufacturers' => [],
        'forced_size_ranges' => [],
        'forced_country_origins' => [],
        'forced_store_guids' => [],
        'forced_group_guids' => [],
        'constraints' => [],
        'options' => ShareLinkService::defaultLinkOptions(),
    ];
}
$stats = ShareLinkService::stats();
$policies = ShareLinkService::listAccessPolicies();
$publicBaseUrl = rtrim(Config::appUrl(), '/');
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
            'priceRanges' => is_array($priceRanges)
                ? $priceRanges
                : [
                    'unitSalePriceSyp' => null,
                    'unitSalePriceUsd' => null,
                    'unitPurchasePriceUsd' => null,
                ],
        ];
    } else {
        $materialFilterOptionsError = 'تعذر جلب فلاتر المواد من API (رمز ' . (int) ($filtersResponse['status'] ?? 0) . ').';
    }
} catch (\Throwable $exception) {
    $materialFilterOptionsError = $exception->getMessage();
}

$currentRoute = '/dashboard/share-links.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/share-links.php';
$content = ob_get_clean();
$title = 'روابط المشاركة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
