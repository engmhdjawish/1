<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Services\CatalogFilterViewService;
use Portal\Services\MaterialFilterOptionsService;
use Portal\Services\ShareCartService;
use Portal\Services\StoreCatalogService;
use Portal\Support\SharePageAccess;

require dirname(__DIR__) . '/views/helpers.php';

$_GET = CatalogFilterViewService::expandRequest($_GET);

$shareBrowse = SharePageAccess::resolveShareBrowseContext(null, null);
if ($shareBrowse !== null) {
    ShareCartService::rememberActiveToken($shareBrowse['token']);
    $shareStoreAllowCart = (bool) ($shareBrowse['display_options']['allow_cart'] ?? false);
    if ($shareStoreAllowCart) {
        $storeAllowCart = true;
        $storeCartCount = ShareCartService::itemCount($shareBrowse['token']);
        $storeCartPackageCount = ShareCartService::packageCount($shareBrowse['token']);
        $storeCartBootstrap = array_merge(ShareCartService::bootstrapPayload($shareBrowse['token']), [
            'share_token' => $shareBrowse['token'],
        ]);
    }
    require dirname(__DIR__) . '/views/partials/store-share-catalog.php';
    exit;
}

ShareCartService::clearActiveToken();

$canStaffDownloadMaterialZip = false;
$storeMaterialZipSeed = [];
$materialFilterOptions = [
    'materialTypes' => [],
    'ageCategories' => [],
    'manufacturers' => [],
    'sizeRanges' => [],
    'countryOfOrigins' => [],
    'stores' => [],
    'groups' => [],
];
$materialFilterOptionsError = null;

if (WebSession::check() && WebSession::hasAnyPermission(['images.view', 'images.upload', 'orders.view'])) {
    $canStaffDownloadMaterialZip = true;
    $filterFetch = MaterialFilterOptionsService::fetchFromApi();
    $materialFilterOptions = $filterFetch['options'];
    $materialFilterOptionsError = $filterFetch['error'];
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$emptyCatalog = [
    'products' => [],
    'totalCount' => 0,
    'page' => 1,
    'pageSize' => 24,
    'totalPages' => 1,
    'rangeStart' => 0,
    'rangeEnd' => 0,
    'resultFilters' => [],
    'filterOptions' => ['stores' => [], 'groups' => []],
    'apiError' => null,
    'allow_client_filters' => false,
    'filters' => ['q' => trim((string) ($_GET['q'] ?? '')), 'sort' => 'number:asc'],
    'store_options' => [],
    'section_context' => null,
    'locked_client_filters' => [],
    'filters_deferred' => false,
    'client_filters_payload' => null,
];

$labQuery = $_GET;
unset($labQuery['soon'], $labQuery['storeGuids'], $labQuery['minWarehouseQuantity'], $labQuery['maxWarehouseQuantity']);

try {
    $catalog = StoreCatalogService::labCatalogFromRequest($labQuery);
} catch (Throwable $exception) {
    error_log('store.php catalog: ' . $exception->getMessage());
    $catalog = $emptyCatalog;
    $catalog['apiError'] = AmineAvailabilityService::userMessage();
}
if (!is_array($catalog)) {
    $catalog = $emptyCatalog;
}

unset($_GET['soon'], $_GET['storeGuids'], $_GET['minWarehouseQuantity'], $_GET['maxWarehouseQuantity']);

$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
try {
    $displayOptions = StoreCatalogService::storePageDisplayOptions($sectionContext);
} catch (Throwable $exception) {
    error_log('store.php display: ' . $exception->getMessage());
    $displayOptions = [
        'show_price' => false,
        'show_quantity' => false,
        'allow_cart' => false,
        'allow_order' => false,
        'show_images' => true,
        'price_mode' => 'none',
    ];
}

$isCustomer = false;
try {
    $isCustomer = CustomerSession::check();
} catch (Throwable) {
    $isCustomer = false;
}

if ($canStaffDownloadMaterialZip) {
    $catalogFilters = is_array($catalog['filters'] ?? null) ? $catalog['filters'] : [];
    $storeMaterialZipSeed = [
        'search' => trim((string) ($catalogFilters['q'] ?? '')),
    ];
    if (($catalogFilters['isAvailable'] ?? null) === true) {
        $storeMaterialZipSeed['isAvailable'] = '1';
    } elseif (($catalogFilters['isAvailable'] ?? null) === false) {
        $storeMaterialZipSeed['isAvailable'] = '0';
    } else {
        $storeMaterialZipSeed['isAvailable'] = '';
    }
    foreach (['materialTypes', 'ageCategories', 'manufacturers', 'sizeRanges', 'countryOfOrigins'] as $facetKey) {
        $values = $catalogFilters[$facetKey] ?? [];
        if (is_array($values) && $values !== []) {
            $storeMaterialZipSeed[$facetKey] = array_values(array_map('strval', $values));
        }
    }
}

$storeLabMode = true;
$siteTour = 'store';
$extraHead = portal_stylesheet('/css/home-lab.css')
    . portal_stylesheet('/css/store-lab.css')
    . portal_stylesheet('/css/store-easy.css')
    . portal_stylesheet('/css/lab-access.css')
    . portal_stylesheet('/css/hn-search.css');
if ($canStaffDownloadMaterialZip) {
    $extraHead .= portal_stylesheet('/css/store-filters.css');
}
$extraFooter = portal_defer_script('/assets/product-share.js');
if ($canStaffDownloadMaterialZip) {
    $extraFooter .= portal_defer_script('/assets/store-filters.js')
        . portal_defer_script('/assets/dashboard/material-image-zip-download.js')
        . portal_defer_script('/assets/store-staff-zip-download.js');
}
$enableQuickView = true;
$enableStoreCartJs = true;

ob_start();
require dirname(__DIR__) . '/views/store-easy.php';
$content = ob_get_clean();
$title = 'المتجر';
require dirname(__DIR__) . '/views/layout.php';
