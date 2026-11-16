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
$campaignsPhp = (string) file_get_contents($root . '/public/dashboard/special-offers-campaigns.php');
$campaignsView = (string) file_get_contents($root . '/views/dashboard/special-offers-campaigns.php');
$offersView = (string) file_get_contents($root . '/views/dashboard/special-offers.php');
$panel = (string) file_get_contents($root . '/views/dashboard/partials/offer-analytics-panel.php');

$assert('nav lists campaigns route', str_contains($nav, '/dashboard/special-offers-campaigns.php'));
$assert('campaigns controller loads analytics service', str_contains($campaignsPhp, 'SpecialOfferAnalyticsService::summariesForOffers'));
$assert('campaigns view has progress bar', str_contains($campaignsView, 'so-campaign-progress'));
$assert('edit form links to campaigns', str_contains($offersView, 'special-offers-campaigns.php?offer='));
$assert('edit form removed inline analytics', !str_contains($offersView, 'قياس التفاعل والمبيعات') || str_contains($offersView, 'صفحة منفصلة'));
$assert('shared analytics panel partial', str_contains($panel, 'so-analytics'));

$user = ['permissions' => ['special_offers.manage']];
$items = DashboardNavigation::filterItems($user, DashboardNavigation::items()['إدارة المحتوى'] ?? []);
$routes = array_column($items, 'route');
$assert('content role sees campaigns nav', in_array('/dashboard/special-offers-campaigns.php', $routes, true));

echo "All offer campaigns checks passed.\n";