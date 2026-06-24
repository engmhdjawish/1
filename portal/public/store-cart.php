<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;

require dirname(__DIR__) . '/views/helpers.php';

$display = StoreCatalogService::displayOptions();
$allowCart = (bool) ($display['allow_cart'] ?? false);
$allowOrder = (bool) ($display['allow_order'] ?? false);
$showPrice = (bool) ($display['show_price'] ?? false);
$priceMode = (string) ($display['price_mode'] ?? 'syp');
$showPriceSyp = $showPrice && in_array($priceMode, ['both', 'syp'], true);
$showPriceUsd = $showPrice && in_array($priceMode, ['both', 'usd'], true);
$error = null;
$notice = null;

if (!$allowCart) {
    $error = 'سياسة المتجر الحالية لا تسمح باستخدام السلة.';
}

$loggedInCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
$isLoggedInCustomer = $loggedInCustomer !== null;
$defaultGuestName = (string) ($loggedInCustomer['name_ar'] ?? '');
$defaultGuestPhone = (string) ($loggedInCustomer['phone'] ?? '');
$maxPackagesPerMaterial = StorePolicyService::maxPackagesPerMaterial();

$stockNotices = [];
if ($allowCart) {
    $reconcile = StoreCartService::reconcileStock();
    $stockNotices = is_array($reconcile['notices'] ?? null) ? $reconcile['notices'] : [];
    if ($stockNotices !== [] && $notice === null) {
        $notice = implode(' ', array_values(array_unique(array_filter($stockNotices, static fn (string $n): bool => trim($n) !== ''))));
    }
}

$cartItems = StoreCartService::enrichedItems();
$unavailableItems = array_values(StoreCartService::unavailableItems());
$totals = StoreCartService::totals();

ob_start();
require dirname(__DIR__) . '/views/store-cart.php';
$content = ob_get_clean();
$title = 'سلة المتجر';
$extraHead = '';
require dirname(__DIR__) . '/views/layout.php';
