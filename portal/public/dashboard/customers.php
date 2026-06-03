<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\WebCustomerService;

WebSession::requirePermission('web_customers.approve');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = $_POST['customer_id'] ?? '';
    $adminId = WebSession::user()['id'];
    if (($_POST['action'] ?? '') === 'approve') {
        $ok = WebCustomerService::approve($customerId, $_POST['access_policy_id'] ?? '', $adminId);
        $flash = $ok ? 'تم التفعيل.' : 'تعذر التفعيل.';
    } elseif (($_POST['action'] ?? '') === 'reject') {
        $ok = WebCustomerService::reject($customerId, 'مرفوض من الإدارة', $adminId);
        $flash = $ok ? 'تم الرفض.' : 'تعذر الرفض.';
    }
}

$pending = WebCustomerService::listPending();
$policies = WebCustomerService::listAccessPolicies();
$user = WebSession::user();
$currentRoute = '/dashboard/customers.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/customers.php';
$content = ob_get_clean();
$title = 'عملاء الويب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
