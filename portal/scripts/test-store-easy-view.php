<?php

declare(strict_types=1);

/**
 * Easy trial store: category tiles, one filter bar, chips, one grid, unavailable last, no warehouses.
 * Usage: php scripts/test-store-easy-view.php [out.html]
 */

define('PORTAL_NO_SESSION', true);
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/store.php';
$_GET = ['q' => 'حذاء', 'ageCategories' => ['رجالي'], 'sizeRanges' => ['40-45']];

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

$fixtureProducts = [[
    'materialGuid' => 'stock-1',
    'name' => 'حذاء متوفر',
    'materialCode' => 'ST-1',
    'materialType' => 'رياضي',
    'ageCategory' => 'رجالي',
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
    'materialType' => 'رياضي',
    'ageCategory' => 'رجالي',
    'sizeRange' => '40-45',
    'unitSalePriceSyp' => null,
    'unitSalePriceUsd' => 0,
    'warehouseQuantity' => 2,
    'has_offer' => true,
    'effective_unit_sale_price_sp' => 0,
    'original_unit_sale_price_sp' => 0,
], [
    'materialGuid' => 'soon-1',
    'name' => 'حذاء شتوي',
    'materialCode' => 'SO-1',
    'materialType' => 'شتوي',
    'ageCategory' => 'رجالي',
    'sizeRange' => '40-45',
    'warehouseQuantity' => 0,
    'unitSalePriceSyp' => '',
    'unitSalePriceUsd' => null,
    'has_offer' => true,
    'effective_unit_sale_price_sp' => 0,
    'original_unit_sale_price_sp' => 0,
]];

$render = static function (array $products, bool $showPrice = true, int $totalPages = 1) use ($fixtureProducts): string {
    $catalog = [
        'products' => $products,
        'totalCount' => $totalPages > 1 ? 60 : count($products),
        'page' => 1,
        'pageSize' => 24,
        'totalPages' => $totalPages,
        'rangeStart' => $products === [] ? 0 : 1,
        'rangeEnd' => count($products),
        'resultFilters' => [
            'materialTypes' => [['value' => 'رياضي', 'count' => 2], ['value' => 'شتوي', 'count' => 1]],
            'manufacturers' => [['value' => 'جاويش', 'count' => 1]],
            'sizeRanges' => [['value' => '40-45', 'count' => 3], ['value' => '36-39', 'count' => 5]],
        ],
        'filterOptions' => [
            'stores' => [['guid' => 'wh-1', 'name' => 'مستودع الستوك']],
            'groups' => [],
            'ageCategories' => ['رجالي', 'نسائي', 'ولادي', 'بناتي'],
        ],
        'apiError' => null,
        'allow_client_filters' => true,
        'filters' => [
            'q' => 'حذاء',
            'sort' => 'number:asc',
            'materialTypes' => [],
            'manufacturers' => [],
            'ageCategories' => ['رجالي'],
            'sizeRanges' => ['40-45'],
            'countryOfOrigins' => [],
            'storeGuids' => ['wh-1'],
            'groupGuids' => [],
            'isAvailable' => null,
            'hasImage' => null,
        ],
        'store_options' => [
            'allow_sorting' => true,
            'client_sort_fields' => ['number', 'materialType', 'unitSalePriceSyp'],
            'visible_client_filters' => [
                'search', 'materialTypes', 'manufacturers', 'ageCategories', 'sizeRanges',
                'availability', 'stores', 'warehouseRange', 'sort',
            ],
        ],
        'section_context' => null,
        'locked_client_filters' => [],
        'filters_deferred' => false,
        'client_filters_payload' => null,
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
    $storeCatalogImagePreview = false;
    unset($GLOBALS['storeCatalogPreviewRendered']);

    ob_start();
    require dirname(__DIR__) . '/views/store-easy.php';

    return (string) ob_get_clean();
};

$html = $render($fixtureProducts);
$empty = $render([]);
$locked = $render($fixtureProducts, false);
$paged = $render($fixtureProducts, true, 3);

$assert('home search box sits first', str_contains($html, 'data-hn-search') && str_contains($html, 'data-store-search') && str_contains($html, 'hn-search__go') && strpos($html, 'se-search') < strpos($html, 'se-shelf'));
$assert('one search field', substr_count($html, 'data-hl-search-input') === 1 && !str_contains($html, 'id="store-search-q"') && !str_contains($html, 'store-mobile-search'));
$assert('category tiles with all tile', str_contains($html, 'تسوّق حسب الفئة') && str_contains($html, '>الكل<') && str_contains($html, 'نسائي') && str_contains($html, 'بناتي'));
$assert('active category tile is marked', (bool) preg_match('/class="se-tile is-active"[^>]*aria-current="true"[^>]*>\s*<span[^>]*>man<\/span>\s*<span class="se-tile__label">رجالي/u', $html));
$assert('tile links go to the store', str_contains($html, 'href="/store.php?') && !str_contains($html, '/store-lab.php'));
$assert('tile facet is not repeated as a pill', !str_contains($html, 'name="ageCategories[]" value="نسائي"') && str_contains($html, 'type="hidden" name="ageCategories[]" value="رجالي"'));
$assert('filter pills render', str_contains($html, 'data-se-pill') && str_contains($html, 'data-se-param="materialTypes"') && str_contains($html, '>النوع<') && str_contains($html, '>الشركة<') && str_contains($html, 'data-se-apply'));
$assert('sizes are one-tap chips, not a pill', str_contains($html, 'class="se-sizes"') && !str_contains($html, 'data-se-param="sizeRanges"') && !str_contains($html, 'type="checkbox" name="sizeRanges[]"'));
$assert('sizes sort naturally and mark the active one', strpos($html, '>36-39<') < strpos($html, '>40-45<') && (bool) preg_match('/class="se-size is-active"\s+aria-pressed="true"\s*>40-45</u', $html));
$assert('tapping an active size removes it', (bool) preg_match('/href="([^"]+)"\s+class="se-size is-active"/u', $html, $activeSize) && !str_contains(html_entity_decode($activeSize[1]), '40-45'));
$assert('tapping another size adds it', (bool) preg_match('/href="([^"]+)"\s+class="se-size"\s/u', $html, $otherSize) && str_contains(html_entity_decode(urldecode($otherSize[1])), '36-39') && str_contains(html_entity_decode(urldecode($otherSize[1])), '40-45'));
$assert('form keeps the chosen size', str_contains($html, 'type="hidden" name="sizeRanges[]" value="40-45"'));
$assert('availability toggle and sort select', str_contains($html, 'name="isAvailable" value="1"') && str_contains($html, '<select name="sort"') && str_contains($html, 'السعر من الأعلى ل.س'));
$assert('chips remove filters', str_contains($html, 'class="se-chip"') && str_contains($html, 'مسح الكل') && str_contains($html, 'إزالة 40-45'));
$assert('no old sidebar or mobile filter drawer', !str_contains($html, 'store-filters-sidebar') && !str_contains($html, 'store-filters-open-btn') && !str_contains($html, 'تصفية النتائج'));
$assert('no warehouses', !str_contains($html, 'مستودع') && !str_contains($html, 'storeGuids') && !str_contains($html, 'المخازن') && !str_contains($html, 'wh-1'));
$assert('no marketing hero', !str_contains($html, 'home-hero'));
$assert('one continuous grid, unavailable last', substr_count($html, 'store-product-grid') === 1 && strpos($html, 'حذاء متوفر') < strpos($html, 'حذاء بلا سعر') && strpos($html, 'حذاء بلا سعر') < strpos($html, 'حذاء شتوي'));
$assert('unavailable band shown', str_contains($html, 'store-soon-band') && str_contains($html, 'غير متوفر حالياً'));
$assert('result count line', str_contains($html, '<strong>3</strong> مادة'));
$assert('guest price lock remains', str_contains($locked, 'store-price-veil'));
$assert('no hint copy', !str_contains($html, 'جرّب') && !str_contains($html, 'اكتب') && !str_contains($html, 'اختر ') && !str_contains($html, 'ضيّق'));
$assert('empty state is short with clear all', str_contains($empty, 'لا توجد مواد مطابقة') && str_contains($empty, 'se-empty') && !str_contains($empty, 'store-product-grid'));
$assert('results sit in one live region', substr_count($html, 'data-se-live') === 1 && str_contains($html, 'data-se-total="3"') && strpos($html, 'data-hl-search-input') < strpos($html, 'data-se-live'));
$assert('view switch', str_contains($html, 'data-se-density-set="2"') && !str_contains($html, 'data-se-top'));
$assert('no load more on a single page', !str_contains($html, 'data-se-more'));
$assert('paged results use page numbers, not load more', !str_contains($paged, 'data-se-more') && !str_contains($paged, 'عرض المزيد') && str_contains($paged, 'store-pagination') && str_contains($paged, 'page=2'));
$assert('assets wired', str_contains($html, '/assets/store-easy.js') && str_contains($html, '/assets/hn-search.js') && !str_contains($html, '/assets/home-lab.js'));

$icons = [
    'رجالي' => 'man', 'نسائي' => 'woman', 'ولادي' => 'boy', 'بناتي' => 'girl', 'أطفال' => 'child_care', 'أخرى' => 'category',
];
foreach ($icons as $label => $icon) {
    $assert("icon for {$label}", store_easy_tile_icon($label) === $icon);
}

$out = $argv[1] ?? '';
if ($out !== '') {
    $document = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="/css/site-app.min.css">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Material+Symbols+Outlined&display=swap">'
        . '<link rel="stylesheet" href="/css/home-lab.css">'
        . '<link rel="stylesheet" href="/css/store-lab.css">'
        . '<link rel="stylesheet" href="/css/store-easy.css">'
        . '<style>body{font-family:Manrope,system-ui,sans-serif;background:#f6f6f8;color:#111827;margin:0}main{max-width:80rem;margin:0 auto;padding:1.5rem 1rem}</style>'
        . '</head><body><main>' . $html . '</main>'
        . '<script src="/assets/store-easy.js" defer></script>'
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

echo "store easy view ok\n";
