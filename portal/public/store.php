<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\StoreCatalogService;
use Portal\Support\StoreCartRequest;

require dirname(__DIR__) . '/views/helpers.php';

$cartNotice = StoreCartRequest::handleAddToCartPost();
$catalog = StoreCatalogService::catalogFromRequest($_GET);
$displayOptions = StoreCatalogService::displayOptions();
$isCustomer = CustomerSession::check();

$extraHead = '<link href="' . h(portal_asset_url('/css/store-filters.css')) . '" rel="stylesheet">';
$enableQuickView = true;
$enableStoreCartJs = true;

ob_start();
require dirname(__DIR__) . '/views/store-catalog.php';
$content = ob_get_clean();
$title = 'المتجر';
require dirname(__DIR__) . '/views/layout.php';
