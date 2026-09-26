<?php

declare(strict_types=1);

/**
 * Serves material images for store browsing from the portal disk only.
 * Never proxies or downloads from Amine on this hot path (keeps the storefront fast).
 * Missing locals are backfilled via dashboard / scripts/pull-missing-material-images.php.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\MaterialImageStorageService;

$id = trim((string) ($_GET['id'] ?? ''));
$thumb = ($_GET['thumb'] ?? '1') !== '0';

if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid image id.';
    exit;
}

// localOnly=true: queue + GUID filename on disk — no Amine API round-trip.
$localPath = MaterialImageStorageService::resolvePathForGuid($id, $thumb, true);
if ($localPath === null && $thumb) {
    $localPath = MaterialImageStorageService::resolvePathForGuid($id, false, true);
}

if ($localPath !== null && is_readable($localPath)) {
    $mime = match (strtolower(pathinfo($localPath, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . (string) filesize($localPath));
    readfile($localPath);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Image not found locally.';
