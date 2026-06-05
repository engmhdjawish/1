<?php

declare(strict_types=1);

/**
 * JSON proxy to Amine API (server-side JWT).
 * Compatible action codes for legacy front-end scripts.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\ApiClient;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = file_get_contents('php://input');
$jsonBody = $body ? json_decode($body, true) : [];

try {
    $result = match ($action) {
        'mat_filter', 'materials' => proxyMaterials($jsonBody, $_GET),
        'mat_filter_options', 'filter_options' => ApiClient::get('/api/materials/filter-options'),
        default => ['ok' => false, 'status' => 400, 'error' => 'إجراء غير معروف: ' . $action],
    };

    http_response_code($result['status'] ?? ($result['ok'] ? 200 : 500));
    echo json_encode($result['data'] ?? $result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}

/** @param array<string, mixed> $jsonBody */
function proxyMaterials(array $jsonBody, array $query): array
{
    $params = array_filter([
        'page' => $jsonBody['page'] ?? $query['page'] ?? 1,
        'pageSize' => $jsonBody['pageSize'] ?? $query['pageSize'] ?? 50,
        'keyword' => $jsonBody['keyword'] ?? $jsonBody['search'] ?? $query['keyword'] ?? null,
        'materialTypes' => $jsonBody['materialTypes'] ?? null,
        'manufacturers' => $jsonBody['manufacturers'] ?? null,
        'ageCategories' => $jsonBody['targetCategories'] ?? $jsonBody['ageCategories'] ?? null,
    ], static fn ($value) => $value !== null && $value !== '');

    return ApiClient::get('/api/materials', $params);
}
