<?php

declare(strict_types=1);

/**
 * Diagnose / repair offer attribution for one order.
 *
 * Usage:
 *   php scripts/repair-offer-order.php ORD-20261117-AAC1E517 offer-d3cf783a
 *   php scripts/repair-offer-order.php ORD-20261117-AAC1E517 offer-d3cf783a --dry-run
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Services\SpecialOfferAnalyticsService;

$orderNumber = trim((string) ($argv[1] ?? ''));
$offerSlug = trim((string) ($argv[2] ?? ''));
$dryRun = in_array('--dry-run', $argv ?? [], true) || in_array('-n', $argv ?? [], true);

if ($orderNumber === '' || $offerSlug === '') {
    fwrite(STDERR, "Usage: php scripts/repair-offer-order.php <order_number> <offer_slug> [--dry-run]\n");
    exit(1);
}

echo "=== Diagnose ===\n";
$diagnose = SpecialOfferAnalyticsService::diagnoseOrderForOffer($orderNumber, $offerSlug);
echo json_encode($diagnose, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

echo "\n=== Repair " . ($dryRun ? '(dry-run)' : '') . " ===\n";
$result = SpecialOfferAnalyticsService::repairOrderForOfferSlug($orderNumber, $offerSlug, !$dryRun);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

exit(($result['ok'] ?? false) ? 0 : 2);