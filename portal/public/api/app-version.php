<?php

declare(strict_types=1);

/**
 * Lightweight "which client build is current?" probe for installed PWAs.
 * No session, no cookies, no database: it must stay cheap and always reflect the files on disk.
 */
define('PORTAL_NO_SESSION', true);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Support\PwaBuild;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$build = PwaBuild::id();

echo json_encode([
    'ok' => true,
    'build' => $build,
    'sw' => PwaBuild::serviceWorkerUrl($build),
], JSON_UNESCAPED_SLASHES);
