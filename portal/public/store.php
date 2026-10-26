<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\CatalogFilterViewService;
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

$storeLabMode = true;
$siteTour = 'store';
$extraHead = portal_stylesheet('/css/home-lab.css')
    . portal_stylesheet('/css/store-lab.css')
    . portal_stylesheet('/css/store-easy.css')
    . portal_stylesheet('/css/lab-access.css')
    . portal_stylesheet('/css/hn-search.css');
$extraFooter = portal_defer_script('/assets/product-share.js');
$enableQuickView = true;
$enableStoreCartJs = true;

ob_start();
require dirname(__DIR__) . '/views/store-easy.php';
$content = ob_get_clean();
$title = 'المتجر';
require dirname(__DIR__) . '/views/layout.php';
