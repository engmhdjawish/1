<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\OrderService;
use Portal\Services\WebCustomerService;

CustomerSession::requireLogin();
require dirname(__DIR__) . '/views/helpers.php';

$customer = CustomerSession::customer();
$customerId = (string) ($customer['id'] ?? '');
$phone = (string) ($customer['phone'] ?? '');
$profile = WebCustomerService::getById($customerId) ?? [];

$tab = ($_GET['tab'] ?? 'profile') === 'orders' ? 'orders' : 'profile';
$orderId = trim((string) ($_GET['order'] ?? $_POST['order_id'] ?? ''));
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (in_array($action, ['cancel_order', 'cancel_order_item'], true)) {
        $tab = 'orders';
    }
    if ($action === 'update_profile') {
        $result = WebCustomerService::updateOwnProfile(
            $customerId,
            trim((string) ($_POST['name_ar'] ?? '')),
            trim((string) ($_POST['email'] ?? ''))
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            CustomerSession::refresh();
            $customer = CustomerSession::customer() ?? $customer;
            $profile = WebCustomerService::getById($customerId) ?? $profile;
        }
    } elseif ($action === 'change_password') {
        $result = WebCustomerService::changeOwnPassword(
            $customerId,
            trim((string) ($_POST['current_password'] ?? '')),
            trim((string) ($_POST['new_password'] ?? ''))
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    } elseif ($action === 'cancel_order') {
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

$title = 'حسابي';
$extraHead = '<link href="' . h(portal_asset_url('/css/customer-portal.css')) . '" rel="stylesheet">';
$extraFooter = '<script src="' . h(portal_asset_url('/assets/store-image-zoom.js')) . '" defer></script>';
$enableQuickView = false;
ob_start();
require dirname(__DIR__) . '/views/account.php';
$content = ob_get_clean();
require dirname(__DIR__) . '/views/layout.php';
