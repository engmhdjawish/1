<?php

declare(strict_types=1);

/**
 * Smoke checks for catalog filter view short URLs.
 * Run: php portal/scripts/test-catalog-filter-views.php
 */

$failed = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failed): void {
    if ($actual !== $expected) {
        echo "FAIL {$label}: expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL;
        $failed++;
        return;
    }
    echo "ok   {$label}\n";
};

require dirname(__DIR__) . '/src/Services/CatalogFilterViewService.php';

use Portal\Services\CatalogFilterViewService;

$payload = CatalogFilterViewService::normalizePayload([
    'materialTypes' => ['رجالي', 'نسائي', 'رجالي'],
    'q' => ' سيراميك ',
    'page' => '2',
    'token' => 'should-ignore',
    'v' => 'abc',
    'sort' => 'number:asc',
]);

$check('drops identity keys', isset($payload['token']) || isset($payload['v']) || isset($payload['page']), false);
$check('keeps search', $payload['q'] ?? null, 'سيراميك');
$check('unique sorted facets', $payload['materialTypes'] ?? null, ['رجالي', 'نسائي']);
$check('keeps sort', $payload['sort'] ?? null, 'number:asc');

$check(
    'should shorten long arabic query',
    CatalogFilterViewService::shouldShorten([
        'materialTypes' => ['سيراميك أرضيات', 'سيراميك جدران'],
        'ageCategories' => ['رجالي'],
    ]),
    true
);

$check(
    'skip empty',
    CatalogFilterViewService::shouldShorten([]),
    false
);

$check('normalize code', CatalogFilterViewService::normalizeCode('Ab12Cd'), 'ab12cd');
$check('reject bad code', CatalogFilterViewService::normalizeCode('x'), null);

$expanded = CatalogFilterViewService::expandRequest([
    'v' => 'notrealcode1',
    'page' => '3',
]);
$check('unknown view keeps query', $expanded['page'] ?? null, '3');
$check('unknown view keeps v', $expanded['v'] ?? null, 'notrealcode1');

$service = file_get_contents(dirname(__DIR__) . '/src/Services/CatalogFilterViewService.php') ?: '';
$api = file_get_contents(dirname(__DIR__) . '/public/api/store-filter-view.php') ?: '';
$js = file_get_contents(dirname(__DIR__) . '/public/assets/store-filter-view.js') ?: '';
$store = file_get_contents(dirname(__DIR__) . '/public/store.php') ?: '';
$share = file_get_contents(dirname(__DIR__) . '/public/share.php') ?: '';
$migration = file_get_contents(dirname(__DIR__, 2) . '/docs/portal-migrations/028-catalog-filter-views.sql') ?: '';
$easy = file_get_contents(dirname(__DIR__) . '/public/assets/store-easy.js') ?: '';

$check('migration table', str_contains($migration, 'catalog_filter_views'), true);
$check('api mint endpoint', str_contains($api, 'CatalogFilterViewService::mint'), true);
$check('store expands v', str_contains($store, 'expandRequest'), true);
$check('share expands v', str_contains($share, 'expandRequest'), true);
$check('js shortens bar', str_contains($js, 'shortenAddressBar'), true);
$check('js keeps working href', str_contains($js, 'workingHref'), true);
$check('js in-app share', str_contains($js, 'shareCurrentView') && str_contains($js, 'navigator.share'), true);
$check('easy uses shorten', str_contains($easy, 'shortenAddressBar'), true);
$check('service content hash', str_contains($service, 'payload_hash'), true);

$easyView = file_get_contents(dirname(__DIR__) . '/views/store-easy.php') ?: '';
$catalogView = file_get_contents(dirname(__DIR__) . '/views/store-catalog.php') ?: '';
$check('easy share button', str_contains($easyView, 'data-share-filter-view'), true);
$check('catalog share button', str_contains($catalogView, 'data-share-filter-view'), true);

$shortSample = 'https://www.jawishco.sy' . CatalogFilterViewService::shortPath('ab3x9k');
$check('short url under 40 chars', strlen($shortSample) < 40, true);
$check('short url has no query', str_contains($shortSample, '?'), false);
$check('short url format', preg_match('#^https://www\.jawishco\.sy/s/[a-z0-9]{5,7}$#', $shortSample) === 1, true);
$check('codes are 6 chars', str_contains($service, 'generateCode(int $length = 6)'), true);
$check('service stores target path', str_contains($service, 'target_path'), true);

$sPhp = file_get_contents(dirname(__DIR__) . '/public/s.php') ?: '';
$check('/s/ resolves filter + product codes', str_contains($sPhp, 'resolveRedirect'), true);
$check('/s/ uses landing url', str_contains($sPhp, 'landingUrl'), true);

$productLanding = CatalogFilterViewService::landingUrl('/product.php?guid=abc-def');
parse_str((string) parse_url($productLanding, PHP_URL_QUERY), $productQuery);
$check(
    'product short link opens the product page',
    str_starts_with($productLanding, '/product.php?') && ($productQuery['guid'] ?? '') === 'abc-def' && ($productQuery['return'] ?? '') === '/store.php',
    true
);
$keptLanding = CatalogFilterViewService::landingUrl('/product.php?guid=abc-def&return=%2Fshare.php%3Ftoken%3Dzzzz');
parse_str((string) parse_url($keptLanding, PHP_URL_QUERY), $keptQuery);
$check('product short link keeps an existing return', $keptQuery['return'] ?? null, '/share.php?token=zzzz');
$check('kept product link is still the product page', str_starts_with($keptLanding, '/product.php?'), true);
$check(
    'store short link is not sent home',
    CatalogFilterViewService::landingUrl('/store.php?v=ab12cd'),
    '/store.php?v=ab12cd'
);

$indexPhp = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
$check('homepage dispatches /s/ before rendering', str_contains($indexPhp, "require __DIR__ . '/s.php'"), true);
$bootstrapPos = strpos($indexPhp, "require dirname(__DIR__) . '/bootstrap.php'");
$dispatchPos = strpos($indexPhp, "require __DIR__ . '/s.php'");
$check('short-link dispatch runs before homepage bootstrap', $dispatchPos !== false && $bootstrapPos !== false && $dispatchPos < $bootstrapPos, true);

$shareFn = substr($js, (int) strpos($js, 'async function resolveShareUrl'), 900);
$check('share never uses ?v= url', str_contains($shareFn, 'data.share_url') && !str_contains($shareFn, 'mint('), true);
$check('navigator.share url only', str_contains($js, 'navigator.share({ title, url })'), true);

$productJs = file_get_contents(dirname(__DIR__) . '/public/assets/product-share.js') ?: '';
$check('product share uses short link', str_contains($productJs, 'shortenPath') && str_contains($productJs, "kind: 'path'"), true);
$check('product share no long fallback', str_contains($productJs, "window.location.origin}/product.php"), false);

$helpers = file_get_contents(dirname(__DIR__) . '/views/helpers.php') ?: '';
$check('tracking urls shortened', str_contains($helpers, 'shortenPath($path)'), true);

echo $failed === 0 ? "\ncatalog filter views ok\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
