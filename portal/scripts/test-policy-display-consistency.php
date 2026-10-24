<?php

declare(strict_types=1);

/**
 * Policies, share UI, and Amine admin permission consistency.
 * Usage: php scripts/test-policy-display-consistency.php
 */

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
require $root . '/views/helpers.php';

use Portal\Services\ProductDisplayService;
use Portal\Support\DashboardNavigation;
use Portal\Support\SharePageAccess;

$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "OK: {$label}\n";
        return;
    }
    echo "FAIL: {$label}\n";
    $failures++;
};

$hiddenStore = [
    'show_price' => false,
    'show_quantity' => false,
    'allow_cart' => false,
    'allow_order' => false,
    'show_images' => true,
    'price_mode' => 'none',
];
$homeWantsPrice = [
    'show_price' => true,
    'price_mode' => 'both',
    'show_images' => true,
];

$homeState = section_price_display_state($homeWantsPrice, $hiddenStore, 'home');
$assert('home sections hide prices when store policy hides them', $homeState['show_any_price'] === false);

$storeState = section_price_display_state($homeWantsPrice, $hiddenStore, 'store');
$assert('store sections hide prices when store policy hides them', $storeState['show_any_price'] === false);

$shownStore = array_merge($hiddenStore, ['show_price' => true, 'price_mode' => 'both']);
$homeShown = section_price_display_state($homeWantsPrice, $shownStore, 'home');
$assert('home can show prices when store policy allows', $homeShown['show_any_price'] === true);

$product = [
    'guid' => 'test-guid',
    'name' => 'مادة تجريبية',
    'warehouseQuantity' => 42.5,
    'unitSalePriceSyp' => 1000,
    'unitSalePriceUsd' => 1,
    'packageSalePriceSyp' => 12000,
    'packageSalePriceUsd' => 12,
    'packaging' => 12,
];
$qv = ProductDisplayService::quickViewPayload($product, [
    'show_price' => false,
    'show_quantity' => false,
    'allow_cart' => false,
    'show_images' => true,
    'price_mode' => 'both',
]);
$assert('quick-view zeros warehouseQty when quantity hidden', (float) ($qv['warehouseQty'] ?? -1) === 0.0);
$assert('quick-view gates showPriceSyp with showPrice', ($qv['showPriceSyp'] ?? true) === false);
$assert('quick-view gates showPriceUsd with showPrice', ($qv['showPriceUsd'] ?? true) === false);
$assert('quick-view zeros sale prices when hidden', (float) ($qv['packageSaleSp'] ?? -1) === 0.0);

$preview = product_preview_payload($product, [
    'show_price' => false,
    'show_quantity' => false,
    'allow_cart' => true,
    'price_mode' => 'both',
]);
$assert('product preview hides packagesAvailable when quantity hidden', (float) ($preview['packagesAvailable'] ?? -1) === 0.0);

$shareLink = [
    'show_price' => 0,
    'show_quantity' => 0,
    'allow_cart' => 1,
    'allow_order' => 0,
    'options' => ['show_images' => true, 'price_mode' => 'both'],
];
$shareDisplay = SharePageAccess::catalogDisplayOptions($shareLink, true);
$assert('share display_options respect link show_price=false', ($shareDisplay['show_price'] ?? true) === false);
$assert('share display_options price_mode none when price off', ($shareDisplay['price_mode'] ?? '') === 'none');

$contentUser = [
    'permissions' => [
        'dashboard.view',
        'home_sections.manage',
        'special_offers.manage',
        'price_checker.manage',
        'company_settings.manage',
        'site_media.manage',
    ],
];
$assert('content role is not super', DashboardNavigation::userCan($contentUser, '*') === false);
$assert('content role keeps company settings', DashboardNavigation::userCan($contentUser, 'company_settings.manage') === true);

$amineItem = [
    'route' => '/dashboard/amine-api.php',
    'label' => 'إدارة API الأمين',
    'permission' => '*',
];
$filtered = DashboardNavigation::filterItems($contentUser, [$amineItem]);
$assert('content role does not see Amine API nav item', $filtered === []);

$superUser = ['permissions' => ['*']];
$filteredSuper = DashboardNavigation::filterItems($superUser, [$amineItem]);
$assert('super admin still sees Amine API nav item', count($filteredSuper) === 1);

$aminePage = (string) file_get_contents($root . '/public/dashboard/amine-api.php');
$amineApi = (string) file_get_contents($root . '/public/dashboard/amine-api-api.php');
$superGate = "if (!\$isSuper)";
$assert('amine-api.php requires super only', str_contains($aminePage, $superGate) && !str_contains($aminePage, 'company_settings.manage'));
$assert('amine-api-api.php requires super only', str_contains($amineApi, $superGate) && !str_contains($amineApi, 'company_settings.manage'));

$sharePage = (string) file_get_contents($root . '/views/share-page.php');
$sharePartial = (string) file_get_contents($root . '/views/partials/store-share-catalog.php');
$storeEasy = (string) file_get_contents($root . '/views/store-easy.php');
$assert('share page renders store-easy', str_contains($sharePage, 'store-easy.php') && !str_contains($sharePage, 'store-catalog.php'));
$assert('store share browse renders store-easy', str_contains($sharePartial, 'store-easy.php') && !str_contains($sharePartial, 'store-catalog.php'));
$assert('store-easy accepts share context', str_contains($storeEasy, '$shareContext = is_array($shareContext') && str_contains($storeEasy, '$shareOnSharePage'));
$assert('store-easy uses share cart for dock when share token set', str_contains($storeEasy, 'ShareCartService::packageCount'));

$homePageService = (string) file_get_contents($root . '/src/Services/HomePageService.php');
$assert(
    'home product strips follow the viewer display policy, not guest-only',
    str_contains($homePageService, 'StoreCatalogService::displayOptions()')
    && !str_contains($homePageService, 'guestDisplayOptions()')
);
$assert(
    'home strip cache key fingerprints the active viewer policy',
    str_contains($homePageService, "'v' => 3")
    && str_contains($homePageService, 'StoreCatalogService::activePolicy()')
);

if ($failures > 0) {
    echo "\n{$failures} failure(s)\n";
    exit(1);
}

echo "\npolicy display consistency ok\n";
