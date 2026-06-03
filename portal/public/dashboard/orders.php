<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $permissions = WebSession::user()['permissions'] ?? [];
    if (!in_array('orders.manage', $permissions, true) && !in_array('*', $permissions, true)) {
        $flash = 'ليس لديك صلاحية تعديل حالة الطلب.';
        $flashType = 'error';
    } else {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $nextStatus = trim((string) ($_POST['next_status'] ?? ''));
        $ok = $orderId !== '' && $nextStatus !== '' && OrderService::updateStatus($orderId, $nextStatus);
        $flash = $ok ? 'تم تحديث حالة الطلب.' : 'تعذر تحديث حالة الطلب.';
        $flashType = $ok ? 'success' : 'error';
    }
}

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'sync' => trim((string) ($_GET['sync'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 50),
];

$orders = OrderService::list($filters);
$statusCounts = OrderService::statusCounts();
$syncCounts = OrderService::syncCounts();
$user = WebSession::user();
$currentRoute = '/dashboard/orders.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/orders.php';
$content = ob_get_clean();
$title = 'إدارة الطلبات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
