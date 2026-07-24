<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\SiteMediaDerivativeService;
use Portal\Services\SiteMediaService;
use Portal\Services\SvgRasterService;

$id = trim((string) ($_GET['id'] ?? ''));
$preferRaster = in_array(strtolower(trim((string) ($_GET['format'] ?? ''))), ['png', 'raster'], true)
    || (($_GET['raster'] ?? '') === '1');
$maxWidth = max(0, (int) ($_GET['w'] ?? 0));
$requestedFormat = strtolower(trim((string) ($_GET['format'] ?? '')));
$wantsWebp = $requestedFormat === 'webp';

if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid image id.';
    exit;
}

$asset = SiteMediaService::getById($id);
if ($asset === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image not found.';
    exit;
}

$originalPath = SiteMediaService::absolutePathForId($id);
if ($originalPath === null || !is_readable($originalPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image file missing.';
    exit;
}

$path = $originalPath;
$mime = trim((string) ($asset['mime_type'] ?? 'application/octet-stream'));

try {
    $isSvg = $mime === 'image/svg+xml' || str_ends_with(strtolower($path), '.svg');
    if ($preferRaster && $isSvg) {
        $rasterPath = SvgRasterService::rasterCompanionPath($path);
        if (is_file($rasterPath) && is_readable($rasterPath) && filesize($rasterPath) > 128) {
            $path = $rasterPath;
            $mime = 'image/png';
            $isSvg = false;
        }
    }

    $derivativeFormat = $wantsWebp ? 'webp' : '';
    if ($maxWidth > 0 || $wantsWebp) {
        if ($isSvg) {
            $rasterPath = SvgRasterService::rasterCompanionPath($path);
            if (is_file($rasterPath) && is_readable($rasterPath) && filesize($rasterPath) > 128) {
                $path = $rasterPath;
                $mime = 'image/png';
            }
        }

        $derivative = SiteMediaDerivativeService::resolve($path, $mime, $maxWidth, $derivativeFormat);
        if ($derivative !== null) {
            $path = $derivative['path'];
            $mime = $derivative['mime'];
        }
    }
} catch (\Throwable $exception) {
    error_log('media/site.php derivative failed: ' . $exception->getMessage());
    $path = $originalPath;
    $mime = trim((string) ($asset['mime_type'] ?? 'application/octet-stream'));
    if ($preferRaster && ($mime === 'image/svg+xml' || str_ends_with(strtolower($path), '.svg'))) {
        $rasterPath = SvgRasterService::rasterCompanionPath($path);
        if (is_file($rasterPath) && is_readable($rasterPath) && filesize($rasterPath) > 128) {
            $path = $rasterPath;
            $mime = 'image/png';
        }
    }
}

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image file missing.';
    exit;
}

header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Cache-Control: public, max-age=604800, immutable');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
