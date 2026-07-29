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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = WebCustomerService::registerSelf(
        trim($_POST['name'] ?? ''),
        portal_normalize_phone(trim($_POST['phone'] ?? '')),
        $_POST['password'] ?? '',
        trim($_POST['email'] ?? '') ?: null
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
