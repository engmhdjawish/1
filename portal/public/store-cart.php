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
$error = null;
$notice = null;

if (!$allowCart) {
    $error = 'سياسة المتجر الحالية لا تسمح باستخدام السلة.';
}

$loggedInCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
$defaultGuestName = (string) ($loggedInCustomer['name_ar'] ?? '');
$defaultGuestPhone = (string) ($loggedInCustomer['phone'] ?? '');
$maxPackagesPerMaterial = StorePolicyService::maxPackagesPerMaterial();

$cartItems = array_values(StoreCartService::items());
$unavailableItems = array_values(StoreCartService::unavailableItems());
$totals = StoreCartService::totals();

ob_start();
require dirname(__DIR__) . '/views/store-cart.php';
$content = ob_get_clean();
$title = 'سلة المتجر';
$extraHead = '';
require dirname(__DIR__) . '/views/layout.php';
