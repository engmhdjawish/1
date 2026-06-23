<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\OrderService;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $orderId = trim((string) ($_POST['order_id'] ?? ''));
    if ($action === 'cancel_order') {
        $result = OrderService::cancelOrderByCustomer($orderId, null, null, $token);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    } elseif ($action === 'cancel_order_item') {
        $itemId = trim((string) ($_POST['item_id'] ?? ''));
        $result = OrderService::cancelOrderItemByCustomer($orderId, $itemId, null, null, $token);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
    }
}

$order = $token !== '' ? OrderService::getOrderByQuoteToken($token) : null;
$error = null;

if ($token === '') {
    $error = 'رابط المتابعة غير صالح.';
} elseif ($order === null) {
    $error = 'تعذر العثور على الطلب. تحقق من الرابط أو تواصل معنا.';
}

$trackingUrl = $token !== '' ? absolute_order_tracking_url($token) : '';

ob_start();
require dirname(__DIR__) . '/views/track-order.php';
$content = ob_get_clean();
$title = $error ? 'متابعة الطلب' : 'طلب ' . (string) ($order['order_number'] ?? '');
$extraHead = '<link href="' . h(portal_asset_url('/css/customer-portal.css')) . '" rel="stylesheet">';
$extraFooter = '<script src="' . h(portal_asset_url('/assets/store-image-zoom.js')) . '" defer></script>';
$enableStoreCartJs = false;
$enableQuickView = false;
require dirname(__DIR__) . '/views/layout.php';
