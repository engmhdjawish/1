<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\MaterialImageStorageService;

$file = trim((string) ($_GET['file'] ?? ''));
$thumb = ($_GET['thumb'] ?? '1') !== '0';

if ($file === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid file name.';
    exit;
}

$path = MaterialImageStorageService::resolveLocalPath($file, $thumb);
if ($path === null && $thumb) {
    $path = MaterialImageStorageService::resolveLocalPath($file, false);
}

if ($path === null || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image not found.';
    exit;
}

$mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'bmp' => 'image/bmp',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
