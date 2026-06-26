<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\StoreCatalogService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

try {
    echo json_encode([
        'ok' => true,
        'filterOptions' => StoreCatalogService::getCachedFilterOptions(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'filterOptions' => ['stores' => [], 'groups' => []],
    ], JSON_UNESCAPED_UNICODE);
}
