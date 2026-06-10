<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\ApiClient;
use Portal\Services\MaterialImageStorageService;

$id = trim((string) ($_GET['id'] ?? ''));
$thumb = ($_GET['thumb'] ?? '1') !== '0';

if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid image id.';
    exit;
}

$localPath = MaterialImageStorageService::resolvePathForGuid($id, $thumb);
if ($localPath === null && $thumb) {
    $localPath = MaterialImageStorageService::resolvePathForGuid($id, false);
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

$apiSuffixes = $thumb ? ['/thumbnail', '/file'] : ['/file'];
$result = ['ok' => false, 'status' => 404];
foreach ($apiSuffixes as $suffix) {
    $result = ApiClient::getBinary('/api/material-images/' . $id . $suffix);
    if ($result['ok']) {
        break;
    }
}

if (!$result['ok']) {
    http_response_code((int) ($result['status'] ?? 404));
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image not found.';
    exit;
}

header('Content-Type: ' . (string) ($result['contentType'] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=900');
echo $result['body'];
