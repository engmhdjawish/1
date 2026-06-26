<?php

declare(strict_types=1);

/**
 * Test store catalog load (same path as store.php).
 * Usage: php scripts/test-store-catalog.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require $base . '/bootstrap.php';
require $base . '/views/helpers.php';

use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;
use Portal\Config;

echo "=== Store catalog test ===\n";
echo 'Storage: ' . Config::storagePath() . "\n";
echo 'Cache dir writable: ' . (is_writable(Config::storagePath()) || (is_dir(Config::storagePath() . '/cache') && is_writable(Config::storagePath() . '/cache')) ? 'yes' : 'no') . "\n\n";

try {
    $guest = StorePolicyService::guestPolicy();
    if ($guest === null) {
        echo "Guest policy: NOT SET or inactive\n";
    } else {
        echo 'Guest policy: ' . ($guest['name_ar'] ?? '') . ' (' . ($guest['code'] ?? '') . ")\n";
        echo 'Policy id: ' . ($guest['id'] ?? '') . "\n";
    }

    foreach (['first run (cold)', 'second run (cache)'] as $label) {
        $started = microtime(true);
        $catalog = StoreCatalogService::catalogFromRequest([]);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        echo "\n--- {$label} ---\n";
        echo 'Products: ' . count($catalog['products'] ?? []) . "\n";
        echo 'Total: ' . (int) ($catalog['totalCount'] ?? 0) . "\n";
        echo 'API error: ' . (string) ($catalog['apiError'] ?? '') . "\n";
        echo 'Allow client filters: ' . ((bool) ($catalog['allow_client_filters'] ?? false) ? 'yes' : 'no') . "\n";
        echo 'Filter options deferred: ' . (!empty($catalog['filterOptions']['deferred']) ? 'yes' : 'no') . "\n";
        echo 'Elapsed ms: ' . $elapsedMs . "\n";
    }

    echo "\nOK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
