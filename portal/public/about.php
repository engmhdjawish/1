<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\PortalSettingsService;

require dirname(__DIR__) . '/views/helpers.php';

$company = PortalSettingsService::companySettings();
$logoUrl = PortalSettingsService::companyLogoUrl($company);
$aboutTitle = trim((string) ($company['about_us_title_ar'] ?? ''));
if ($aboutTitle === '') {
    $aboutTitle = 'من نحن';
}
$aboutText = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutText === '') {
    $aboutText = default_about_content();
}
$aboutContent = parse_about_content($aboutText);

ob_start();
require dirname(__DIR__) . '/views/about.php';
$content = ob_get_clean();
$title = $aboutTitle;
$companyContext = $company;
$companyLogoUrl = $logoUrl;
$extraHead = '<link href="/css/about-page.css" rel="stylesheet">';
require dirname(__DIR__) . '/views/layout.php';
