<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\VisitorLogService;

WebSession::requireAnyPermission(['visitors.view', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 7;
}

$sessionId = trim((string) ($_GET['session'] ?? ''));
$schemaReady = VisitorLogService::hasSchema();
$summary = $schemaReady ? VisitorLogService::summaryForDays($days) : [
    'total_events' => 0,
    'page_views' => 0,
    'product_views' => 0,
    'cart_adds' => 0,
    'unique_sessions' => 0,
    'unique_ips' => 0,
    'registered_hits' => 0,
];

$recent = $schemaReady ? VisitorLogService::recent(150, null, $days) : [];
$topProducts = $schemaReady ? VisitorLogService::topProducts($days, 15) : [];
$topPages = $schemaReady ? VisitorLogService::topPages($days, 12) : [];
$actionBreakdown = $schemaReady ? VisitorLogService::actionBreakdown($days) : [];
$topReferrers = $schemaReady ? VisitorLogService::topReferrers($days, 8) : [];
$sessions = $schemaReady ? VisitorLogService::sessionSummaries($days, 25) : [];
$sessionEvents = ($schemaReady && $sessionId !== '')
    ? VisitorLogService::sessionEvents($sessionId, 120)
    : [];
$mapPoints = $schemaReady ? VisitorLogService::mapPoints($days, 250) : [];
$locationStats = $schemaReady ? VisitorLogService::locationStats($days, 12) : [];
$currentRoute = $sessionId !== ''
    ? '/dashboard/visitor-analytics.php?days=' . $days . '&session=' . rawurlencode($sessionId)
    : '/dashboard/visitor-analytics.php?days=' . $days;

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/visitor-analytics.php';
$content = ob_get_clean();
$title = 'نشاط الزوار';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
