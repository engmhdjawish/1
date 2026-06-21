<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$data = trim((string) ($_GET['d'] ?? $_GET['data'] ?? ''));
$size = max(48, min(512, (int) ($_GET['s'] ?? 160)));
$margin = max(0, min(16, (int) ($_GET['m'] ?? 2)));
$foreground = trim((string) ($_GET['fg'] ?? '#000000'));
$background = trim((string) ($_GET['bg'] ?? '#ffffff'));

if ($data === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'missing data';
    exit;
}

if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $foreground)) {
    $foreground = '#000000';
}
if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $background)) {
    $background = '#ffffff';
}

$remote = 'https://api.qrserver.com/v1/create-qr-code/?'
    . http_build_query([
        'size' => $size . 'x' . $size,
        'data' => $data,
        'margin' => $margin,
        'color' => ltrim($foreground, '#'),
        'bgcolor' => ltrim($background, '#'),
        'format' => 'svg',
    ]);

$context = stream_context_create([
    'http' => [
        'timeout' => 8,
        'header' => "User-Agent: JawishPortal/1.0\r\n",
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$svg = @file_get_contents($remote, false, $context);
if (!is_string($svg) || trim($svg) === '') {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'qr unavailable';
    exit;
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $svg;
