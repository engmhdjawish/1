<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\OrderService;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? ''));
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
$extraHead = '<link href="/css/customer-portal.css" rel="stylesheet"><link href="/css/store-cart.css" rel="stylesheet"><link href="/css/store-ui.css" rel="stylesheet">';
$extraFooter = '<script src="/assets/store-image-zoom.js" defer></script>';
require dirname(__DIR__) . '/views/layout.php';
