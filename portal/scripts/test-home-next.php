<?php

declare(strict_types=1);

/**
 * From-scratch trial homepage: search, stories, offer spotlight, step picker, feed, help, sheet, dock.
 * Usage: php scripts/test-home-next.php
 */

define('PORTAL_NO_SESSION', true);
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/home-next.php';
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

$shopCacheKey = 'home_shop_blocks_v1';
$previousShop = ResponseCache::get($shopCacheKey);
ResponseCache::set($shopCacheKey, HomeShopBlockService::defaultBlocks(), 600);

$sections = [
    [
        'slug' => 'offers', 'title_ar' => 'عروض الأسبوع', 'is_offer_section' => true, 'products' => [],
        'discount_type' => 'percent', 'discount_percent' => '15.00', 'ends_at' => date('Y-m-d H:i:sP', time() + 30 * 3600),
    ],
    ['slug' => 'men', 'title_ar' => 'أحذية رجالية', 'products' => []],
];
$ads = [];
$embeddedProductStrips = [];
$storeCatalogDisplay = ['show_price' => true, 'show_quantity' => false, 'allow_cart' => true, 'allow_order' => true, 'show_images' => true, 'price_mode' => 'both'];
$companyContext = ['company_name' => 'جاويش', 'company_whatsapp' => '+963 999 000 000', 'company_phone' => '011 222 3333', 'about_us_ar' => ''];

try {
    ob_start();
    require dirname(__DIR__) . '/views/home-next.php';
    $html = (string) ob_get_clean();
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
$now = 1_800_000_000;

$assert('countdown in days', hn_offer_countdown(date('c', $now + 3 * 86400 + 60), $now) === 'ينتهي خلال 3 أيام');
$assert('countdown in hours', hn_offer_countdown(date('c', $now + 5 * 3600 + 7 * 60), $now) === 'ينتهي خلال 05:07');
$assert('no countdown when ended or open-ended', hn_offer_countdown(date('c', $now - 10), $now) === '' && hn_offer_countdown(null, $now) === '' && hn_offer_countdown('x', $now) === '');
$assert('day labels', hn_days_label(1) === 'يوم واحد' && hn_days_label(2) === 'يومين' && hn_days_label(11) === '11 يوماً');
$assert('offer badge', hn_offer_badge(['badge_text_ar' => 'جديد']) === 'جديد' && hn_offer_badge(['discount_type' => 'percent', 'discount_percent' => '12.50']) === 'خصم 12.5%' && hn_offer_badge([]) === 'عرض خاص');

$assert('single search to the trial store', substr_count($html, 'name="q"') === 1 && str_contains($html, 'action="/store-lab.php"') && str_contains($html, 'data-hn-search-input'));
$assert('voice button hidden until supported', (bool) preg_match('/data-hn-voice[^>]*hidden/u', $html));
$assert('stories: all sections first, offer ring, categories', $pos('hn-story--all') < $pos('data-hn-story=') && str_contains($html, 'hn-story hn-story--hot') && substr_count($html, 'data-hn-story=') === 5);
$assert('offer spotlight with badge and countdown', str_contains($html, '>خصم 15%<') && str_contains($html, 'data-hn-countdown="') && str_contains($html, 'ينتهي خلال يوم واحد') && str_contains($html, 'تسوّق العرض'));
$assert('step picker with three steps', substr_count($html, 'data-hn-guide-step=') === 3 && str_contains($html, '>لمن؟<') && str_contains($html, 'data-hn-guide-show'));
$assert('picker options work without script', str_contains($html, h(store_lab_url(['ageCategories' => ['نسائي']]))));
$assert('sizes sort naturally in the picker', $pos('>18-23<') < $pos('>36-39<') && $pos('>36-39<') < $pos('>40-45<'));
$assert('shop-by hub from dashboard blocks', substr_count($html, 'data-lab-shop-tab') === 5);
$assert('section rail links stay on the trial store', str_contains($html, 'class="hn-rail"') && !str_contains($html, 'href="/store.php?'));
$assert('endless feed from the trial store', str_contains($html, 'data-hn-feed') && str_contains($html, 'data-src="/store-lab.php"') && str_contains($html, 'store-product-grid se-grid'));
$assert('help: whatsapp, call, tracking, text size', str_contains($html, 'https://wa.me/963999000000') && str_contains($html, 'href="tel:0112223333"') && str_contains($html, 'href="/track-order.php"') && str_contains($html, 'data-hn-text-size'));
$assert('category sheet with panes per facet', (bool) preg_match('/class="hn-sheet"[^>]*data-hn-sheet[^>]*hidden/u', $html) && substr_count($html, 'data-hn-pane-tab') === 5);
$assert('phone dock with cart badge', str_contains($html, 'class="hn-dock"') && str_contains($html, 'href="/home-next.php" class="hn-dock__item is-active"') && str_contains($html, 'data-store-cart-badge'));
$assert('page order', $pos('hn-top') < $pos('hn-stories') && $pos('hn-stories') < $pos('class="hn-offer"') && $pos('class="hn-offer"') < $pos('data-hn-guide ') && $pos('data-hn-feed') < $pos('class="hn-help"'));
$assert('no warehouses', !str_contains($html, 'مستودع'));
$assert('no hint copy', !str_contains($html, 'جرّب') && !str_contains($html, 'اكتب') && !str_contains($html, 'يخمن'));

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "home next ok\n";
