<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\HomeSectionService;
use Portal\Services\PortalSettingsService;
use Portal\Services\SiteMediaService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StoreCatalogService;

require dirname(__DIR__) . '/views/helpers.php';

$companyContext = PortalSettingsService::companySettings();
$companyLogoUrl = PortalSettingsService::companyLogoUrl($companyContext);
$storeCatalogDisplay = StoreCatalogService::displayOptions();

$sections = HomeSectionService::activeSections();
foreach ($sections as &$section) {
    $section['_sort'] = (int) ($section['sort_order'] ?? 0);
}
unset($section);

$offerSections = SpecialOfferService::activeHomeSections();
foreach ($offerSections as &$section) {
    $section['_sort'] = (int) ($section['home_sort_order'] ?? 0);
}
unset($section);

$sections = array_merge($sections, $offerSections);
usort($sections, static fn (array $a, array $b): int => ($a['_sort'] ?? 0) <=> ($b['_sort'] ?? 0));

$ads = SiteMediaService::listAdsForHome();

ob_start();
require dirname(__DIR__) . '/views/home.php';
$content = ob_get_clean();
$title = 'الرئيسية';
$extraHead = '<link href="' . h(portal_asset_url('/css/home-page.css')) . '" rel="stylesheet">';
$extraFooter = '<script src="' . h(portal_asset_url('/assets/home-page.js')) . '" defer></script>';
$enableQuickView = false;
require dirname(__DIR__) . '/views/layout.php';
