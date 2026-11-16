<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Support\DashboardNavigation;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
if (!DashboardNavigation::hasMerchandisingAccess($user)) {
    http_response_code(403);
    echo 'غير مصرح لك بالوصول إلى العروض والمخزون.';
    exit;
}

$merchandisingGroups = DashboardNavigation::merchandisingGroups($user);
$currentRoute = '/dashboard/merchandising.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/merchandising.php';
$content = ob_get_clean();
$title = 'العروض والمخزون';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';