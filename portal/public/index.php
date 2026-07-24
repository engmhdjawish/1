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
$ads = SiteMediaService::listAdsForHome();

ob_start();
require dirname(__DIR__) . '/views/home.php';
$content = ob_get_clean();
$title = 'الرئيسية';
$extraHead = '<link href="' . h(portal_asset_url('/css/home-page.css')) . '" rel="stylesheet">';
$extraFooter = '<script src="' . h(portal_asset_url('/assets/home-page.js')) . '" defer></script>';
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = $storeCatalogDisplay['allow_cart'] ?? false;
require dirname(__DIR__) . '/views/layout.php';
