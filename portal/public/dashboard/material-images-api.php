<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageStorageService;

header('Content-Type: application/json; charset=utf-8');

WebSession::requirePermission('images.upload');
MaterialImageStorageService::ensureSettings();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'list'));
    if ($action === 'list') {
        echo json_encode([
            'ok' => true,
            'files' => MaterialImageStorageService::listLocalFiles(),
            'stats' => MaterialImageStorageService::stats(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
    $result = MaterialImageStorageService::uploadSingle($file);
    echo json_encode([
        'ok' => (bool) ($result['ok'] ?? false),
        'message' => (string) ($result['message'] ?? ''),
        'file_name' => (string) ($result['file_name'] ?? ''),
        'replaced' => (bool) ($result['replaced'] ?? false),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'الطريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
