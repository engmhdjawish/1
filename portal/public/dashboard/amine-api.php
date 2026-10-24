<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Services\PortalSettingsService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
// مدير النظام فقط — لا تُربط بإعدادات الشركة/محتوى الموقع.
if (!$isSuper) {
    http_response_code(403);
    echo 'غير مصرح لك بإدارة API الأمين.';
    exit;
}

$apiHealth = PortalSettingsService::apiHealth();
$apiBaseUrl = trim((string) (Config::get('AMINE_API_BASE_URL', '') ?? ''));
$currentRoute = '/dashboard/amine-api.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/amine-api.php';
$content = ob_get_clean();
$title = 'إدارة API الأمين';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
