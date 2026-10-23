<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\WebCustomerService;
use Portal\Support\PortalUrl;

require dirname(__DIR__) . '/views/helpers.php';

if (CustomerSession::isLoggedIn()) {
    header('Location: /store.php');
    exit;
}

$error = null;
$message = null;
$old = ['name' => '', 'phone' => '', 'shop_address' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'shop_address' => trim((string) ($_POST['shop_address'] ?? '')),
    ];
    $result = WebCustomerService::registerSelf(
        $old['name'],
        portal_normalize_phone($old['phone']),
        (string) ($_POST['password'] ?? ''),
        $old['shop_address']
    );
    if ($result['ok']) {
        if (!empty($result['logged_in'])) {
            header('Location: ' . (PortalUrl::safeRedirectPath('/store.php') ?? '/store.php'));
            exit;
        }
        $message = $result['message'];
    } else {
        $error = $result['message'];
    }
}

ob_start();
require dirname(__DIR__) . '/views/register.php';
$content = ob_get_clean();
$title = 'تسجيل عميل';
require dirname(__DIR__) . '/views/layout.php';
