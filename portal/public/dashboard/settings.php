<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;

WebSession::requirePermission('store_policy.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$heading = 'الإعدادات العامة';
$description = 'إعدادات هوية الشركة، سياسة الزائر، ومؤشرات الربط مع API.';
$readiness = 'partial (اعتمادًا على إعدادات portal_db + health checks)';
$nextActions = [
    ['href' => '/dashboard/index.php', 'label' => 'العودة إلى لوحة التحكم'],
    ['href' => '/dashboard/accounting-sync.php', 'label' => 'فتح طابور المزامنة'],
];
$user = WebSession::user();
$currentRoute = '/dashboard/settings.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/coming-soon.php';
$content = ob_get_clean();
$title = 'الإعدادات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
