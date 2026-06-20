<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Support\DashboardNavigation;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
if (!DashboardNavigation::hasConfigurationAccess($user)) {
    http_response_code(403);
    echo 'غير مصرح لك بالوصول إلى الإعدادات.';
    exit;
}

$configurationGroups = DashboardNavigation::configurationGroups($user);
$currentRoute = '/dashboard/configuration.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/configuration.php';
$content = ob_get_clean();
$title = 'الإعدادات والتهيئة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
