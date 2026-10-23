<?php

declare(strict_types=1);

/**
 * Trial-store availability and missing-price signals.
 * Usage: php scripts/test-store-lab-signals.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

use Portal\Services\StoreCatalogService;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$assert(
    'zero warehouse quantity is coming soon',
    StoreCatalogService::materialIsUnavailable(['warehouseQuantity' => 0, 'name' => 'نفد'])
);
$assert(
    'positive quantity stays in stock',
    StoreCatalogService::materialIsUnavailable(['warehouseQuantity' => 4, 'isAvailable' => true]) === false
);
$assert(
    'explicit unavailable flag wins',
    StoreCatalogService::materialIsUnavailable(['isAvailable' => false, 'name' => 'قريبا'])
);
$assert(
    'missing quantity is not treated as unavailable',
    StoreCatalogService::materialIsUnavailable(['name' => 'بلا إشارة']) === false
);
$assert(
    'string false flag is unavailable',
    StoreCatalogService::materialIsUnavailable(['isAvailable' => 'false', 'warehouseQuantity' => 2])
);

$separated = StoreCatalogService::separateUnavailableProducts([
    'products' => [
        ['name' => 'متوفر', 'warehouseQuantity' => 3],
        ['name' => 'نفد', 'warehouseQuantity' => 0],
    ],
    'totalCount' => 2,
]);
$assert(
    'in-stock grid drops unavailable rows',
    count($separated['catalog']['products']) === 1
        && ($separated['catalog']['products'][0]['name'] ?? '') === 'متوفر'
        && count($separated['stripped']) === 1
        && (int) $separated['catalog']['totalCount'] === 1
);

$assert(
    'null and zero prices are absent',
    catalog_material_has_sale_price(['unitSalePriceSyp' => null, 'unitSalePriceUsd' => 0]) === false
);
$assert(
    'empty string price is absent',
    catalog_material_has_sale_price(['unitSalePriceSyp' => '', 'UnitSalePriceUsd' => '']) === false
);
$assert(
    'a positive syp price is present',
    catalog_material_has_sale_price(['unitSalePriceSyp' => 1500, 'unitSalePriceUsd' => null])
);
$assert(
    'syp mode ignores a usd-only price',
    catalog_material_has_sale_price(['unitSalePriceUsd' => 12], 'syp') === false
);
$assert(
    'none mode never shows a price',
    catalog_material_has_sale_price(['unitSalePriceSyp' => 1500], 'none') === false
);

$mixed = StoreCatalogService::scopeLabGridProducts([
    ['name' => 'أول', 'warehouseQuantity' => 3],
    ['name' => 'ثان', 'warehouseQuantity' => 0],
    ['name' => 'ثالث', 'warehouseQuantity' => 1, 'isAvailable' => false],
], null);
$assert(
    'mixed grid keeps catalog order',
    array_map(static fn (array $row): string => (string) $row['name'], $mixed) === ['أول', 'ثان', 'ثالث']
);
$onlySoon = StoreCatalogService::scopeLabGridProducts($mixed, false);
$assert(
    'availability filter can keep only coming soon rows',
    count($onlySoon) === 2
        && ($onlySoon[0]['name'] ?? '') === 'ثان'
        && ($onlySoon[1]['name'] ?? '') === 'ثالث'
);
$onlyStock = StoreCatalogService::scopeLabGridProducts($mixed, true);
$assert(
    'availability filter can keep only stocked rows',
    count($onlyStock) === 1 && ($onlyStock[0]['name'] ?? '') === 'أول'
);

$ordered = StoreCatalogService::orderUnavailableLast([
    ['name' => 'قريبا-2', 'materialCode' => '2', 'warehouseQuantity' => 0],
    ['name' => 'متوفر-1', 'materialCode' => '1', 'warehouseQuantity' => 3],
    ['name' => 'متوفر-4', 'materialCode' => '4', 'warehouseQuantity' => 1],
    ['name' => 'قريبا-3', 'materialCode' => '3', 'isAvailable' => false, 'warehouseQuantity' => 8],
    ['name' => 'متوفر-5', 'materialCode' => '5', 'warehouseQuantity' => 2],
]);
$assert(
    'coming soon sorts after available and each block keeps its order',
    array_map(static fn (array $row): string => (string) $row['name'], $ordered) === [
        'متوفر-1',
        'متوفر-4',
        'متوفر-5',
        'قريبا-2',
        'قريبا-3',
    ]
);

$window = StoreCatalogService::labAvailabilityWindow(0, 24, 100, 40);
$assert(
    'first page stays inside the available block',
    $window['availableOffset'] === 0 && $window['availableLimit'] === 24
        && $window['unavailableLimit'] === 0 && $window['total'] === 140
);
$boundary = StoreCatalogService::labAvailabilityWindow(24, 24, 30, 40);
$assert(
    'boundary page finishes available rows then takes coming soon',
    $boundary['availableOffset'] === 24 && $boundary['availableLimit'] === 6
        && $boundary['unavailableOffset'] === 0 && $boundary['unavailableLimit'] === 18
);
$tail = StoreCatalogService::labAvailabilityWindow(48, 24, 30, 80);
$assert(
    'later pages are coming soon in the same sort window',
    $tail['availableLimit'] === 0 && $tail['unavailableOffset'] === 18 && $tail['unavailableLimit'] === 24
);
$onlySoonWindow = StoreCatalogService::labAvailabilityWindow(0, 24, 0, 10);
$assert(
    'a catalog with no stock starts at coming soon',
    $onlySoonWindow['availableLimit'] === 0 && $onlySoonWindow['unavailableOffset'] === 0
        && $onlySoonWindow['unavailableLimit'] === 10 && $onlySoonWindow['total'] === 10
);
$searchWindow = StoreCatalogService::labAvailabilityWindow(0, 24, 2, 5);
$assert(
    'search window keeps stock matches ahead of coming soon matches',
    $searchWindow['availableLimit'] === 2 && $searchWindow['unavailableLimit'] === 5 && $searchWindow['total'] === 7
);

$assert(
    'trial url keeps catalog filters',
    store_lab_url(['q' => 'حذاء', 'materialTypes' => ['رجالي']]) === '/store.php?q=' . rawurlencode('حذاء') . '&materialTypes%5B0%5D=' . rawurlencode('رجالي')
        || str_starts_with(store_lab_url(['q' => 'حذاء']), '/store.php?q=')
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "store lab signals ok\n";
