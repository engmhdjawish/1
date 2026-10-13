<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\StoreCatalogService;

require dirname(__DIR__) . '/views/helpers.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $catalog = StoreCatalogService::catalogForAvailabilityDemo($_GET);
} catch (\Throwable $exception) {
    error_log('store-availability-demo.php: ' . $exception->getMessage());
    $catalog = [
        'products' => [],
        'totalCount' => 0,
        'page' => 1,
        'pageSize' => 24,
        'totalPages' => 1,
        'rangeStart' => 0,
        'rangeEnd' => 0,
        'apiError' => $exception->getMessage(),
        'demo_view' => trim((string) ($_GET['view'] ?? 'all')),
        'demo_stats' => ['available_on_page' => 0, 'unavailable_on_page' => 0],
    ];
}

$displayOptions = StoreCatalogService::displayOptions();
$extraHead = portal_stylesheet_tag('/css/store-ui.css')
    . portal_stylesheet_tag('/css/store-availability-demo.css');

ob_start();
require dirname(__DIR__) . '/views/store-availability-demo.php';
$content = ob_get_clean();
$title = 'معاينة — توفر المواد';
$enableQuickView = false;
$enableStoreCartJs = false;
require dirname(__DIR__) . '/views/layout.php';
