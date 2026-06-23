<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Support\StorePricePreference;

require dirname(__DIR__, 2) . '/views/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$input = is_array($json) ? $json : $_POST;
$currency = strtolower(trim((string) ($input['currency'] ?? '')));

if ($currency !== StorePricePreference::SYP && $currency !== StorePricePreference::USD) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'عملة غير صالحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

StorePricePreference::set($currency);

echo json_encode([
    'ok' => true,
    'currency' => $currency,
    'price_mode' => $currency,
    'label' => StorePricePreference::label($currency),
], JSON_UNESCAPED_UNICODE);
