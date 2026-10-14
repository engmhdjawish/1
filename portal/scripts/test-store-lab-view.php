<?php

declare(strict_types=1);

/**
 * Trial store view: no hero, live filter box, hidden warehouses, coming-soon section.
 * Usage: php scripts/test-store-lab-view.php
 */

define('PORTAL_NO_SESSION', true);
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/store-lab.php';
$_GET = ['q' => 'حذاء'];

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$render = static function (bool $showPrice, bool $labMode = true): string {
    $stock = [[
            'materialGuid' => 'stock-1',
            'name' => 'حذاء متوفر',
            'materialCode' => 'ST-1',
            'materialType' => 'رجالي',
            'manufacturer' => 'جاويش',
            'sizeRange' => '40-45',
            'unitSalePriceSyp' => 1500,
            'unitSalePriceUsd' => 0,
            'warehouseQuantity' => 4,
            'has_offer' => true,
            'effective_unit_sale_price_sp' => 1500,
            'original_unit_sale_price_sp' => 1500,
        ], [
            'materialGuid' => 'stock-2',
            'name' => 'حذاء بلا سعر',
            'materialCode' => 'ST-2',
            'materialType' => 'رجالي',
            'unitSalePriceSyp' => null,
            'unitSalePriceUsd' => 0,
            'warehouseQuantity' => 2,
            'has_offer' => true,
            'effective_unit_sale_price_sp' => 0,
            'original_unit_sale_price_sp' => 0,
        ]];
    $soonItem = [
        'materialGuid' => 'soon-1',
        'name' => 'حذاء شتوي',
        'materialCode' => 'SO-1',
        'materialType' => 'شتوي',
        'warehouseQuantity' => 0,
        'unitSalePriceSyp' => '',
        'unitSalePriceUsd' => null,
        'has_offer' => true,
        'effective_unit_sale_price_sp' => 0,
        'original_unit_sale_price_sp' => 0,
    ];
    $catalog = [
        'products' => $stock,
        'totalCount' => 2,
        'page' => 1,
        'pageSize' => 24,
        'totalPages' => 1,
        'resultFilters' => [
            'materialTypes' => [['value' => 'رجالي', 'count' => 2]],
            'manufacturers' => [['value' => 'جاويش', 'count' => 1]],
        ],
        'filterOptions' => [
            'stores' => [
                ['guid' => 'wh-1', 'name' => 'مستودع الستوك', 'code' => 'MAIN'],
                ['guid' => 'wh-2', 'name' => 'المستودع الرئيسي', 'code' => 'HQ'],
            ],
            'groups' => [
                ['guid' => 'g-1', 'name' => 'أحذية'],
            ],
        ],
        'apiError' => null,
        'allow_client_filters' => true,
        'filters' => [
            'q' => 'حذاء',
            'sort' => 'number:asc',
            'materialTypes' => ['رجالي'],
            'manufacturers' => [],
            'ageCategories' => [],
            'sizeRanges' => [],
            'countryOfOrigins' => [],
            'storeGuids' => ['wh-1'],
            'groupGuids' => [],
            'groupBy' => 'none',
            'isAvailable' => null,
            'minWarehouseQuantity' => 5,
            'maxWarehouseQuantity' => 20,
        ],
        'store_options' => [
            'allow_sorting' => true,
            'client_sort_fields' => ['number', 'materialType', 'manufacturer'],
            'visible_client_filters' => [
                'search',
                'materialTypes',
                'manufacturers',
                'availability',
                'stores',
                'warehouseRange',
                'groups',
                'sort',
                'groupBy',
            ],
        ],
        'section_context' => null,
        'locked_client_filters' => [],
        'filters_deferred' => true,
        'client_filters_payload' => [
            'filterOptions' => [
                'stores' => [
                    ['guid' => 'wh-1', 'name' => 'مستودع الستوك'],
                ],
                'groups' => [],
            ],
            'resultFilters' => [],
        ],
    ];
    $displayOptions = [
        'show_price' => $showPrice,
        'show_quantity' => false,
        'allow_cart' => false,
        'allow_order' => false,
        'show_images' => true,
        'price_mode' => 'both',
    ];
    $isCustomer = false;
    $storeLabMode = $labMode;
    $comingSoon = [
        'products' => [$soonItem],
        'totalCount' => 1,
        'page' => 1,
        'pageSize' => 12,
        'totalPages' => 1,
        'apiError' => null,
    ];
    $storeCatalogImagePreview = false;

    ob_start();
    require dirname(__DIR__) . '/views/store-catalog.php';

    return (string) ob_get_clean();
};

$html = $render(true);
$locked = $render(false);
$live = $render(true, false);

$assert('no marketing hero', !str_contains($html, 'home-hero') && !str_contains($html, 'sl-hero'));
$assert('live filter sidebar is present', str_contains($html, 'store-filters-sidebar') && str_contains($html, 'تصفية النتائج'));
$assert('filter form stays on the trial route', str_contains($html, 'action="/store-lab.php"'));
$assert('original filter group markup is used', str_contains($html, 'store-filter-accordion') && str_contains($html, 'name="materialTypes[]"'));
$assert('warehouse names are not visible', !str_contains($html, 'مستودع الستوك') && !str_contains($html, 'المستودع الرئيسي') && !str_contains($html, 'المخازن') && !str_contains($html, 'المستودعات'));
$assert('warehouse controls are not rendered', !str_contains($html, 'data-filter-group="stores"') && !str_contains($html, 'data-filter-group="warehouse"') && !str_contains($html, 'name="storeGuids') && !str_contains($html, 'name="minWarehouseQuantity"') && !str_contains($html, 'wh-1'));
$soonAt = strpos($html, 'id="coming-soon"');
$assert('coming-soon section sits under the in-stock grid', $soonAt !== false && strpos($html, 'حذاء متوفر') < $soonAt && strpos($html, 'حذاء بلا سعر') < $soonAt && strpos($html, 'حذاء شتوي') > $soonAt);
$assert('coming-soon products are grouped', str_contains($html, 'sl-soon__group') && str_contains($html, 'شتوي'));
$assert('search hint names coming soon', str_contains($html, 'يشمل البحث المتوفر وما يتوفر قريبا'));
$assert('no coaching copy', !str_contains($html, 'اكتب جزءاً') && !str_contains($html, 'جرّب') && !str_contains($html, 'تُباع لدينا') && !str_contains($html, 'ضيّق النتائج') && !str_contains($html, 'id="available"'));
$assert('live store still renders the warehouse filter', str_contains($live, 'المخازن') && str_contains($live, 'data-filter-group="stores"') && str_contains($live, 'مستودع الستوك') && !str_contains($live, 'id="coming-soon"') && !str_contains($live, 'action="/store-lab.php"'));
$cardSlice = static function (string $page, string $name): string {
    $start = strpos($page, $name);
    if ($start === false) {
        return '';
    }
    $open = strrpos(substr($page, 0, $start), '<article');
    $close = strpos($page, '</article>', $start);
    if ($open === false || $close === false) {
        return '';
    }

    return substr($page, $open, $close - $open);
};
$pricedCard = $cardSlice($html, 'حذاء متوفر');
$zeroCard = $cardSlice($html, 'حذاء بلا سعر');
$soonCard = $cardSlice($html, 'حذاء شتوي');
$assert('listed price is shown', str_contains($pricedCard, 'offer-price-block'));
$assert('zero price is omitted', $zeroCard !== '' && !str_contains($zeroCard, 'offer-price-block') && !str_contains($zeroCard, 'store-price-veil'));
$assert('guest price lock remains available', str_contains($locked, 'store-price-veil'));
$assert('coming soon card has a badge and no cart form', str_contains($soonCard, 'يتوفر قريبا') && !str_contains($soonCard, 'data-store-add-cart') && !str_contains($html, 'data-store-add-cart') && !str_contains($html, 'store-add-cart'));

$out = $argv[1] ?? '';
if ($out !== '') {
    $document = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="/css/site-app.min.css">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Material+Symbols+Outlined&display=swap">'
        . '<link rel="stylesheet" href="/css/home-page.css">'
        . '<link rel="stylesheet" href="/css/home-lab.css">'
        . '<link rel="stylesheet" href="/css/store-filters.css">'
        . '<link rel="stylesheet" href="/css/store-lab.css">'
        . '<style>body{font-family:Manrope,system-ui,sans-serif;background:#f6f6f8;color:#111827;margin:0}main{max-width:80rem;margin:0 auto;padding:1.5rem 1rem}</style>'
        . '</head><body><main>' . $html . '</main>'
        . '<script src="/assets/store-filters.js" defer></script>'
        . '<script>if (location.hash === "#open-filters") { document.getElementById("store-filters-backdrop")?.classList.add("is-open"); }</script>'
        . '</body></html>';
    if (file_put_contents($out, $document) === false) {
        fwrite(STDERR, "could not write {$out}\n");
        exit(1);
    }
    echo "wrote {$out}\n";
}

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "store lab view ok\n";
