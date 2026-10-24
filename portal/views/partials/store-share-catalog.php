<?php

declare(strict_types=1);

/**
 * Store page for share-link visitors: same easy UI as the public store,
 * with the share cart and the share link's own display policy.
 *
 * @var array<string, mixed> $shareBrowse
 */

use Portal\Auth\CustomerSession;
use Portal\Services\StoreCatalogService;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$shareToken = trim((string) ($shareBrowse['token'] ?? ''));
$shareLink = is_array($shareBrowse['share_link'] ?? null) ? $shareBrowse['share_link'] : [];
$shareLinkId = trim((string) ($shareBrowse['share_link_id'] ?? ($shareLink['id'] ?? '')));
$shareContext = [
    'token' => $shareToken,
    'share_link_id' => $shareLinkId,
    'name_ar' => (string) ($shareLink['name_ar'] ?? 'رابط مشاركة'),
    'access_policy_name_ar' => (string) ($shareLink['access_policy_name_ar'] ?? ''),
    'has_access' => true,
];

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
    // Same mixed catalog as the public store so share visitors see the same UI and assortment.
    $catalog = StoreCatalogService::labCatalogFromRequest($labQuery);
} catch (Throwable $exception) {
    error_log('store-share-catalog: ' . $exception->getMessage());
    $catalog = $emptyCatalog;
    $catalog['apiError'] = \Portal\Services\AmineAvailabilityService::userMessage();
}
if (!is_array($catalog)) {
    $catalog = $emptyCatalog;
}

unset($_GET['soon'], $_GET['storeGuids'], $_GET['minWarehouseQuantity'], $_GET['maxWarehouseQuantity']);

$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
$displayOptions = is_array($shareBrowse['display_options'] ?? null)
    ? $shareBrowse['display_options']
    : [
        'show_price' => false,
        'show_quantity' => false,
        'allow_cart' => false,
        'allow_order' => false,
        'show_images' => true,
        'price_mode' => 'none',
    ];
if ($sectionContext !== null && is_array($sectionContext['display_options'] ?? null)) {
    if (!function_exists('section_catalog_display_options')) {
        require dirname(__DIR__) . '/helpers.php';
    }
    $displayOptions = section_catalog_display_options($sectionContext['display_options'], $displayOptions);
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
require dirname(__DIR__) . '/store-easy.php';
$content = ob_get_clean();
$title = (string) ($shareContext['name_ar'] ?? 'المتجر');
require dirname(__DIR__) . '/layout.php';
