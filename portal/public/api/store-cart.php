<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Support\StoreCartApi;

require dirname(__DIR__, 2) . '/views/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $reconcile = ($_GET['reconcile'] ?? '1') !== '0';
    echo json_encode(StoreCartApi::state($reconcile), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$input = is_array($json) ? $json : $_POST;
$action = trim((string) ($input['action'] ?? ''));

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'الإجراء مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = StoreCartApi::dispatch($action, $input);
$status = ($result['ok'] ?? false) ? 200 : 422;
http_response_code($status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
