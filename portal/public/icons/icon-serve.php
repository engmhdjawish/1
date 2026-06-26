<?php

declare(strict_types=1);

$allowed = [
    'icon-32.png' => 'image/png',
    'icon-180.png' => 'image/png',
    'icon-192.png' => 'image/png',
    'icon-512.png' => 'image/png',
];

$name = basename((string) ($_GET['f'] ?? 'icon-192.png'));
if (!isset($allowed[$name])) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $allowed[$name]);
header('Cache-Control: public, max-age=86400');
readfile($path);
