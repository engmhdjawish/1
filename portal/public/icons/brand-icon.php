<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;

$size = (int) ($_GET['size'] ?? 192);
$allowedSizes = [32, 96, 180, 192, 512];
$size = in_array($size, $allowedSizes, true) ? $size : 192;
$maskable = ($_GET['maskable'] ?? '') === '1' && $size === 512;

$sendPng = static function (string $binary, int $mtime, int $maxAge): void {
    $etag = '"' . substr(sha1($binary), 0, 20) . '"';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    if ($mtime > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . (string) strlen($binary));
    echo $binary;
    exit;
};

// Install checks and Android's app packaging fetch these icons; answer from disk without the database.
$cachedPath = CompanyBrandIconService::cachedIconPath($size, $maskable);
if ($cachedPath !== null) {
    $binary = (string) @file_get_contents($cachedPath);
    if ($binary !== '') {
        $sendPng($binary, (int) filemtime($cachedPath), 604800);
    }
}

try {
    $logoUrl = PortalSettingsService::companyLogoUrl();
    $binary = CompanyBrandIconService::resolveIconBinary($size, $logoUrl, $maskable);
} catch (Throwable) {
    $binary = null;
}

if (is_string($binary) && $binary !== '') {
    $sendPng($binary, time(), 86400);
}

$fallback = __DIR__ . '/icon-' . $size . '.png';
if (!is_file($fallback) && $size === 96) {
    $fallback = __DIR__ . '/icon-192.png';
}
if (is_file($fallback)) {
    $sendPng((string) file_get_contents($fallback), (int) filemtime($fallback), 604800);
}

http_response_code(404);
