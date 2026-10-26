<?php

declare(strict_types=1);

/**
 * Missing warehouseQuantity must not mark the add-to-cart button as «مكتمل».
 * Usage: php scripts/test-cart-qty-bounds-missing-stock.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/views/helpers.php';

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$noStockKey = [
    'materialGuid' => '6b1f0001-1c2d-4e5f-8a9b-000000001eef',
    'name' => 'مادة بلا كمية',
    'packaging' => 12,
];
$bounds = store_cart_qty_bounds($noStockKey, 0.0, false);
$assert('missing warehouseQuantity leaves stockAvailable null', $bounds['stockAvailable'] === null);
$assert('missing warehouseQuantity is not atLimit', $bounds['atLimit'] === false);
$assert('missing warehouseQuantity leaves effectiveMax open', $bounds['effectiveMax'] === null);

$preview = product_preview_payload($noStockKey, [
    'show_price' => true,
    'show_quantity' => false,
    'allow_cart' => true,
    'price_mode' => 'both',
]);
$assert('preview payload is not atLimit without warehouse qty', ($preview['atLimit'] ?? true) === false);
$assert(
    'preview effectiveMax is null without warehouse qty',
    array_key_exists('effectiveMax', $preview) && $preview['effectiveMax'] === null
);

$zeroStock = [
    'materialGuid' => '00000000-0000-4000-8000-000000000099',
    'name' => 'مخزون صفر',
    'packaging' => 12,
    'warehouseQuantity' => 0,
];
$zeroBounds = store_cart_qty_bounds($zeroStock, 0.0, false);
$assert('explicit zero stock is atLimit', $zeroBounds['atLimit'] === true);
$assert('explicit zero stock effectiveMax is 0', (float) $zeroBounds['effectiveMax'] === 0.0);

$inStock = [
    'materialGuid' => '00000000-0000-4000-8000-000000000098',
    'name' => 'مخزون متوفر',
    'packaging' => 12,
    'warehouseQuantity' => 24,
];
$inBounds = store_cart_qty_bounds($inStock, 0.0, false);
$assert('known stock is sellable', $inBounds['atLimit'] === false && (float) $inBounds['effectiveMax'] > 0);

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}

echo "\nAll cart qty bounds checks passed.\n";
