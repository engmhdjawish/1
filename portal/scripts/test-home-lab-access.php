<?php

declare(strict_types=1);

/**
 * Trial homepage access layers: search, quick access, tiles, recently viewed, finder, sections, dock.
 * Usage: php scripts/test-home-lab-access.php
 */

define('PORTAL_NO_SESSION', true);
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/home-lab.php';
$_GET = [];

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

use Portal\Services\HomeShopBlockService;
use Portal\Services\StoreCatalogService;
use Portal\Support\ResponseCache;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$ref = new ReflectionClass(StoreCatalogService::class);
$ref->setStaticPropertyValue('activePolicyResolved', true);
$ref->setStaticPropertyValue('activePolicyCache', [
    'store_options' => [
        'allow_sorting' => true,
        'visible_client_filters' => ['search', 'materialTypes', 'manufacturers', 'ageCategories', 'sizeRanges', 'availability', 'sort'],
    ],
    'filter_rules' => [],
]);

$cacheKey = 'store_filter_options_v2';
$cacheDir = \Portal\Config::storagePath() . '/cache';
$cacheDirExisted = is_dir($cacheDir);
$previous = ResponseCache::get($cacheKey);
ResponseCache::set($cacheKey, [
    'stores' => [['guid' => 'wh-1', 'name' => 'مستودع الستوك']],
    'groups' => [],
    'ageCategories' => ['رجالي', 'نسائي', 'ولادي'],
    'materialTypes' => ['رياضي', 'كلاسيك'],
    'sizeRanges' => ['40-45', '18-23', '36-39'],
    'manufacturers' => ['جاويش', 'النجم'],
], 600);

$sections = [
    ['slug' => 'offers', 'title_ar' => 'عروض الأسبوع', 'is_offer_section' => true, 'products' => []],
    ['slug' => 'men', 'title_ar' => 'أحذية رجالية', 'products' => []],
];
$ads = [];
$embeddedProductStrips = [];
$storeCatalogDisplay = ['show_price' => true, 'show_quantity' => false, 'allow_cart' => true, 'allow_order' => true, 'show_images' => true, 'price_mode' => 'both'];
$companyContext = ['company_name' => 'جاويش', 'company_whatsapp' => '+963 999 000 000', 'about_us_ar' => ''];

$shopCacheKey = 'home_shop_blocks_v1';
$previousShop = ResponseCache::get($shopCacheKey);
ResponseCache::set($shopCacheKey, HomeShopBlockService::defaultBlocks(), 600);

try {
    ob_start();
    require dirname(__DIR__) . '/views/home-lab.php';
    $html = (string) ob_get_clean();

    $customBlocks = [
        HomeShopBlockService::normalize([
            'id' => 'c1', 'title_ar' => 'مختارات', 'block_type' => 'custom', 'style' => 'cards', 'placement' => 'band',
            'tiles' => [
                ['label' => 'بناتي شتوي', 'filters' => ['ageCategories' => 'بناتي', 'materialTypes' => 'شتوي'], 'available' => true],
                ['label' => 'صيني', 'filters' => ['countryOfOrigins' => 'الصين']],
                ['label' => 'بلا فلتر', 'filters' => []],
            ],
        ]),
        HomeShopBlockService::normalize([
            'id' => 'c2', 'title_ar' => 'الشركة والفئة', 'block_type' => 'combo',
            'primary_param' => 'manufacturers', 'secondary_param' => 'ageCategories',
            'primary_values' => ['النجم', 'غير موجودة'], 'secondary_values' => [],
        ]),
        HomeShopBlockService::normalize([
            'id' => 'c3', 'title_ar' => 'البلد', 'block_type' => 'facet', 'primary_param' => 'countryOfOrigins',
        ]),
    ];
    $resolvedCustom = lab_shop_blocks_resolve($customBlocks, static fn (array $params): string => store_lab_url($params));
} finally {
    ResponseCache::forget($shopCacheKey);
    if ($previousShop !== null) {
        ResponseCache::set($shopCacheKey, $previousShop, 600);
    }
    ResponseCache::forget($cacheKey);
    if ($previous !== null) {
        ResponseCache::set($cacheKey, $previous, 600);
    }
    if (!$cacheDirExisted && is_dir($cacheDir) && count((array) scandir($cacheDir)) <= 2) {
        @rmdir($cacheDir);
    }
}

$pos = static fn (string $needle): int => ($p = strpos($html, $needle)) === false ? PHP_INT_MAX : $p;

$assert('search goes to the trial store with lab suggestions', str_contains($html, 'action="/store.php" method="get" role="search" data-hl-search data-hl-surface="lab"'));
$assert('recent searches slot', str_contains($html, 'data-lab-recent-q') && str_contains($html, '/assets/lab-access.js'));
$assert('quick access shortcuts', str_contains($html, 'class="lab-quick"') && str_contains($html, '>العروض<') && str_contains($html, '>كل المواد<') && str_contains($html, 'isAvailable=1') && str_contains($html, 'href="/store-cart.php"') && str_contains($html, 'https://wa.me/963999000000'));
$assert('guest gets order tracking', str_contains($html, 'href="/track-order.php"') && !str_contains($html, 'href="/my-orders.php"'));
$assert('shop-by hub with a tab per default block', str_contains($html, 'id="lab-shop-title" class="lab-shop__title">تسوّق حسب<') && substr_count($html, 'data-lab-shop-tab') === 5 && str_contains($html, '>الشركة والفئة<') && str_contains($html, '>النوع والفئة<'));
$assert('only the first shop panel is open', substr_count($html, 'class="lab-shop__panel"') === 5 && preg_match_all('/class="lab-shop__panel"[^>]*hidden/u', $html) === 4);
$assert('category tiles link to the trial store', str_contains($html, '/store.php?ageCategories%5B0%5D=' . rawurlencode('نسائي')));
$assert('brand cards show an initial', str_contains($html, 'lab-shop-card__initial'));
$comboHref = '/store.php?' . http_build_query(['manufacturers' => ['جاويش'], 'ageCategories' => ['رجالي']]);
$assert('combo links carry both filters', str_contains($html, h($comboHref)) && str_contains($html, '<span>عرض كل جاويش</span>') && str_contains($html, 'data-lab-shop-pick="1"'));
$assert('shop-by sits before the finder', $pos('data-lab-shop') < $pos('lab-finder'));

$custom = $resolvedCustom[0] ?? null;
$assert('custom cards drop hidden facets and empty tiles', $custom !== null && $custom['placement'] === 'band' && count($custom['tiles']) === 1);
$assert('custom card merges several filters', $custom !== null && $custom['tiles'][0]['href'] === '/store.php?' . http_build_query(['ageCategories' => ['بناتي'], 'materialTypes' => ['شتوي'], 'isAvailable' => '1']));
$combo = $resolvedCustom[1] ?? null;
$assert('combo keeps only existing configured values', $combo !== null && count($combo['groups']) === 1 && $combo['groups'][0]['label'] === 'النجم' && count($combo['groups'][0]['tiles']) === 3);
$assert('block on a hidden facet is skipped', count($resolvedCustom) === 2);

$bad = HomeShopBlockService::normalize(['block_type' => 'combo', 'primary_param' => 'manufacturers', 'secondary_param' => 'manufacturers', 'style' => 'x', 'max_items' => 500]);
$assert('normalize rejects same-facet combos and bad options', $bad['secondary_param'] === '' && $bad['style'] === 'icons' && $bad['max_items'] === 60);
$assert('save validates before touching the database', HomeShopBlockService::save(null, ['title_ar' => ''], null)['ok'] === false && HomeShopBlockService::save(null, ['title_ar' => 'x', 'block_type' => 'custom'], null)['ok'] === false);
$assert('recently viewed slot is hidden until filled', (bool) preg_match('/class="lab-viewed"[^>]*data-lab-recent-viewed hidden/u', $html));
$labAccessJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/lab-access.js');
$assert(
    'recently viewed opens store product preview cards',
    str_contains($labAccessJs, "data-store-product-preview")
    && str_contains($labAccessJs, "data-store-preview-card")
    && str_contains($labAccessJs, 'StoreProductPreview')
    && str_contains($labAccessJs, '/api/store-product.php')
    && !str_contains($labAccessJs, "link.className = 'lab-viewed__card'")
);
$assert(
    'recently viewed preview hides unavailable prices without a price-lock veil',
    str_contains($labAccessJs, 'priceHidden')
    && str_contains($labAccessJs, 'unavailable')
);
$storeProductApi = (string) file_get_contents(dirname(__DIR__) . '/public/api/store-product.php');
$assert(
    'store-product API marks unavailable prices as priceHidden',
    str_contains($storeProductApi, "priceHidden")
    && str_contains($storeProductApi, 'materialIsUnavailable')
    && str_contains($storeProductApi, 'showUnavailablePrices')
);
$previewJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/store-product-preview.js');
$assert(
    'product preview scopes recently viewed and exports open',
    str_contains($previewJs, '[data-lab-recent-viewed]')
    && str_contains($previewJs, 'window.StoreProductPreview = { open, close }')
);
$indexPhp = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert('home enables store product preview shell', str_contains($indexPhp, '$enableProductPreview = true'));
$assert('finder with visible facets only', str_contains($html, 'اعثر بسرعة') && str_contains($html, 'name="ageCategories[]"') && str_contains($html, 'name="sizeRanges[]"') && str_contains($html, 'name="manufacturers[]"') && str_contains($html, 'name="isAvailable"'));
$assert('availability is not preselected', !(bool) preg_match('/name="isAvailable" value="1" checked/u', $html));
$assert('size shortcuts sort naturally', $pos('>18-23<') < $pos('>36-39<') && $pos('>36-39<') < $pos('>40-45<'));
$assert('sections directory', str_contains($html, 'class="lab-dir"') && str_contains($html, 'عرض خاص') && str_contains($html, '/store.php?'));
$assert('section more links go to the store', !str_contains($html, '/store-lab.php'));
$assert('access layers come before the hero', $pos('lab-quick') < $pos('home-hero home-hero--premium') && $pos('lab-finder') < $pos('home-hero home-hero--premium') && $pos('lab-dir') < $pos('home-section-nav--sticky'));
$assert('phone dock', str_contains($html, 'class="lab-dock"') && str_contains($html, 'href="/index.php" class="lab-dock__item is-active"') && str_contains($html, 'data-lab-dock-search'));
$assert('no warehouses', !str_contains($html, 'مستودع'));
$assert('no hint copy', !str_contains($html, 'جرّب') && !str_contains($html, 'اكتب') && !str_contains($html, 'يخمن'));

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "home lab access ok\n";
