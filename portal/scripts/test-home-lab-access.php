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

try {
    ob_start();
    require dirname(__DIR__) . '/views/home-lab.php';
    $html = (string) ob_get_clean();
} finally {
    ResponseCache::forget($cacheKey);
    if ($previous !== null) {
        ResponseCache::set($cacheKey, $previous, 600);
    }
    if (!$cacheDirExisted && is_dir($cacheDir) && count((array) scandir($cacheDir)) <= 2) {
        @rmdir($cacheDir);
    }
}

$pos = static fn (string $needle): int => ($p = strpos($html, $needle)) === false ? PHP_INT_MAX : $p;

$assert('search goes to the trial store with lab suggestions', str_contains($html, 'action="/store-lab.php" method="get" role="search" data-hl-search data-hl-surface="lab"'));
$assert('recent searches slot', str_contains($html, 'data-lab-recent-q') && str_contains($html, '/assets/lab-access.js'));
$assert('quick access shortcuts', str_contains($html, 'class="lab-quick"') && str_contains($html, '>العروض<') && str_contains($html, '>كل المواد<') && str_contains($html, 'isAvailable=1') && str_contains($html, 'href="/store-cart.php"') && str_contains($html, 'https://wa.me/963999000000'));
$assert('guest gets order tracking', str_contains($html, 'href="/track-order.php"') && !str_contains($html, 'href="/my-orders.php"'));
$assert('category tiles link to the trial store', str_contains($html, 'تسوّق حسب الفئة') && str_contains($html, '/store-lab.php?ageCategories'));
$assert('recently viewed slot is hidden until filled', (bool) preg_match('/class="lab-viewed"[^>]*data-lab-recent-viewed hidden/u', $html));
$assert('finder with visible facets only', str_contains($html, 'اعثر بسرعة') && str_contains($html, 'name="ageCategories[]"') && str_contains($html, 'name="sizeRanges[]"') && str_contains($html, 'name="manufacturers[]"') && str_contains($html, 'name="isAvailable"'));
$assert('availability is not preselected', !(bool) preg_match('/name="isAvailable" value="1" checked/u', $html));
$assert('size shortcuts sort naturally', $pos('>18-23<') < $pos('>36-39<') && $pos('>36-39<') < $pos('>40-45<'));
$assert('sections directory', str_contains($html, 'class="lab-dir"') && str_contains($html, 'عرض خاص') && str_contains($html, '/store-lab.php?'));
$assert('section more links stay on the trial store', !str_contains($html, 'href="/store.php?section') && !str_contains($html, 'href="/store.php?offer'));
$assert('access layers come before the hero', $pos('lab-quick') < $pos('home-hero home-hero--premium') && $pos('lab-finder') < $pos('home-hero home-hero--premium') && $pos('lab-dir') < $pos('home-section-nav--sticky'));
$assert('phone dock', str_contains($html, 'class="lab-dock"') && str_contains($html, 'href="/home-lab.php" class="lab-dock__item is-active"') && str_contains($html, 'data-lab-dock-search'));
$assert('no warehouses', !str_contains($html, 'مستودع'));
$assert('no hint copy', !str_contains($html, 'جرّب') && !str_contains($html, 'اكتب') && !str_contains($html, 'يخمن'));

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "home lab access ok\n";
