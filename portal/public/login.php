<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Support\PortalUrl;

require dirname(__DIR__) . '/views/helpers.php';

$type = $_GET['type'] ?? $_POST['type'] ?? 'staff';
$type = $type === 'customer' ? 'customer' : 'staff';
$error = null;
$message = $_GET['message'] ?? null;
$redirect = PortalUrl::safeRedirectPath($_GET['redirect'] ?? $_POST['redirect'] ?? null);

if ($type === 'customer' && WebSession::check()) {
    WebSession::logout();
} elseif ($type === 'staff' && CustomerSession::check()) {
    CustomerSession::logout();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($type === 'staff' && WebSession::check()) {
        header('Location: ' . PortalUrl::loginRedirectTarget('staff', $redirect));
        exit;
    }
    if ($type === 'customer' && CustomerSession::check()) {
        header('Location: ' . PortalUrl::loginRedirectTarget('customer', $redirect));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim((string) ($_POST['password'] ?? ''));
    $redirect = PortalUrl::safeRedirectPath($_POST['redirect'] ?? $redirect);
    if ($type === 'customer') {
        $ok = CustomerSession::login(trim($_POST['phone'] ?? ''), $password);
        if ($ok) {
            header('Location: ' . PortalUrl::loginRedirectTarget('customer', $redirect));
            exit;
        }
        $error = 'فشل الدخول. تأكد من التفعيل بعد موافقة الإدارة.';
    } else {
        $loginError = null;
        $ok = WebSession::login(trim($_POST['user_name'] ?? ''), $password, $loginError);
        if ($ok) {
            header('Location: ' . PortalUrl::loginRedirectTarget('staff', $redirect));
            exit;
        }
        $error = $loginError ?? 'بيانات الدخول غير صحيحة.';
    }
}

ob_start();
require dirname(__DIR__) . '/views/login.php';
$content = ob_get_clean();
$title = 'تسجيل الدخول';
require dirname(__DIR__) . '/views/layout.php';
