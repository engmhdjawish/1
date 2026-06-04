<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Services\PortalSettingsService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canManageCompany = $isSuper || in_array('company_settings.manage', $permissions, true);
$canManageGuestPolicy = $isSuper || in_array('store_policy.manage', $permissions, true) || in_array('access_policies.manage', $permissions, true);
$canManageAccessPolicies = $canManageGuestPolicy;

if (!$canManageCompany && !$canManageGuestPolicy) {
    http_response_code(403);
    echo 'غير مصرح لك بالوصول إلى الإعدادات.';
    exit;
}

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_company') {
        if (!$canManageCompany) {
            $flash = 'لا تملك صلاحية تعديل إعدادات الشركة.';
            $flashType = 'error';
        } else {
            PortalSettingsService::saveCompanySettings([
                'company_name' => trim((string) ($_POST['company_name'] ?? '')),
                'company_phone' => trim((string) ($_POST['company_phone'] ?? '')),
                'company_mobile' => trim((string) ($_POST['company_mobile'] ?? '')),
                'company_whatsapp' => trim((string) ($_POST['company_whatsapp'] ?? '')),
                'company_address' => trim((string) ($_POST['company_address'] ?? '')),
                'company_logo' => trim((string) ($_POST['company_logo'] ?? '')),
            ], isset($user['id']) ? (string) $user['id'] : null);
            $flash = 'تم حفظ إعدادات الشركة.';
            $flashType = 'success';
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
                $flash = 'تم تحديث سياسة الزائر.';
                $flashType = 'success';
            }
        }
    }
}

$company = PortalSettingsService::companySettings();
$policies = PortalSettingsService::accessPolicies(true);
$guestPolicyId = PortalSettingsService::guestPolicyId();
$apiHealth = PortalSettingsService::apiHealth();
$apiConfig = [
    'base_url' => Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '',
    'username' => Config::get('AMINE_API_USERNAME', '') ?? '',
];
$canManageAccessPolicies = $canManageGuestPolicy;
$currentRoute = '/dashboard/settings.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/settings.php';
$content = ob_get_clean();
$title = 'الإعدادات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
