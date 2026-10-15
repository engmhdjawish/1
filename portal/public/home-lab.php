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
try {
    $storeCatalogDisplay = StoreCatalogService::displayOptions();
} catch (Throwable $e) {
    error_log('home-lab.php displayOptions: ' . $e->getMessage());
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
    error_log('home-lab.php home sections: ' . $e->getMessage());
    $sections = [];
    $embeddedProductStrips = [];
}
try {
    $ads = SiteMediaService::listAdsForHome();
} catch (Throwable $e) {
    error_log('home-lab.php ads: ' . $e->getMessage());
    $ads = [];
}

$lcpPreloadUrl = !empty($companyLogoUrl)
    ? portal_site_logo_url((string) $companyLogoUrl, 'header')
    : null;

ob_start();
require dirname(__DIR__) . '/views/home-lab.php';
$content = ob_get_clean();
$title = 'تجربة الرئيسية';
$metaRobots = 'noindex, nofollow';
$canonicalUrl = portal_canonical_url('/index.php');
$metaDescription = 'نسخة تجريبية لتصميم الصفحة الرئيسية. الصفحة المعتمدة لم تتغيّر.';
$enableOnboarding = false;
$extraHead = portal_stylesheet('/css/home-page.css') . portal_stylesheet('/css/home-lab.css');
$extraFooter = portal_defer_script('/assets/home-page.js') . portal_defer_script('/assets/home-lab.js');
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = $storeCatalogDisplay['allow_cart'] ?? false;
require dirname(__DIR__) . '/views/layout.php';
