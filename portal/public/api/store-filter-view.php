<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\CatalogFilterViewService;

function portal_share_absolute(string $path): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    if ($host === 'jawishco.sy' || $host === 'www.jawishco.sy') {
        return 'https://www.jawishco.sy' . $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $host !== '' ? $scheme . '://' . $host . $path : $path;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$input = is_array($json) ? $json : $_POST;

$path = trim((string) ($input['path'] ?? '/store.php'));
$params = $input['params'] ?? $input['query'] ?? [];
if (!is_array($params)) {
    $params = [];
}

// Also accept a full href and parse it.
$href = trim((string) ($input['href'] ?? ''));
if ($href !== '') {
    $parts = parse_url($href);
    if (!empty($parts['path'])) {
        $path = (string) $parts['path'];
    }
    if (!empty($parts['query'])) {
        $parsed = [];
        parse_str((string) $parts['query'], $parsed);
        $params = array_merge($parsed, $params);
    }
}

if (trim((string) ($input['kind'] ?? '')) === 'path') {
    $target = trim((string) ($input['target'] ?? ''));
    $allowed = preg_match('#^/product\.php\?guid=[A-Za-z0-9-]{1,64}$#', $target) === 1
        || preg_match('#^/track-order\.php\?token=[A-Za-z0-9_-]{1,128}$#', $target) === 1;
    $short = $allowed ? CatalogFilterViewService::shortenPath($target) : null;
    if ($short === null) {
        http_response_code($allowed ? 500 : 422);
        echo json_encode(['ok' => false, 'message' => 'تعذر إنشاء رابط مختصر.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'url' => $short, 'short_url' => $short, 'share_url' => portal_share_absolute($short)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!CatalogFilterViewService::shouldShorten($params) && CatalogFilterViewService::normalizePayload($params) === []) {
    $barePath = $path;
    $accessKey = trim((string) ($params['token'] ?? $params['t'] ?? $params['c'] ?? ''));
    if ($accessKey !== '' && in_array($path, ['/share.php', '/share', '/s.php'], true)) {
        $link = \Portal\Services\ShareLinkService::resolvePublicAccessKey($accessKey);
        $barePath = is_array($link) ? \Portal\Services\ShareLinkService::publicPathForLink($link) : '/s/' . rawurlencode($accessKey);
    }
    echo json_encode([
        'ok' => true,
        'code' => '',
        'url' => $barePath,
        'shortened' => false,
        'share_url' => portal_share_absolute($barePath),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = CatalogFilterViewService::mint($path, $params);
if (!($result['ok'] ?? false)) {
    http_response_code(500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'code' => (string) ($result['code'] ?? ''),
    'url' => (string) ($result['url'] ?? $path),
    'shortened' => trim((string) ($result['code'] ?? '')) !== '',
    'short_url' => (string) ($result['short_url'] ?? ''),
    'share_url' => trim((string) ($result['short_url'] ?? '')) !== '' ? portal_share_absolute((string) $result['short_url']) : '',
], JSON_UNESCAPED_UNICODE);
