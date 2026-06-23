<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Services\MaterialImageZipService;
use Portal\Services\OrderService;

require dirname(__DIR__, 2) . '/views/helpers.php';

$orderId = trim((string) ($_GET['order_id'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));

if ($orderId === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'معرّف الطلب مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$order = null;

if (WebSession::check() && WebSession::hasAnyPermission(['orders.view', 'images.upload'])) {
    $order = OrderService::getOrderDetails($orderId);
} elseif (CustomerSession::check()) {
    $customer = CustomerSession::customer();
    $order = OrderService::getOrderForCustomer(
        $orderId,
        (string) ($customer['id'] ?? ''),
        (string) ($customer['phone'] ?? '')
    );
} elseif ($token !== '') {
    $tokenOrder = OrderService::getOrderByQuoteToken($token);
    if ($tokenOrder !== null && (string) ($tokenOrder['id'] ?? '') === $orderId) {
        $order = $tokenOrder;
    }
}

if ($order === null) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'غير مصرح لك بتحميل صور هذا الطلب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    $archiveName = 'order-' . (string) ($order['order_number'] ?? $orderId) . '-images';
    MaterialImageZipService::streamOrderImagesZip($order, $items, $archiveName);
} catch (\Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
