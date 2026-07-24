<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

use Portal\Services\SiteMediaDerivativeService;
use Portal\Services\SiteMediaService;
use Portal\Services\SvgRasterService;

function site_media_respond_text(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function site_media_respond_file(string $path, string $mime, bool $webpFallback = false): never
{
    if (!is_file($path) || !is_readable($path)) {
        site_media_respond_text(404, 'Image file missing.');
    }

    $size = filesize($path);
    if ($size === false || $size <= 0) {
        site_media_respond_text(404, 'Image file missing.');
    }

    $mime = trim($mime) !== '' ? trim($mime) : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800, immutable');
    if ($webpFallback) {
        header('X-Site-Media-Fallback: png');
    }
    header('Content-Length: ' . (string) $size);
    readfile($path);
    exit;
}

/** @return array{path: string, mime: string} */
function site_media_resolve_raster_path(string $originalPath, string $mime, int $targetSize = 1024): array
{
    $path = $originalPath;
    $mime = trim($mime) !== '' ? trim($mime) : 'application/octet-stream';
    $isSvg = $mime === 'image/svg+xml' || str_ends_with(strtolower($originalPath), '.svg');
    if (!$isSvg) {
        return ['path' => $path, 'mime' => $mime];
    }

    $rasterPath = SvgRasterService::rasterCompanionPath($originalPath);
    if (!is_file($rasterPath) || !is_readable($rasterPath) || filesize($rasterPath) <= 128) {
        SvgRasterService::toPngFile($originalPath, $rasterPath, max(512, $targetSize));
    }

    if (is_file($rasterPath) && is_readable($rasterPath) && filesize($rasterPath) > 128) {
        return ['path' => $rasterPath, 'mime' => 'image/png'];
    }

    return ['path' => $path, 'mime' => $mime];
}

try {
    require dirname(__DIR__, 2) . '/bootstrap.php';

    $id = trim((string) ($_GET['id'] ?? ''));
    $preferRaster = in_array(strtolower(trim((string) ($_GET['format'] ?? ''))), ['png', 'raster'], true)
        || (($_GET['raster'] ?? '') === '1');
    $maxWidth = max(0, (int) ($_GET['w'] ?? 0));
    $requestedFormat = strtolower(trim((string) ($_GET['format'] ?? '')));
    $wantsWebp = $requestedFormat === 'webp';

    if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
        site_media_respond_text(400, 'Invalid image id.');
    }

    $asset = SiteMediaService::getById($id);
    if ($asset === null) {
        site_media_respond_text(404, 'Image not found.');
    }

    $originalPath = SiteMediaService::absolutePathForId($id);
    if ($originalPath === null || !is_readable($originalPath)) {
        site_media_respond_text(404, 'Image file missing.');
    }

    $path = $originalPath;
    $mime = trim((string) ($asset['mime_type'] ?? 'application/octet-stream'));
    $isLogo = (string) ($asset['category'] ?? '') === 'logo';

    if ($isLogo && $wantsWebp) {
        $wantsWebp = false;
    }

    $raster = site_media_resolve_raster_path($originalPath, $mime, max(512, $maxWidth));
    $path = $raster['path'];
    $mime = $raster['mime'];

    if ($preferRaster && ($mime === 'image/svg+xml' || str_ends_with(strtolower($path), '.svg'))) {
        site_media_respond_text(404, 'Image file missing.');
    }

    $derivativeFormat = $wantsWebp ? 'webp' : '';
    if ($maxWidth > 0 || $wantsWebp) {
        $derivative = SiteMediaDerivativeService::resolve($path, $mime, $maxWidth, $derivativeFormat);
        if ($derivative !== null) {
            $path = $derivative['path'];
            $mime = $derivative['mime'];
        } elseif ($wantsWebp && $mime === 'image/png') {
            site_media_respond_file($path, $mime, true);
        }
    }

    site_media_respond_file($path, $mime);
} catch (Throwable $exception) {
    error_log('media/site.php failed: ' . $exception->getMessage());

    if (isset($originalPath) && is_string($originalPath) && is_readable($originalPath)) {
        $fallbackMime = isset($asset) && is_array($asset)
            ? trim((string) ($asset['mime_type'] ?? 'application/octet-stream'))
            : 'application/octet-stream';
        $fallback = site_media_resolve_raster_path($originalPath, $fallbackMime);
        site_media_respond_file($fallback['path'], $fallback['mime'], true);
    }

    site_media_respond_text(500, 'Image unavailable.');
}
