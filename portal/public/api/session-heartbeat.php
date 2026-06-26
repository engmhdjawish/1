<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\PortalPresenceService;
use Portal\Services\PortalSessionService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$visitorId = '';
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    try {
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($payload)) {
            $visitorId = trim((string) ($payload['visitor_id'] ?? ''));
        }
    } catch (\Throwable) {
        // ignore invalid JSON body
    }
}

try {
    PortalSessionService::touchCurrent();
    PortalPresenceService::touchFromRequest($visitorId);
    if (random_int(1, 50) === 1) {
        PortalPresenceService::pruneStale();
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
