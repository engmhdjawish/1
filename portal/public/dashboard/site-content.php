<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Support\DashboardNavigation;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
if (!DashboardNavigation::hasSiteContentAccess($user)) {
    http_response_code(403);
    echo 'غير مصرح لك بالوصول إلى محتوى الموقع.';
    exit;
}

$siteContentGroups = DashboardNavigation::siteContentGroups($user);
$currentRoute = '/dashboard/site-content.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/site-content.php';
$content = ob_get_clean();
$title = 'محتوى الموقع';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
