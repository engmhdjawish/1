<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Portal\Support\DashboardNavigation;

$assert = static function (string $label, bool $ok): void {
    echo ($ok ? 'OK' : 'FAIL') . "  {$label}\n";
    if (!$ok) {
        exit(1);
    }
};

$nav = (string) file_get_contents($root . '/src/Support/DashboardNavigation.php');
$merchPhp = (string) file_get_contents($root . '/public/dashboard/merchandising.php');
$campaignsPhp = (string) file_get_contents($root . '/public/dashboard/special-offers-campaigns.php');
$campaignsView = (string) file_get_contents($root . '/views/dashboard/special-offers-campaigns.php');
$offersView = (string) file_get_contents($root . '/views/dashboard/special-offers.php');
$panel = (string) file_get_contents($root . '/views/dashboard/partials/offer-analytics-panel.php');

$assert('nav lists campaigns route', str_contains($nav, '/dashboard/special-offers-campaigns.php'));
$assert('campaigns live under merchandising area', str_contains($nav, 'MERCHANDISING_ROUTES') && str_contains($nav, 'AREA_MERCHANDISING'));
$assert('merchandising overview page exists', str_contains($merchPhp, 'merchandisingGroups'));
$assert('campaigns controller loads analytics service', str_contains($campaignsPhp, 'SpecialOfferAnalyticsService::summariesForOffers'));
$assert('campaigns view has progress bar', str_contains($campaignsView, 'so-campaign-progress'));
$assert('edit form links to campaigns', str_contains($offersView, 'special-offers-campaigns.php?offer='));
$assert('edit form removed inline analytics', !str_contains($offersView, 'قياس التفاعل والمبيعات') || str_contains($offersView, 'صفحة منفصلة'));
$assert('shared analytics panel partial', str_contains($panel, 'so-analytics'));

$user = ['permissions' => ['special_offers.manage']];
$groups = DashboardNavigation::merchandisingGroups($user);
$routes = [];
foreach ($groups as $items) {
    foreach ($items as $item) {
        $routes[] = (string) ($item['route'] ?? '');
    }
}
$assert('offers role sees campaigns in merchandising', in_array('/dashboard/special-offers-campaigns.php', $routes, true));
$assert('campaigns route maps to merchandising area', DashboardNavigation::areaForRoute('/dashboard/special-offers-campaigns.php') === DashboardNavigation::AREA_MERCHANDISING);
$assert('price checker maps to operations', DashboardNavigation::areaForRoute('/dashboard/price-checker.php') === DashboardNavigation::AREA_OPERATIONS);
$assert('view closure use clause is valid', !preg_match('/use\s*\([^)]*=\s*null/', $campaignsView));
$analyticsSrc = (string) file_get_contents($root . '/src/Services/SpecialOfferAnalyticsService.php');
$assert('analytics merges inferred sales', str_contains($analyticsSrc, 'mergeInferredSummaries'));
$assert('analytics lists recent orders', str_contains($analyticsSrc, 'recentOrdersForOffer'));
$orderSrc = (string) file_get_contents($root . '/src/Services/OrderService.php');
$assert('orders enrich cart lines before save', str_contains($orderSrc, 'enrichLineWithOffer'));

echo "All offer campaigns checks passed.\n";