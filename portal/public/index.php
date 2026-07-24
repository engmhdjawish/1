<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\HomePageService;
use Portal\Services\PortalSettingsService;
use Portal\Services\SiteMediaService;
use Portal\Services\StoreCatalogService;

require dirname(__DIR__) . '/views/helpers.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$companyContext = PortalSettingsService::companySettings();
$companyLogoUrl = PortalSettingsService::companyLogoUrl($companyContext);
$storeCatalogDisplay = StoreCatalogService::displayOptions();
$deferHomeProducts = false;
$sections = HomePageService::mergedSectionShells();
$embeddedProductStrips = HomePageService::embeddedProductStrips();
if ($embeddedProductStrips === []) {
    $embeddedProductStrips = HomePageService::productStripHtmlBySectionKey();
}
$ads = SiteMediaService::listAdsForHome();

$lcpPreloadUrl = null;
if (!empty($companyLogoUrl)) {
    $lcpPreloadUrl = portal_site_logo_url((string) $companyLogoUrl, 'header');
} elseif ($ads !== []) {
    $firstAdUrl = trim((string) ($ads[0]['url'] ?? ''));
    if ($firstAdUrl !== '') {
        $lcpPreloadUrl = portal_site_media_display_url($firstAdUrl, 1280);
    }
}

$homeHasEmbeddedStrips = $embeddedProductStrips !== [];

ob_start();
require dirname(__DIR__) . '/views/home.php';
$content = ob_get_clean();
$title = 'الرئيسية';
$extraHead = portal_preload_stylesheet('/css/home-page.css');
$extraFooter = portal_defer_script('/assets/home-page.js');
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = $storeCatalogDisplay['allow_cart'] ?? false;
require dirname(__DIR__) . '/views/layout.php';
