<?php

declare(strict_types=1);

/**
 * Offer pricing idempotency + cart double-discount + per-material checks.
 * Usage: php scripts/test-offer-pricing.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ShareCartService;
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

// Cart path: catalog already overwrote unitSalePriceSyp — must not discount again.
$catalogProduct = array_merge($material, $first, [
    'has_offer' => true,
    'offer' => $offer,
    'name' => 'مادة تجريبية',
    'materialCode' => 'T-1',
]);
$cartLine = ShareCartService::lineFromApiItem($catalogProduct, true);
$assert(
    'lineFromApiItem keeps list unit price from originals',
    abs((float) ($cartLine['unit_sale_price_sp'] ?? 0) - 1000.0) < 0.001
        && abs((float) ($cartLine['original_unit_sale_price_sp'] ?? 0) - 1000.0) < 0.001
);

$applied = SpecialOfferService::applyToCartLine($cartLine, $offer);
$assert(
    'applyToCartLine does not double-discount after catalog overlay',
    abs((float) ($applied['unit_sale_price_sp'] ?? 0) - 800.0) < 0.001
        && abs((float) ($applied['original_unit_sale_price_sp'] ?? 0) - 1000.0) < 0.001
        && abs((float) ($applied['sale_price_sp'] ?? 0) - 8000.0) < 0.001
);

$amountOffer = [
    'id' => 'offer-amount',
    'discount_type' => 'fixed_amount',
    'fixed_amount_syp' => 1500,
    'fixed_amount_usd' => 15,
];
$amountPricing = SpecialOfferService::computePricing($material, $amountOffer);
$assert(
    'fixed_amount subtracts from package price',
    abs($amountPricing['original_package_sale_price_sp'] - 10000.0) < 0.001
        && abs($amountPricing['effective_package_sale_price_sp'] - 8500.0) < 0.001
);

$perMaterialOffer = [
    'id' => 'offer-per',
    'pricing_scope' => 'per_material',
    'discount_type' => 'percent',
    'discount_percent' => 10,
    'product_overrides' => [
        'mat-1' => [
            'discount_type' => 'fixed_price',
            'fixed_price_syp' => 7000,
            'fixed_price_usd' => 70,
        ],
    ],
];
$perPricing = SpecialOfferService::computePricing($material, $perMaterialOffer);
$assert(
    'per-material override uses fixed package price',
    abs($perPricing['effective_package_sale_price_sp'] - 7000.0) < 0.001
);

$otherMaterial = array_merge($material, ['materialGuid' => 'mat-2']);
$fallbackPricing = SpecialOfferService::computePricing($otherMaterial, $perMaterialOffer);
$assert(
    'per-material without override falls back to offer percent',
    abs($fallbackPricing['effective_unit_sale_price_sp'] - 900.0) < 0.001
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "All checks passed\n";
