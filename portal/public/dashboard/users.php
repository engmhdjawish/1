<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;

WebSession::requirePermission('web_users.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$heading = 'المستخدمون والأدوار';
$description = 'إدارة حسابات موظفي البوابة وربطها بالأدوار والصلاحيات.';
$readiness = 'partial (النموذج جاهز ويحتاج تنفيذ شاشات CRUD)';
$nextActions = [
    ['href' => '/dashboard/index.php', 'label' => 'العودة إلى لوحة التحكم'],
    ['href' => '/dashboard/settings.php', 'label' => 'مراجعة الإعدادات العامة'],
];
$user = WebSession::user();
$currentRoute = '/dashboard/users.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/coming-soon.php';
$content = ob_get_clean();
$title = 'المستخدمون والأدوار';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
