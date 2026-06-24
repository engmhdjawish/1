<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\HomeSectionService;
use Portal\Services\SiteMediaService;
use Portal\Services\SpecialOfferService;

require dirname(__DIR__) . '/views/helpers.php';

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
$previewScript = '<script src="' . h(portal_asset_url('/assets/store-product-preview.js')) . '" defer></script>';
$extraFooter = '<script src="' . h(portal_asset_url('/assets/home-page.js')) . '" defer></script>' . $previewScript;
$enableQuickView = false;
require dirname(__DIR__) . '/views/layout.php';
