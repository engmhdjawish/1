<?php

declare(strict_types=1);

/**
 * Serves material images for store browsing from the portal disk only.
 * thumb=1 serves a real thumbnail (generated once from the original if missing).
 * Never proxies or downloads from Amine on this hot path.
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

if ($thumb) {
    $localPath = MaterialImageStorageService::ensureStoreThumbnail($id);
} else {
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
