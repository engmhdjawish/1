<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\AmineAvailabilityService;
use Portal\Services\HomePageService;
use Portal\Services\StoreCatalogService;

require dirname(__DIR__) . '/views/helpers.php';

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

$stockQuery = $_GET;
unset($stockQuery['isAvailable'], $stockQuery['soon']);

try {
    $catalog = StoreCatalogService::catalogFromRequest($stockQuery);
} catch (Throwable $exception) {
    error_log('store-lab.php catalog: ' . $exception->getMessage());
    $catalog = $emptyCatalog;
    $catalog['apiError'] = AmineAvailabilityService::userMessage();
}
if (!is_array($catalog)) {
    $catalog = $emptyCatalog;
}
$separated = StoreCatalogService::separateUnavailableProducts($catalog);
$catalog = $separated['catalog'];
$strippedUnavailable = $separated['stripped'];

try {
    $comingSoon = StoreCatalogService::comingSoonFromRequest($_GET);
} catch (Throwable $exception) {
    error_log('store-lab.php coming soon: ' . $exception->getMessage());
    $comingSoon = [
        'products' => [],
        'totalCount' => 0,
        'page' => 1,
        'pageSize' => 12,
        'totalPages' => 1,
        'apiError' => AmineAvailabilityService::userMessage(),
    ];
}
if (($comingSoon['apiError'] ?? null) && $strippedUnavailable !== [] && ($comingSoon['products'] ?? []) === []) {
    $comingSoon['products'] = $strippedUnavailable;
    $comingSoon['totalCount'] = count($strippedUnavailable);
    $comingSoon['page'] = 1;
    $comingSoon['totalPages'] = 1;
    $comingSoon['apiError'] = null;
}

$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
try {
    $displayOptions = StoreCatalogService::storePageDisplayOptions($sectionContext);
} catch (Throwable $exception) {
    error_log('store-lab.php display: ' . $exception->getMessage());
    $displayOptions = [
        'show_price' => false,
        'show_quantity' => false,
        'allow_cart' => false,
        'allow_order' => false,
        'show_images' => true,
        'price_mode' => 'none',
    ];
}

try {
    $discoverSections = HomePageService::mergedSectionShells();
} catch (Throwable $exception) {
    error_log('store-lab.php sections: ' . $exception->getMessage());
    $discoverSections = [];
}

$isCustomer = false;
try {
    $isCustomer = CustomerSession::check();
} catch (Throwable) {
    $isCustomer = false;
}

ob_start();
require dirname(__DIR__) . '/views/store-lab.php';
$content = ob_get_clean();
$title = 'تجربة المتجر';
$metaRobots = 'noindex, nofollow';
$canonicalUrl = portal_canonical_url('/store.php');
$metaDescription = 'نسخة تجريبية للمتجر. المتجر المعتمد لم يتغيّر، والمواد غير المتوفرة تظهر هنا في قسم يتوفر قريباً.';
$enableOnboarding = false;
$extraHead = portal_stylesheet('/css/home-page.css')
    . portal_stylesheet('/css/home-lab.css')
    . portal_stylesheet('/css/store-lab.css');
$extraFooter = portal_defer_script('/assets/home-lab.js');
$enableQuickView = false;
$enableStoreCartJs = false;
$deferStoreCartJs = (bool) ($displayOptions['allow_cart'] ?? false);
require dirname(__DIR__) . '/views/layout.php';
