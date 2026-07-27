<?php

declare(strict_types=1);

/**
 * Offer pricing idempotency checks.
 * Usage: php scripts/test-offer-pricing.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\SpecialOfferService;

$failures = [];

$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

echo "=== Offer pricing checks ===\n\n";

$material = [
    'materialGuid' => 'mat-1',
    'unitSalePriceSyp' => 1000.0,
    'unitSalePriceUsd' => 10.0,
    'packageConversionFactor' => 10,
];

$offer = [
    'id' => 'offer-1',
    'discount_type' => 'percent',
    'discount_percent' => 20,
];

$first = SpecialOfferService::computePricing($material, $offer);
$alreadyPriced = array_merge($material, $first, ['has_offer' => true]);
$second = SpecialOfferService::computePricing($alreadyPriced, $offer);

$assert(
    'computePricing uses original price when offer already applied',
    abs($second['original_unit_sale_price_sp'] - 1000.0) < 0.001
        && abs($second['effective_unit_sale_price_sp'] - 800.0) < 0.001
);

$overlay = SpecialOfferService::pricingOverlay($alreadyPriced, $offer);
$assert(
    'pricingOverlay is idempotent for pre-priced products',
    !empty($overlay['has_offer'])
        && abs((float) ($overlay['effective_unit_sale_price_sp'] ?? 0) - 800.0) < 0.001
        && abs((float) ($overlay['original_unit_sale_price_sp'] ?? 0) - 1000.0) < 0.001
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "All checks passed\n";
