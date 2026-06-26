<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\StoreCatalogService;
use Portal\Support\StoreCartRequest;

require dirname(__DIR__) . '/views/helpers.php';

$cartNotice = StoreCartRequest::handleAddToCartPost();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
try {
    $catalog = StoreCatalogService::catalogFromRequest($_GET);
} catch (\Throwable $exception) {
    error_log('store.php catalog error: ' . $exception->getMessage());
    $catalog = [
        'products' => [],
        'totalCount' => 0,
        'page' => 1,
        'pageSize' => 24,
        'totalPages' => 1,
        'rangeStart' => 0,
        'rangeEnd' => 0,
        'resultFilters' => [],
        'filterOptions' => ['stores' => [], 'groups' => []],
        'apiError' => 'تعذر تحميل المتجر. تحقق من سياسة الوصول أو اتصال API.',
        'allow_client_filters' => false,
        'filters' => ['q' => '', 'sort' => 'number:asc'],
        'store_options' => [],
    ];
}
$displayOptions = StoreCatalogService::displayOptions();
$isCustomer = CustomerSession::check();

$isStoreAjaxNav = strtolower(trim((string) ($_SERVER['HTTP_X_STORE_NAV'] ?? ''))) === '1';
if ($isStoreAjaxNav) {
    header('Content-Type: text/html; charset=utf-8');
    ob_start();
    require dirname(__DIR__) . '/views/store-catalog.php';
    $html = ob_get_clean();
    if (preg_match('/<!-- store-catalog-fragment:start -->(.*)<!-- store-catalog-fragment:end -->/s', $html, $matches)) {
        echo $matches[1];
    }
    exit;
}

$extraHead = '<link href="' . h(portal_asset_url('/css/store-filters.css')) . '" rel="stylesheet">';
$enableQuickView = true;
$enableStoreCartJs = true;

ob_start();
require dirname(__DIR__) . '/views/store-catalog.php';
$content = ob_get_clean();
$title = 'المتجر';
require dirname(__DIR__) . '/views/layout.php';
