<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\VisitorLogService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    $input = [];
}

$customer = CustomerSession::check() ? CustomerSession::customer() : null;
$result = VisitorLogService::recordEvent(
    (string) ($input['session_id'] ?? ''),
    (string) ($input['action'] ?? 'page_view'),
    (string) ($input['path'] ?? ''),
    (string) ($input['title'] ?? ''),
    (string) ($input['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
    $customer
);

echo json_encode([
    'ok' => (bool) ($result['ok'] ?? false),
    'message' => (string) ($result['message'] ?? ''),
], JSON_UNESCAPED_UNICODE);
