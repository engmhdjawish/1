<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$heading = 'إدارة أقسام الصفحة الرئيسية';
$description = 'هذه الشاشة مخصصة لإدارة ترتيب الأقسام والبنرات وقواعد اختيار المنتجات.';
$readiness = 'partial (جاهزة بالهيكل وتنتظر CRUD كامل)';
$nextActions = [
    ['href' => '/dashboard/index.php', 'label' => 'العودة إلى لوحة التحكم'],
    ['href' => '/dashboard/share-links.php', 'label' => 'مراجعة روابط المشاركة والسياسات'],
];
$user = WebSession::user();
$currentRoute = '/dashboard/home-sections.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/coming-soon.php';
$content = ob_get_clean();
$title = 'أقسام الرئيسية';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
