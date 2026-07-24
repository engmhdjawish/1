<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Support\PortalUrl;

require dirname(__DIR__) . '/views/helpers.php';

/** @var string|null $loginPagePath */
$type = $_GET['type'] ?? $_POST['type'] ?? 'staff';
$type = $type === 'customer' ? 'customer' : 'staff';
$error = null;
$message = $_GET['message'] ?? null;
$redirect = PortalUrl::safeRedirectPath($_GET['redirect'] ?? $_POST['redirect'] ?? null);
$loginPagePath = isset($loginPagePath) ? (string) $loginPagePath : PortalUrl::loginPagePath($type);

if ($type === 'customer' && WebSession::check()) {
    WebSession::logout();
} elseif ($type === 'staff' && CustomerSession::check()) {
    CustomerSession::logout();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && PortalUrl::requestPath() === '/login.php') {
    $target = PortalUrl::loginPagePath($type);
    $query = [];
    if ($redirect !== null) {
        $query['redirect'] = $redirect;
    }
    if ($message !== null && $message !== '') {
        $query['message'] = $message;
    }
    header('Location: ' . $target . ($query !== [] ? '?' . http_build_query($query) : ''));
    exit;
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
    $redirect = PortalUrl::safeRedirectPath($_POST['redirect'] ?? $redirect);
    if ($type === 'customer') {
        $password = trim((string) ($_POST['customer_password'] ?? $_POST['password'] ?? ''));
        $loginError = null;
        $ok = CustomerSession::login(
            portal_normalize_phone(trim((string) ($_POST['customer_phone'] ?? $_POST['phone'] ?? ''))),
            $password,
            $loginError,
        );
        if ($ok) {
            header('Location: ' . PortalUrl::loginRedirectTarget('customer', $redirect));
            exit;
        }
        $error = $loginError ?? 'بيانات الدخول غير صحيحة.';
    } else {
        $password = trim((string) ($_POST['staff_password'] ?? $_POST['password'] ?? ''));
        $loginError = null;
        $ok = WebSession::login(trim((string) ($_POST['staff_user_name'] ?? $_POST['user_name'] ?? '')), $password, $loginError);
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
