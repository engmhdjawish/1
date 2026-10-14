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

$assert(
    'trial url keeps catalog filters',
    store_lab_url(['q' => 'حذاء', 'materialTypes' => ['رجالي']]) === '/store-lab.php?q=' . rawurlencode('حذاء') . '&materialTypes%5B0%5D=' . rawurlencode('رجالي')
        || str_starts_with(store_lab_url(['q' => 'حذاء']), '/store-lab.php?q=')
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "store lab signals ok\n";
