<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\CatalogFilterViewService;

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

if (!CatalogFilterViewService::shouldShorten($params) && CatalogFilterViewService::normalizePayload($params) === []) {
    echo json_encode([
        'ok' => true,
        'code' => '',
        'url' => $path,
        'shortened' => false,
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
], JSON_UNESCAPED_UNICODE);
