<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\HomePageService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    echo json_encode([
        'ok' => true,
        'strips' => HomePageService::productStripHtmlBySectionKey(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    error_log('home-products.php: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'تعذر تحميل منتجات الرئيسية.',
    ], JSON_UNESCAPED_UNICODE);
}
