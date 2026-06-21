<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageDisplayTemplateService;
use Portal\Services\PortalSettingsService;

WebSession::requirePermission('images.upload');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$template = MaterialImageDisplayTemplateService::getTemplate();
$fieldCatalog = MaterialImageDisplayTemplateService::fieldCatalog();
$sampleFields = MaterialImageDisplayTemplateService::sampleFieldMap();
$companyLogoUrl = PortalSettingsService::companyLogoUrl() ?? '';
$currentRoute = '/dashboard/material-image-template.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/material-image-template.php';
$content = ob_get_clean();
$title = 'محرر قالب عرض صور المواد';
$extraHead = '<link href="/css/material-image-frame.css" rel="stylesheet">'
    . '<link href="/assets/dashboard/material-image-template-editor.css" rel="stylesheet">';
$extraScripts = '<script src="/assets/dashboard/media-picker.js" defer></script>'
    . '<script src="/assets/dashboard/material-image-template-editor.js" defer></script>';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
