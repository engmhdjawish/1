<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;

require dirname(__DIR__) . '/views/helpers.php';

$type = $_GET['type'] ?? $_POST['type'] ?? 'staff';
$error = null;
$message = $_GET['message'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($type === 'customer') {
        $ok = CustomerSession::login(trim($_POST['phone'] ?? ''), $password);
        if ($ok) {
            header('Location: /store.php');
            exit;
        }
        $error = 'فشل الدخول. تأكد من التفعيل بعد موافقة الإدارة.';
    } else {
        $ok = WebSession::login(trim($_POST['user_name'] ?? ''), $password);
        if ($ok) {
            header('Location: /dashboard/index.php');
            exit;
        }
        $error = 'بيانات الدخول غير صحيحة.';
    }
}

ob_start();
require dirname(__DIR__) . '/views/login.php';
$content = ob_get_clean();
$title = 'تسجيل الدخول';
require dirname(__DIR__) . '/views/layout.php';
