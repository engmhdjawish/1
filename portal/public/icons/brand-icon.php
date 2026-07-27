<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;

$size = (int) ($_GET['size'] ?? 192);
$allowedSizes = [32, 96, 180, 192, 512];
$size = in_array($size, $allowedSizes, true) ? $size : 192;

$logoUrl = PortalSettingsService::companyLogoUrl();
$binary = CompanyBrandIconService::resolveIconBinary($size, $logoUrl);

if (is_string($binary) && $binary !== '') {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . (string) strlen($binary));
    echo $binary;
    exit;
}

$fallback = __DIR__ . '/icon-' . $size . '.png';
if (!is_file($fallback) && $size === 96) {
    $fallback = __DIR__ . '/icon-192.png';
}
if (is_file($fallback)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Length: ' . (string) filesize($fallback));
    readfile($fallback);
    exit;
}

http_response_code(404);
