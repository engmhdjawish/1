<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\StoreCatalogService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$query = $_GET;
unset($query['page'], $query['soon'], $query['storeGuids'], $query['minWarehouseQuantity'], $query['maxWarehouseQuantity']);

try {
    $catalog = StoreCatalogService::labCatalogFromRequest($query);
    $error = trim((string) ($catalog['apiError'] ?? ''));
    echo json_encode(
        $error === ''
            ? ['ok' => true, 'total' => max(0, (int) ($catalog['totalCount'] ?? 0))]
            : ['ok' => false],
        JSON_UNESCAPED_UNICODE
    );
} catch (\Throwable $exception) {
    error_log('store-count.php: ' . $exception->getMessage());
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
