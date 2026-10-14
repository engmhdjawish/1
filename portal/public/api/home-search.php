<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/views/helpers.php';

use Portal\Services\HomeSearchService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$query = trim((string) ($_GET['q'] ?? ''));

try {
    echo json_encode(HomeSearchService::suggest($query), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('home-search.php: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'query' => $query,
        'guess' => null,
        'suggestions' => [],
    ], JSON_UNESCAPED_UNICODE);
}
