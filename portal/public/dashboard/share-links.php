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
$editLink = null;
$user = WebSession::user();

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
        $result = ShareLinkService::save(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['name_ar'] ?? '')),
            trim((string) ($_POST['access_policy_id'] ?? '')),
            isset($_POST['require_password']),
            trim((string) ($_POST['access_username'] ?? '')),
            trim((string) ($_POST['plain_password'] ?? '')),
            trim((string) ($_POST['keyword'] ?? '')),
            (float) ($_POST['min_quantity'] ?? 0),
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
            isset($_POST['option_allow_client_filters']),
            isset($_POST['option_allow_sorting']),
            isset($_POST['option_include_result_filters']),
            trim((string) ($_POST['option_default_sort'] ?? 'number:asc')),
            trim((string) ($_POST['option_default_group_by'] ?? 'none'))
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $editId = (string) ($result['id'] ?? '');
        }
    } elseif ($action === 'toggle') {
        $ok = ShareLinkService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1'
        );
        $flash = $ok ? 'تم تحديث حالة الرابط.' : 'تعذر تحديث حالة الرابط.';
        $flashType = $ok ? 'success' : 'error';
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
}
if ($editLink === null) {
    $editId = '';
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
        $materialFilterOptions = [
            'materialTypes' => array_values(array_map('strval', $data['materialTypes'] ?? [])),
            'ageCategories' => array_values(array_map('strval', $data['ageCategories'] ?? [])),
            'manufacturers' => array_values(array_map('strval', $data['manufacturers'] ?? [])),
            'sizeRanges' => array_values(array_map('strval', $data['sizeRanges'] ?? [])),
            'countryOfOrigins' => array_values(array_map('strval', $data['countryOfOrigins'] ?? [])),
            'stores' => array_values(array_filter($data['stores'] ?? [], static fn ($row) => is_array($row))),
            'groups' => array_values(array_filter($data['groups'] ?? [], static fn ($row) => is_array($row))),
            'priceRanges' => is_array($data['priceRanges'] ?? null)
                ? $data['priceRanges']
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
