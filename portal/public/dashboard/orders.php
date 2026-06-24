<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;
use Portal\Services\WebCustomerService;
use Portal\Support\DashboardHttp;

use Portal\Support\DashboardOrderPricePreference;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$permissions = WebSession::user()['permissions'] ?? [];
$canManageOrders = in_array('orders.manage', $permissions, true) || in_array('*', $permissions, true);
if ($canManageOrders) {
    OrderService::ensureItemEditSchema();
}
$itemEditSchemaReady = OrderService::hasItemEditSchema();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageOrders) {
        $flash = 'ليس لديك صلاحية تعديل الطلب.';
        $flashType = 'error';
    } else {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $itemAction = trim((string) ($_POST['item_action'] ?? ''));
        $staffUserId = (string) (WebSession::user()['id'] ?? '');

        if ($itemAction !== '' && $orderId !== '') {
            $itemId = trim((string) ($_POST['item_id'] ?? ''));
            $reason = trim((string) ($_POST['reason_ar'] ?? ''));
            $result = match ($itemAction) {
                'update_qty' => OrderService::updateItemQuantity(
                    $orderId,
                    $itemId,
                    (float) ($_POST['quantity'] ?? 0),
                    $reason,
                    $staffUserId
                ),
                'update_price' => OrderService::updateItemPrice(
                    $orderId,
                    $itemId,
                    isset($_POST['sale_price_sp']) ? (float) $_POST['sale_price_sp'] : null,
                    isset($_POST['sale_price_usd']) ? (float) $_POST['sale_price_usd'] : null,
                    $reason,
                    $staffUserId
                ),
                'cancel_item' => OrderService::cancelOrderItem($orderId, $itemId, $reason, $staffUserId),
                default => ['ok' => false, 'message' => 'إجراء غير معروف.'],
            };
            $flash = $result['message'];
            $flashType = $result['ok'] ? 'success' : 'error';
            if (DashboardHttp::wantsJson()) {
                DashboardHttp::json($result['ok'], $flash, ['reload' => $result['ok']]);
            }
        } else {
            $nextStatus = trim((string) ($_POST['next_status'] ?? ''));
            $ok = $orderId !== '' && $nextStatus !== '' && OrderService::updateStatus($orderId, $nextStatus);
            $flash = $ok ? 'تم تحديث حالة الطلب.' : 'تعذر تحديث حالة الطلب.';
            $flashType = $ok ? 'success' : 'error';
            if (DashboardHttp::wantsJson()) {
                DashboardHttp::json($ok, $flash, ['reload' => true]);
            }
        }
    }
}

$filters = [
    'status' => array_key_exists('status', $_GET)
        ? trim((string) $_GET['status'])
        : (trim((string) ($_GET['web_customer_id'] ?? '')) !== '' ? '' : 'pending'),
    'sync' => trim((string) ($_GET['sync'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'origin' => trim((string) ($_GET['origin'] ?? '')),
    'web_customer_id' => trim((string) ($_GET['web_customer_id'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 50),
];
$detailsId = trim((string) ($_GET['details'] ?? ''));
DashboardOrderPricePreference::applyFromRequest($_GET);
$orderPriceCurrency = DashboardOrderPricePreference::current();

$ordersListQuery = $_GET;
unset($ordersListQuery['details']);
$ordersListUrl = '/dashboard/orders.php' . ($ordersListQuery !== [] ? '?' . http_build_query($ordersListQuery) : '');

$orders = OrderService::list($filters);
$orderDetails = $detailsId !== '' ? OrderService::getOrderDetails($detailsId) : null;
$filteredCustomer = null;
if (($filters['web_customer_id'] ?? '') !== '') {
    $filteredCustomer = WebCustomerService::getById((string) $filters['web_customer_id']);
}
$staffEditBlockReason = is_array($orderDetails) ? OrderService::staffEditBlockReason($orderDetails) : '';
$statusCounts = OrderService::statusCounts();
$syncCounts = OrderService::syncCounts();
$user = WebSession::user();
$currentRoute = '/dashboard/orders.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/orders.php';
$content = ob_get_clean();
$title = 'إدارة الطلبات';
$dashboardPageAssets = 'orders';
$extraFooter = $orderDetails !== null
    ? '<script src="/assets/dashboard-order-price-pref.js" defer></script>'
    : '';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
