<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Database;
use Portal\Services\AccessPolicyService;
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

$flash = null;
$flashType = 'success';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'تم حفظ الإعدادات.';
    $flashType = 'success';
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
            header('Location: /dashboard/settings.php?tab=company&saved=1');
            exit;
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
                PortalSettingsService::setGuestPolicy($policyId, isset($user['id']) ? (string) $user['id'] : null);
                $maxRaw = trim((string) ($_POST['max_packages_per_material'] ?? ''));
                $maxPackages = $maxRaw !== '' && is_numeric($maxRaw) ? (float) $maxRaw : null;
                StorePolicyService::setMaxPackagesPerMaterial($maxPackages, isset($user['id']) ? (string) $user['id'] : null);
                header('Location: /dashboard/settings.php?tab=policies&saved=1');
                exit;
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
                isset($_POST['is_active'])
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
        ];
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
