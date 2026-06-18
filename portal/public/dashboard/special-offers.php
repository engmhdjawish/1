<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;
use Portal\Services\SpecialOfferService;
use Portal\Support\DashboardHttp;

WebSession::requirePermission('special_offers.manage');
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
        'has_image' => $parseNullableBool($_POST['filter_has_image'] ?? null),
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
$buildDisplayOptions = static function (): array {
    $priceMode = trim((string) ($_POST['option_price_mode'] ?? 'both'));

    return [
        'show_images' => isset($_POST['option_show_images']),
        'price_mode' => in_array($priceMode, ['both', 'syp', 'usd', 'none'], true) ? $priceMode : 'both',
    ];
};

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$isNew = ($_GET['new'] ?? '') === '1';
$showForm = $editId !== '' || $isNew;
$user = WebSession::user();

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'تم حفظ العرض.';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $flash = 'تم حذف العرض.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'save_offer') {
            $selectionMode = trim((string) ($_POST['selection_mode'] ?? 'filter'));
            $payload = [
                'id' => trim((string) ($_POST['id'] ?? '')),
                'slug' => trim((string) ($_POST['slug'] ?? '')),
                'title_ar' => trim((string) ($_POST['title_ar'] ?? '')),
                'subtitle_ar' => trim((string) ($_POST['subtitle_ar'] ?? '')),
                'badge_text_ar' => trim((string) ($_POST['badge_text_ar'] ?? '')),
                'banner_image_url' => trim((string) ($_POST['banner_image_url'] ?? '')),
                'selection_mode' => $selectionMode,
                'discount_type' => trim((string) ($_POST['discount_type'] ?? 'percent')),
                'discount_percent' => $_POST['discount_percent'] ?? null,
                'fixed_price_syp' => $_POST['fixed_price_syp'] ?? null,
                'fixed_price_usd' => $_POST['fixed_price_usd'] ?? null,
                'starts_at' => trim((string) ($_POST['starts_at'] ?? '')),
                'ends_at' => trim((string) ($_POST['ends_at'] ?? '')),
                'is_active' => isset($_POST['is_active']),
                'priority' => (int) ($_POST['priority'] ?? 0),
                'min_packages' => $_POST['min_packages'] ?? null,
                'max_packages' => $_POST['max_packages'] ?? null,
                'max_products' => (int) ($_POST['max_products'] ?? 12),
                'show_on_home' => isset($_POST['show_on_home']),
                'home_sort_order' => (int) ($_POST['home_sort_order'] ?? 0),
                'filter_rules' => $selectionMode === 'filter' ? $buildFilterPayload() : [],
                'material_guids' => $selectionMode === 'manual' ? $parseValues($_POST['manual_material_guids'] ?? []) : [],
                'display_options' => $buildDisplayOptions(),
            ];
            $result = SpecialOfferService::save($payload, isset($user['id']) ? (string) $user['id'] : null);
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                if (DashboardHttp::wantsJson()) {
                    DashboardHttp::json(true, $flash, [
                        'reload' => true,
                        'redirect' => '/dashboard/special-offers.php?edit=' . urlencode((string) $result['id']) . '&saved=1',
                    ]);
                }
                header('Location: /dashboard/special-offers.php?edit=' . urlencode((string) $result['id']) . '&saved=1');
                exit;
            }
            $showForm = true;
            $editId = trim((string) ($_POST['id'] ?? ''));
            $isNew = $editId === '';
        } elseif ($action === 'toggle_offer') {
            $ok = SpecialOfferService::toggleActive(trim((string) ($_POST['id'] ?? '')), ($_POST['next_active'] ?? '0') === '1');
            $flash = $ok ? 'تم تحديث حالة العرض.' : 'تعذر التحديث.';
            $flashType = $ok ? 'success' : 'error';
        } elseif ($action === 'delete_offer') {
            $deleteId = trim((string) ($_POST['id'] ?? ''));
            $ok = SpecialOfferService::delete($deleteId);
            $flash = $ok ? 'تم حذف العرض.' : 'تعذر حذف العرض.';
            $flashType = $ok ? 'success' : 'error';
            if ($ok) {
                if (DashboardHttp::wantsJson()) {
                    DashboardHttp::json(true, $flash, ['reload' => true]);
                }
                header('Location: /dashboard/special-offers.php?deleted=1');
                exit;
            }
        } else {
            $flash = 'إجراء غير معروف.';
            $flashType = 'error';
        }
    } catch (\Throwable $exception) {
        $flash = 'تعذر تنفيذ العملية: ' . $exception->getMessage();
        $flashType = 'error';
    }

    if (DashboardHttp::wantsJson()) {
        DashboardHttp::json(
            $flashType === 'success',
            (string) ($flash ?? 'تعذر تنفيذ العملية.'),
            ['reload' => $flashType === 'success']
        );
    }
}

$dbError = null;
$stats = ['total' => 0, 'active' => 0, 'manual' => 0, 'filter' => 0, 'on_home' => 0];
try {
    $stats = SpecialOfferService::stats();
    $offers = SpecialOfferService::adminList();
} catch (\Throwable $e) {
    $offers = [];
    $dbError = 'تعذر الاتصال بجداول العروض. شغّل ملف الترحيل: docs/portal-migration-special-offers.sql على قاعدة PostgreSQL.';
}

$editOffer = null;
if ($editId !== '') {
    $editOffer = SpecialOfferService::getById($editId);
    if ($editOffer === null) {
        $editId = '';
        $showForm = $isNew;
    }
}
if ($showForm && $editOffer === null) {
    $editOffer = [
        'id' => '',
        'slug' => '',
        'title_ar' => '',
        'subtitle_ar' => '',
        'badge_text_ar' => '',
        'banner_image_url' => '',
        'selection_mode' => 'filter',
        'discount_type' => 'percent',
        'discount_percent' => '10',
        'fixed_price_syp' => '',
        'fixed_price_usd' => '',
        'starts_at' => date('Y-m-d\TH:i'),
        'ends_at' => '',
        'is_active' => 1,
        'priority' => 0,
        'min_packages' => '',
        'max_packages' => '',
        'max_products' => 12,
        'show_on_home' => 1,
        'home_sort_order' => 0,
        'filter_rules' => [],
        'display_options' => SpecialOfferService::defaultDisplayOptions(),
        'material_guids' => [],
        'manual_products' => [],
        'preview_products' => [],
    ];
}

$materialFilterOptions = ['materialTypes' => [], 'ageCategories' => [], 'manufacturers' => [], 'sizeRanges' => [], 'countryOfOrigins' => [], 'stores' => [], 'groups' => []];
$materialFilterOptionsError = null;
try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok']) {
        $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
        $materialFilterOptions = [
            'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
            'ageCategories' => array_values(array_map('strval', is_array($data['ageCategories'] ?? null) ? $data['ageCategories'] : ($data['AgeCategories'] ?? []))),
            'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
            'sizeRanges' => array_values(array_map('strval', is_array($data['sizeRanges'] ?? null) ? $data['sizeRanges'] : ($data['SizeRanges'] ?? []))),
            'countryOfOrigins' => array_values(array_map('strval', is_array($data['countryOfOrigins'] ?? null) ? $data['countryOfOrigins'] : ($data['CountryOfOrigins'] ?? []))),
            'stores' => array_values(array_filter(is_array($data['stores'] ?? null) ? $data['stores'] : ($data['Stores'] ?? []), static fn ($r) => is_array($r))),
            'groups' => array_values(array_filter(is_array($data['groups'] ?? null) ? $data['groups'] : ($data['Groups'] ?? []), static fn ($r) => is_array($r))),
        ];
    } else {
        $materialFilterOptionsError = 'تعذر جلب فلاتر المواد من API.';
    }
} catch (\Throwable $e) {
    $materialFilterOptionsError = $e->getMessage();
}

$currentRoute = '/dashboard/special-offers.php';
ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/special-offers.php';
$content = ob_get_clean();
$title = 'العروض الخاصة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
