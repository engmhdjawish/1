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

ob_start();
require dirname(__DIR__) . '/views/about.php';
$content = ob_get_clean();
$title = $aboutTitle;
$companyContext = $company;
$companyLogoUrl = $logoUrl;
require dirname(__DIR__) . '/views/layout.php';
