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
$deferHomeProducts = true;
$sections = HomePageService::mergedSectionShells();
$embeddedProductStrips = HomePageService::embeddedProductStrips();
$ads = SiteMediaService::listAdsForHome();

$lcpPreloadUrl = null;
if ($ads !== []) {
    $firstAdUrl = trim((string) ($ads[0]['url'] ?? ''));
    if ($firstAdUrl !== '') {
        $lcpPreloadUrl = portal_site_media_display_url($firstAdUrl, 1280);
    }
}

$homeHasEmbeddedStrips = false;
$homeProductsPending = false;
foreach ($sections as $section) {
    $sectionKey = trim((string) ($section['slug'] ?? $section['id'] ?? ''));
    if ($sectionKey === '') {
        continue;
    }
    if (trim((string) ($embeddedProductStrips[$sectionKey] ?? '')) !== '') {
        $homeHasEmbeddedStrips = true;
    } else {
        $homeProductsPending = true;
    }
}

ob_start();
require dirname(__DIR__) . '/views/home.php';
$content = ob_get_clean();
$title = 'الرئيسية';
$extraHead = portal_preload_stylesheet('/css/home-page.css');
if ($homeProductsPending) {
    $extraHead .= '<link rel="preload" href="/api/home-products.php" as="fetch" crossorigin="same-origin">';
    $extraHead .= '<script>window.__homeProductsFetch=fetch("/api/home-products.php",{credentials:"same-origin",headers:{Accept:"application/json"}});</script>';
}
$extraFooter = portal_defer_script('/assets/home-page.js');
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = $storeCatalogDisplay['allow_cart'] ?? false;
require dirname(__DIR__) . '/views/layout.php';
