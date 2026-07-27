<?php

declare(strict_types=1);

/**
 * Unit checks for warehouse policy product scoping helpers.
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

echo "=== Warehouse / sellable stock checks ===\n\n";

$onePairInDozenBox = [
    'materialGuid' => '11111111-1111-1111-1111-111111111111',
    'warehouseQuantity' => 1,
    'packageConversionFactor' => 12,
];

$assert(
    'partial primary stock can still be sellable when cart allows it',
    StockReservationService::isSellable($onePairInDozenBox) === true
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "All checks passed.\n";
