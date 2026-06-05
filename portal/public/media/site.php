<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\SiteMediaService;

$id = trim((string) ($_GET['id'] ?? ''));
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

$path = SiteMediaService::absolutePathForId($id);
if ($path === null || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image file missing.';
    exit;
}

$mime = trim((string) ($asset['mime_type'] ?? 'application/octet-stream'));
header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
