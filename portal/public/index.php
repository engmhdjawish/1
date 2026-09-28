<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\HomeCategoryService;
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
try {
    $storeCatalogDisplay = StoreCatalogService::displayOptions();
} catch (Throwable $e) {
    error_log('index.php displayOptions: ' . $e->getMessage());
    $storeCatalogDisplay = [
        'show_price' => false,
        'show_quantity' => false,
        'allow_cart' => false,
        'allow_order' => false,
        'show_images' => true,
        'price_mode' => 'none',
    ];
}
$deferHomeProducts = false;
try {
    $sections = HomePageService::mergedSectionShells();
    $embeddedProductStrips = HomePageService::embeddedProductStrips();
    if ($embeddedProductStrips === []) {
        $embeddedProductStrips = HomePageService::productStripHtmlBySectionKey();
    }
} catch (Throwable $e) {
    error_log('index.php home sections: ' . $e->getMessage());
    $sections = [];
    $embeddedProductStrips = [];
}
try {
    $ads = SiteMediaService::listAdsForHome();
} catch (Throwable $e) {
    error_log('index.php ads: ' . $e->getMessage());
    $ads = [];
}
try {
    $homeCategories = HomeCategoryService::activeCategories();
} catch (Throwable $e) {
    error_log('index.php home categories: ' . $e->getMessage());
    $homeCategories = [];
}

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
$extraHead = portal_stylesheet('/css/home-page.css');
$extraFooter = portal_defer_script('/assets/home-page.js');
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = $storeCatalogDisplay['allow_cart'] ?? false;
require dirname(__DIR__) . '/views/layout.php';
