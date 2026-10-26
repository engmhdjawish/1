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
$check('easy uses shorten', str_contains($easy, 'shortenAddressBar'), true);
$check('service content hash', str_contains($service, 'payload_hash'), true);

echo $failed === 0 ? "\ncatalog filter views ok\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
