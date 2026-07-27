<?php

declare(strict_types=1);

/**
 * Unit checks for catalog sellable stock (full-package minimum).
 * Usage: php scripts/test-sellable-stock-filter.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\StockReservationService;

$failures = [];

$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

echo "=== Sellable stock filter checks ===\n\n";

$onePairInDozenBox = [
    'materialGuid' => '11111111-1111-1111-1111-111111111111',
    'warehouseQuantity' => 1,
    'packageConversionFactor' => 12,
];

$fullBox = [
    'materialGuid' => '22222222-2222-2222-2222-222222222222',
    'warehouseQuantity' => 12,
    'packageConversionFactor' => 12,
];

$assert(
    'single primary unit below packaging is not sellable in catalog',
    StockReservationService::isSellable($onePairInDozenBox) === false
);
$assert(
    'full package quantity is sellable in catalog',
    StockReservationService::isSellable($fullBox) === true
);

$filtered = StockReservationService::filterSellableProducts([$onePairInDozenBox, $fullBox]);
$assert(
    'filterSellableProducts keeps only full-package items',
    count($filtered) === 1
        && ($filtered[0]['materialGuid'] ?? '') === '22222222-2222-2222-2222-222222222222'
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "All checks passed.\n";
