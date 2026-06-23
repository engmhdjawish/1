<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\VisitorLogService;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 7;
}

$schemaReady = VisitorLogService::hasSchema();
$summary = $schemaReady ? VisitorLogService::summaryForDays($days) : [
    'page_views' => 0,
    'unique_sessions' => 0,
    'unique_ips' => 0,
    'registered_hits' => 0,
];
$recent = $schemaReady ? VisitorLogService::recent(120, null, $days) : [];
$mapPoints = $schemaReady ? VisitorLogService::mapPoints($days, 250) : [];
$currentRoute = '/dashboard/visitor-analytics.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/visitor-analytics.php';
$content = ob_get_clean();
$title = 'نشاط الزوار';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
