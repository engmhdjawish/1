<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;
use Portal\Support\DashboardHttp;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$permissions = WebSession::user()['permissions'] ?? [];
$canManageOrders = in_array('orders.manage', $permissions, true) || in_array('*', $permissions, true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageOrders) {
        $flash = 'ليس لديك صلاحية تعديل حالة الطلب.';
        $flashType = 'error';
    } else {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $nextStatus = trim((string) ($_POST['next_status'] ?? ''));
        $ok = $orderId !== '' && $nextStatus !== '' && OrderService::updateStatus($orderId, $nextStatus);
        $flash = $ok ? 'تم تحديث حالة الطلب.' : 'تعذر تحديث حالة الطلب.';
        $flashType = $ok ? 'success' : 'error';
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($ok, $flash, ['reload' => true]);
        }
    }
}

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'sync' => trim((string) ($_GET['sync'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 50),
];
$detailsId = trim((string) ($_GET['details'] ?? ''));

$orders = OrderService::list($filters);
$orderDetails = $detailsId !== '' ? OrderService::getOrderDetails($detailsId) : null;
$statusCounts = OrderService::statusCounts();
$syncCounts = OrderService::syncCounts();
$user = WebSession::user();
$currentRoute = '/dashboard/orders.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/orders.php';
$content = ob_get_clean();
$title = 'إدارة الطلبات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
