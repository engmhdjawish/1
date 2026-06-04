<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccessPolicyService;
use Portal\Services\PortalSettingsService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canManage = $isSuper
    || in_array('access_policies.manage', $permissions, true)
    || in_array('store_policy.manage', $permissions, true);

if (!$canManage) {
    http_response_code(403);
    echo 'غير مصرح لك بإدارة سياسات الوصول.';
    exit;
}

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save') {
        $result = AccessPolicyService::save(
            trim((string) ($_POST['id'] ?? '')) !== '' ? trim((string) ($_POST['id'] ?? '')) : null,
            [
                'code' => $_POST['code'] ?? '',
                'name_ar' => $_POST['name_ar'] ?? '',
                'description_ar' => $_POST['description_ar'] ?? '',
                'show_price' => $_POST['show_price'] ?? null,
                'show_quantity' => $_POST['show_quantity'] ?? null,
                'allow_cart' => $_POST['allow_cart'] ?? null,
                'allow_order' => $_POST['allow_order'] ?? null,
                'is_active' => $_POST['is_active'] ?? null,
            ]
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok'] && !empty($result['id'])) {
            $editId = (string) $result['id'];
        }
    } elseif ($action === 'delete') {
        $result = AccessPolicyService::delete(trim((string) ($_POST['id'] ?? '')));
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $editId = '';
        }
    } elseif ($action === 'save_guest_policy') {
        $policyId = trim((string) ($_POST['access_policy_id'] ?? ''));
        if ($policyId === '') {
            $flash = 'يرجى اختيار سياسة للمتجر العام.';
            $flashType = 'error';
        } else {
            PortalSettingsService::setGuestPolicy($policyId, isset($user['id']) ? (string) $user['id'] : null);
            $flash = 'تم تحديث سياسة الزائر في المتجر العام.';
            $flashType = 'success';
        }
    }
}

$policies = AccessPolicyService::listPolicies(true);
$guestPolicyId = PortalSettingsService::guestPolicyId();
$editPolicy = $editId !== '' ? AccessPolicyService::getById($editId) : null;
if ($editPolicy === null) {
    $editId = '';
}

$currentRoute = '/dashboard/access-policies.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/access-policies.php';
$content = ob_get_clean();
$title = 'سياسات الوصول';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
