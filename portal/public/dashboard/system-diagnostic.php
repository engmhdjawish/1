<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\PortalSystemDiagnosticService;
use Portal\Support\DashboardNavigation;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
if (!DashboardNavigation::hasConfigurationAccess($user)) {
    http_response_code(403);
    echo 'غير مصرح لك بتشغيل التشخيص.';
    exit;
}

$format = strtolower(trim((string) ($_GET['format'] ?? '')));
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(PortalSystemDiagnosticService::run(), JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(120);
$report = PortalSystemDiagnosticService::run();
$currentRoute = '/dashboard/system-diagnostic.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/system-diagnostic.php';
$content = ob_get_clean();
$title = 'تشخيص الأداء';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
