<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Database;
use Portal\Services\AccessPolicyService;
use Portal\Services\ApiClient;
use Portal\Services\EnvConfigService;
use Portal\Services\PortalSettingsService;
use Portal\Services\StorePolicyService;
use Portal\Support\DashboardHttp;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canManageCompany = $isSuper || in_array('company_settings.manage', $permissions, true);
$canManageGuestPolicy = $isSuper || in_array('store_policy.manage', $permissions, true) || in_array('access_policies.manage', $permissions, true);
$canManagePolicies = $isSuper || in_array('access_policies.manage', $permissions, true);
$canManageIntegration = $isSuper;

if (!$canManageCompany && !$canManageGuestPolicy && !$canManageIntegration && !$canManagePolicies) {
    http_response_code(403);
    echo 'غير مصرح لك بالوصول إلى الإعدادات.';
    exit;
}

$tab = trim((string) ($_GET['tab'] ?? 'company'));
if (!in_array($tab, ['company', 'integration', 'policies'], true)) {
    $tab = 'company';
}
if ($tab === 'integration' && !$canManageIntegration) {
    $tab = 'company';
}
if ($tab === 'policies' && !$canManageGuestPolicy && !$canManagePolicies) {
    $tab = 'company';
}

$policyEditId = trim((string) ($_GET['policy_edit'] ?? ''));
$policyIsNew = ($_GET['policy_new'] ?? '') === '1';
$policyShowForm = $tab === 'policies' && ($policyEditId !== '' || $policyIsNew);

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
$buildPolicyFilterPayload = static function () use ($parseValues, $parseNullableFloat, $parseNullableBool): array {
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

$buildPolicyStoreOptions = static function () use ($parseValues): array {
    $visibleClientFilters = AccessPolicyService::normalizeVisibleClientFilters($parseValues($_POST['option_visible_client_filters'] ?? []));

    return [
        'visible_client_filters' => $visibleClientFilters,
        'allow_sorting' => isset($_POST['option_allow_sorting']),
        'client_sort_fields' => $parseValues($_POST['option_client_sort_fields'] ?? []),
        'default_sort' => trim((string) ($_POST['option_default_sort'] ?? 'number:asc')),
    ];
};

$flash = null;
$flashType = 'success';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'تم حفظ الإعدادات.';
    $flashType = 'success';
}
if (isset($_GET['icon_warning']) && trim((string) $_GET['icon_warning']) !== '') {
    $flash = 'تم حفظ الإعدادات، لكن تعذر توليد أيقونات التطبيق: ' . trim((string) $_GET['icon_warning']);
    $flashType = 'error';
}
if (isset($_GET['policy_saved']) && $_GET['policy_saved'] === '1') {
    $flash = 'تم حفظ السياسة.';
    $flashType = 'success';
}
if (isset($_GET['policy_deleted']) && $_GET['policy_deleted'] === '1') {
    $flash = 'تم حذف السياسة.';
    $flashType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_company') {
        if (!$canManageCompany) {
            $flash = 'لا تملك صلاحية تعديل إعدادات الشركة.';
            $flashType = 'error';
        } else {
            try {
                $currentCompany = PortalSettingsService::companySettings();
                PortalSettingsService::saveCompanySettings([
                    'company_name' => trim((string) ($_POST['company_name'] ?? '')),
                    'company_phone' => trim((string) ($_POST['company_phone'] ?? '')),
                    'company_mobile' => trim((string) ($_POST['company_mobile'] ?? '')),
                    'company_whatsapp' => trim((string) ($_POST['company_whatsapp'] ?? '')),
                    'company_email' => trim((string) ($_POST['company_email'] ?? '')),
                    'company_address' => trim((string) ($_POST['company_address'] ?? '')),
                    'company_logo' => trim((string) ($_POST['company_logo'] ?? '')),
                    'about_us_title_ar' => trim((string) ($_POST['about_us_title_ar'] ?? '')),
                    'about_us_ar' => trim((string) ($_POST['about_us_ar'] ?? '')),
                    'material_images_dir' => (string) ($currentCompany['material_images_dir'] ?? ''),
                    'material_thumbnails_dir' => (string) ($currentCompany['material_thumbnails_dir'] ?? ''),
                ], isset($user['id']) ? (string) $user['id'] : null);
                $redirectQuery = 'tab=company&saved=1';
                $iconError = \Portal\Services\CompanyBrandIconService::lastError();
                if (is_string($iconError) && trim($iconError) !== '') {
                    $redirectQuery .= '&icon_warning=' . rawurlencode(trim($iconError));
                }
                header('Location: /dashboard/settings.php?' . $redirectQuery);
                exit;
            } catch (\Throwable $exception) {
                $flash = 'تعذر حفظ إعدادات الشركة: ' . $exception->getMessage();
                $flashType = 'error';
                $tab = 'company';
            }
        }
    } elseif ($action === 'save_integration') {
        if (!$canManageIntegration) {
            $flash = 'لا تملك صلاحية تعديل إعدادات الاتصال.';
            $flashType = 'error';
        } else {
            $updates = [
                'AMINE_API_BASE_URL' => trim((string) ($_POST['amine_api_base_url'] ?? '')),
                'AMINE_API_USERNAME' => trim((string) ($_POST['amine_api_username'] ?? '')),
                'PORTAL_DB_HOST' => trim((string) ($_POST['portal_db_host'] ?? '')),
                'PORTAL_DB_PORT' => trim((string) ($_POST['portal_db_port'] ?? '')),
                'PORTAL_DB_NAME' => trim((string) ($_POST['portal_db_name'] ?? '')),
                'PORTAL_DB_USER' => trim((string) ($_POST['portal_db_user'] ?? '')),
            ];
            $apiPassword = trim((string) ($_POST['amine_api_password'] ?? ''));
            $dbPassword = trim((string) ($_POST['portal_db_password'] ?? ''));
            if ($apiPassword !== '') {
                $updates['AMINE_API_PASSWORD'] = $apiPassword;
            }
            if ($dbPassword !== '') {
                $updates['PORTAL_DB_PASSWORD'] = $dbPassword;
            }
            $result = EnvConfigService::updateIntegrationSettings($updates);
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                header('Location: /dashboard/settings.php?tab=integration&saved=1');
                exit;
            }
            $tab = 'integration';
        }
    } elseif ($action === 'save_guest_policy') {
        if (!$canManageGuestPolicy) {
            $flash = 'لا تملك صلاحية تعديل سياسة المتجر العام.';
            $flashType = 'error';
        } else {
            $policyId = trim((string) ($_POST['access_policy_id'] ?? ''));
            if ($policyId === '') {
                $flash = 'يرجى اختيار سياسة وصول.';
                $flashType = 'error';
            } else {
                try {
                    PortalSettingsService::setGuestPolicy($policyId, isset($user['id']) ? (string) $user['id'] : null);
                    $maxRaw = trim((string) ($_POST['max_packages_per_material'] ?? ''));
                    $maxPackages = $maxRaw !== '' && is_numeric($maxRaw) ? (float) $maxRaw : null;
                    StorePolicyService::setMaxPackagesPerMaterial($maxPackages, isset($user['id']) ? (string) $user['id'] : null);
                    header('Location: /dashboard/settings.php?tab=policies&saved=1');
                    exit;
                } catch (\InvalidArgumentException $exception) {
                    $flash = $exception->getMessage();
                    $flashType = 'error';
                }
            }
        }
        $tab = 'policies';
    } elseif ($action === 'save_policy') {
        if (!$canManagePolicies) {
            $flash = 'لا تملك صلاحية إدارة السياسات.';
            $flashType = 'error';
        } else {
            $result = AccessPolicyService::save(
                trim((string) ($_POST['id'] ?? '')) ?: null,
                trim((string) ($_POST['code'] ?? '')),
                trim((string) ($_POST['name_ar'] ?? '')),
                trim((string) ($_POST['description_ar'] ?? '')),
                isset($_POST['show_price']),
                isset($_POST['show_quantity']),
                isset($_POST['allow_cart']),
                isset($_POST['allow_order']),
                isset($_POST['is_active']),
                $buildPolicyFilterPayload(),
                $buildPolicyStoreOptions()
            );
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                header('Location: /dashboard/settings.php?tab=policies&policy_saved=1');
                exit;
            }
            $tab = 'policies';
            $policyShowForm = true;
            $policyEditId = trim((string) ($_POST['id'] ?? ''));
            $policyIsNew = $policyEditId === '';
            $editPolicy = [
                'id' => $policyEditId,
                'code' => trim((string) ($_POST['code'] ?? '')),
                'name_ar' => trim((string) ($_POST['name_ar'] ?? '')),
                'description_ar' => trim((string) ($_POST['description_ar'] ?? '')),
                'show_price' => isset($_POST['show_price']) ? 1 : 0,
                'show_quantity' => isset($_POST['show_quantity']) ? 1 : 0,
                'allow_cart' => isset($_POST['allow_cart']) ? 1 : 0,
                'allow_order' => isset($_POST['allow_order']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'filter_rules' => $buildPolicyFilterPayload(),
                'store_options' => $buildPolicyStoreOptions(),
            ];
        }
    } elseif ($action === 'toggle_policy') {
        if (!$canManagePolicies) {
            $flash = 'لا تملك صلاحية إدارة السياسات.';
            $flashType = 'error';
        } else {
            $ok = AccessPolicyService::setActive(
                trim((string) ($_POST['id'] ?? '')),
                ($_POST['next_active'] ?? '0') === '1'
            );
            $flash = $ok ? 'تم تحديث حالة السياسة.' : 'تعذر تحديث السياسة (قد تكون السياسة الافتراضية للزائر).';
            $flashType = $ok ? 'success' : 'error';
        }
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($flashType === 'success', (string) $flash, ['reload' => true]);
        }
        $tab = 'policies';
    } elseif ($action === 'delete_policy') {
        if (!$canManagePolicies) {
            $flash = 'لا تملك صلاحية إدارة السياسات.';
            $flashType = 'error';
        } else {
            $result = AccessPolicyService::delete(trim((string) ($_POST['id'] ?? '')));
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                header('Location: /dashboard/settings.php?tab=policies&policy_deleted=1');
                exit;
            }
        }
        $tab = 'policies';
    }
}

$company = PortalSettingsService::companySettings();
$policies = AccessPolicyService::list(true);
$guestPolicyId = PortalSettingsService::guestPolicyId();
$maxPackagesPerMaterial = StorePolicyService::maxPackagesPerMaterial();
$apiHealth = PortalSettingsService::apiHealth();
$dbHealth = PortalSettingsService::databaseHealth();
$integration = EnvConfigService::integrationSettings();

$editPolicy = null;
if ($policyShowForm) {
    if ($policyEditId !== '') {
        $editPolicy = AccessPolicyService::getById($policyEditId);
        if ($editPolicy === null) {
            $policyEditId = '';
            $policyShowForm = $policyIsNew;
        }
    }
    if ($policyShowForm && $editPolicy === null) {
        $editPolicy = [
            'id' => '',
            'code' => '',
            'name_ar' => '',
            'description_ar' => '',
            'show_price' => 1,
            'show_quantity' => 0,
            'allow_cart' => 1,
            'allow_order' => 1,
            'is_active' => 1,
            'filter_rules' => AccessPolicyService::defaultFilterRules(),
            'store_options' => AccessPolicyService::defaultStoreOptions(),
        ];
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
];
$materialFilterOptionsError = null;
if ($policyShowForm) {
    try {
        $filtersResponse = ApiClient::get('/api/materials/filter-options');
        if ($filtersResponse['ok']) {
            $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
            $stores = is_array($data['stores'] ?? null) ? $data['stores'] : (is_array($data['Stores'] ?? null) ? $data['Stores'] : []);
            $groups = is_array($data['groups'] ?? null) ? $data['groups'] : (is_array($data['Groups'] ?? null) ? $data['Groups'] : []);
            $materialFilterOptions = [
                'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
                'ageCategories' => array_values(array_map('strval', is_array($data['ageCategories'] ?? null) ? $data['ageCategories'] : ($data['AgeCategories'] ?? []))),
                'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
                'sizeRanges' => array_values(array_map('strval', is_array($data['sizeRanges'] ?? null) ? $data['sizeRanges'] : ($data['SizeRanges'] ?? []))),
                'countryOfOrigins' => array_values(array_map('strval', is_array($data['countryOfOrigins'] ?? null) ? $data['countryOfOrigins'] : ($data['CountryOfOrigins'] ?? []))),
                'stores' => $stores,
                'groups' => $groups,
            ];
        } else {
            $materialFilterOptionsError = 'تعذر جلب خيارات الفلاتر من API.';
        }
    } catch (\Throwable $exception) {
        $materialFilterOptionsError = $exception->getMessage();
    }
}

$policyUsage = [];
foreach ($policies as $policy) {
    $policyUsage[(string) ($policy['id'] ?? '')] = AccessPolicyService::usageSummary((string) ($policy['id'] ?? ''));
}

$currentRoute = '/dashboard/settings.php?tab=' . rawurlencode($tab);

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/settings.php';
$content = ob_get_clean();
$title = 'الإعدادات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
