<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\ApiClient;

$id = trim((string) ($_GET['id'] ?? ''));
$thumb = ($_GET['thumb'] ?? '1') !== '0';

if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid image id.';
    exit;
}

$path = '/api/material-images/' . $id . ($thumb ? '/thumbnail' : '/file');
$result = ApiClient::getBinary($path);

if (!$result['ok']) {
    http_response_code((int) ($result['status'] ?? 404));
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image not found.';
    exit;
}

header('Content-Type: ' . (string) ($result['contentType'] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=900');
echo $result['body'];
