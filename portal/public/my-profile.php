<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\WebCustomerService;

CustomerSession::requireLogin();
require dirname(__DIR__) . '/views/helpers.php';

$customer = CustomerSession::customer();
$customerId = (string) ($customer['id'] ?? '');
$profile = WebCustomerService::getById($customerId) ?? [];
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'change_password') {
        $result = WebCustomerService::changeOwnPassword(
            $customerId,
            trim((string) ($_POST['current_password'] ?? '')),
            trim((string) ($_POST['new_password'] ?? ''))
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    }
}

$title = 'الملف الشخصي';
$extraHead = '<link href="' . h(portal_asset_url('/css/customer-portal.css')) . '" rel="stylesheet">';
$enableQuickView = false;
ob_start();
require dirname(__DIR__) . '/views/my-profile.php';
$content = ob_get_clean();
require dirname(__DIR__) . '/views/layout.php';
