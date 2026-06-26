<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\CompanyBrandIconService;

$size = (int) ($_GET['size'] ?? 192);
$allowedSizes = [32, 180, 192, 512];
$size = in_array($size, $allowedSizes, true) ? $size : 192;

$brandPath = CompanyBrandIconService::iconAbsolutePath($size);
if (is_file($brandPath)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile($brandPath);
    exit;
}

$fallback = __DIR__ . '/icon-' . $size . '.png';
if (is_file($fallback)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($fallback);
    exit;
}

http_response_code(404);
