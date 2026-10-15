<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\StoreCatalogService;
use Portal\Support\SessionGate;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=600');

// Filter options recompute the catalog and call the materials API.
// Release the session first so add, delete, and currency are not queued behind it.
SessionGate::release();

try {
    $payload = StoreCatalogService::getClientFiltersPayload($_GET);
    header('X-Filter-Source: ' . StoreCatalogService::lastFiltersPayloadSource());
    echo json_encode([
        'ok' => true,
        'filterOptions' => $payload['filterOptions'],
        'resultFilters' => $payload['resultFilters'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'filterOptions' => ['stores' => [], 'groups' => []],
        'resultFilters' => [],
    ], JSON_UNESCAPED_UNICODE);
}
