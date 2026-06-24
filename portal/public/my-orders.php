<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\OrderService;

CustomerSession::requireLogin();
require dirname(__DIR__) . '/views/helpers.php';

$customer = CustomerSession::customer();
$customerId = (string) ($customer['id'] ?? '');
$phone = (string) ($customer['phone'] ?? '');
$profile = ['name_ar' => $customer['name_ar'] ?? '', 'email' => $customer['email'] ?? ''];
$orderId = trim((string) ($_GET['order'] ?? $_POST['order_id'] ?? ''));
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'cancel_order') {
        $targetOrderId = trim((string) ($_POST['order_id'] ?? ''));
        $result = OrderService::cancelOrderByCustomer($targetOrderId, $customerId, $phone, null);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $orderId = $targetOrderId;
        }
    } elseif ($action === 'cancel_order_item') {
        $targetOrderId = trim((string) ($_POST['order_id'] ?? ''));
        $itemId = trim((string) ($_POST['item_id'] ?? ''));
        $result = OrderService::cancelOrderItemByCustomer($targetOrderId, $itemId, $customerId, $phone, null);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $orderId = $targetOrderId;
        }
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$orders = OrderService::listForCustomer($customerId, $phone, [
    'status' => $statusFilter,
    'limit' => 50,
]);
$orderDetails = $orderId !== '' ? OrderService::getOrderForCustomer($orderId, $customerId, $phone) : null;
if ($orderId !== '' && $orderDetails === null) {
    $flash = $flash ?? 'الطلب غير موجود أو لا يخص حسابك.';
    $flashType = $flashType === 'success' && $flash !== null ? 'error' : $flashType;
}

$title = 'طلباتي';
$extraHead = '<link href="' . h(portal_asset_url('/css/customer-portal.css')) . '" rel="stylesheet">';
$extraFooter = '<script src="' . h(portal_asset_url('/assets/store-image-zoom.js')) . '" defer></script>';
$enableQuickView = false;
ob_start();
require dirname(__DIR__) . '/views/my-orders.php';
$content = ob_get_clean();
require dirname(__DIR__) . '/views/layout.php';
