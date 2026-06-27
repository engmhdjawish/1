<?php

declare(strict_types=1);

ob_start();

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Services\PortalPresenceService;
use Portal\Services\PortalSessionService;
use Portal\Support\DashboardHttp;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    DashboardHttp::emitJson(['ok' => false, 'message' => 'Method not allowed.'], 405);
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

    $staffLoggedIn = WebSession::check();
    $customerLoggedIn = CustomerSession::check();
    $loginRequired = !($staffLoggedIn || $customerLoggedIn);

    DashboardHttp::emitJson([
        'ok' => true,
        'login_required' => $loginRequired,
        'auth' => $staffLoggedIn ? 'staff' : ($customerLoggedIn ? 'customer' : 'guest'),
    ]);
} catch (\Throwable) {
    DashboardHttp::emitJson(['ok' => false, 'message' => 'تعذر تحديث الجلسة.'], 500);
}
