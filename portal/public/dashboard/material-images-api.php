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
    $action = trim((string) ($_GET['action'] ?? 'stats'));

    if ($action === 'stats') {
        echo json_encode([
            'ok' => true,
            'stats' => MaterialImageStorageService::stats(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'browse') {
        $result = MaterialImageStorageService::browseMaterials([
            'page' => (int) ($_GET['page'] ?? 1),
            'page_size' => (int) ($_GET['page_size'] ?? 24),
            'search' => (string) ($_GET['search'] ?? ''),
            'material_types' => $_GET['material_types'] ?? ($_GET['material_types[]'] ?? null),
            'age_categories' => $_GET['age_categories'] ?? ($_GET['age_categories[]'] ?? null),
            'manufacturers' => $_GET['manufacturers'] ?? ($_GET['manufacturers[]'] ?? null),
            'size_ranges' => $_GET['size_ranges'] ?? ($_GET['size_ranges[]'] ?? null),
            'country_origins' => $_GET['country_origins'] ?? ($_GET['country_origins[]'] ?? null),
            'store_guids' => $_GET['store_guids'] ?? ($_GET['store_guids[]'] ?? null),
            'group_guids' => $_GET['group_guids'] ?? ($_GET['group_guids[]'] ?? null),
            'has_image' => $_GET['has_image'] ?? '1',
            'is_available' => $_GET['is_available'] ?? null,
            'local_status' => (string) ($_GET['local_status'] ?? 'all'),
        ]);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
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
