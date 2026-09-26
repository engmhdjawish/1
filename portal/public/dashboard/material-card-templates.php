<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialCardTemplateService;

WebSession::requireAnyPermission(['images.templates.manage', 'images.upload']);
require_once dirname(__DIR__, 2) . '/views/helpers.php';

$requirements = MaterialCardTemplateService::processingRequirements();
$templates = [];
$fonts = [];
$loadError = null;
$fieldLabels = MaterialCardTemplateService::FIELD_LABELS;
try {
    $templates = MaterialCardTemplateService::listTemplates();
    $fonts = MaterialCardTemplateService::listFonts();
} catch (Throwable $e) {
    $loadError = 'تعذر تحميل القوالب. تأكد من تشغيل ترحيلات قاعدة البيانات 014 و015: ' . $e->getMessage();
}

$currentRoute = '/dashboard/material-card-templates.php';
ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/material-card-templates.php';
$content = ob_get_clean();
$title = 'قوالب بطاقة صور المواد';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
