<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\WebCustomerService;

WebSession::requirePermission('web_customers.approve');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = $_POST['customer_id'] ?? '';
    $adminId = WebSession::user()['id'];
    if (($_POST['action'] ?? '') === 'approve') {
        $ok = WebCustomerService::approve($customerId, $_POST['access_policy_id'] ?? '', $adminId);
        $flash = $ok ? 'تم التفعيل.' : 'تعذر التفعيل.';
        $flashType = $ok ? 'success' : 'error';
    } elseif (($_POST['action'] ?? '') === 'reject') {
        $ok = WebCustomerService::reject($customerId, 'مرفوض من الإدارة', $adminId);
        $flash = $ok ? 'تم الرفض.' : 'تعذر الرفض.';
        $flashType = $ok ? 'success' : 'error';
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));

$customers = WebCustomerService::listByStatus($statusFilter, $searchFilter, $sourceFilter, 120);
$statusCounts = WebCustomerService::statusCounts();
$policies = WebCustomerService::listAccessPolicies();
$user = WebSession::user();
$currentRoute = '/dashboard/customers.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/customers.php';
$content = ob_get_clean();
$title = 'عملاء الويب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
