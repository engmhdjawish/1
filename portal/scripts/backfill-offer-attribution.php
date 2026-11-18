<?php

declare(strict_types=1);

/**
 * Backfill special_offer_id on historical orders + restore visitor sessions from logs.
 *
 * Usage:
 *   php scripts/backfill-offer-attribution.php
 *   php scripts/backfill-offer-attribution.php --days=365
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Services\SpecialOfferAnalyticsService;

$days = 365;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--days=(\d+)$/', (string) $arg, $m)) {
        $days = max(1, min(3650, (int) $m[1]));
    }
}

echo "Running full offer attribution backfill (days={$days})...\n";
$result = SpecialOfferAnalyticsService::runFullOrderOfferBackfill($days);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

$total = (int) ($result['sql_linked'] ?? 0) + (int) ($result['php_linked'] ?? 0);
exit($total > 0 || (int) ($result['sessions_filled'] ?? 0) > 0 ? 0 : 2);