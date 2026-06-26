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
        echo 'Filters deferred: ' . ((bool) ($catalog['filters_deferred'] ?? false) ? 'yes' : 'no') . "\n";
        $resultFilters = is_array($catalog['resultFilters'] ?? null) ? $catalog['resultFilters'] : [];
        $filterOptions = is_array($catalog['filterOptions'] ?? null) ? $catalog['filterOptions'] : [];
        foreach ([
            'materialTypes' => 'result materialTypes',
            'manufacturers' => 'result manufacturers',
            'groups' => 'result groups',
        ] as $key => $facetLabel) {
            $items = is_array($resultFilters[$key] ?? null) ? $resultFilters[$key] : [];
            echo $facetLabel . ': ' . count($items) . "\n";
        }
        echo 'filter stores: ' . count(is_array($filterOptions['stores'] ?? null) ? $filterOptions['stores'] : []) . "\n";
        echo 'filter groups: ' . count(is_array($filterOptions['groups'] ?? null) ? $filterOptions['groups'] : []) . "\n";
        echo 'Elapsed ms: ' . $elapsedMs . "\n";
    }

    $started = microtime(true);
    $fullFiltersCatalog = StoreCatalogService::catalogFromRequest(['facetFilters' => '1']);
    $fullFiltersMs = (int) round((microtime(true) - $started) * 1000);
    echo "\n--- with facetFilters=1 (inline filters) ---\n";
        echo 'Filters deferred: ' . ((bool) ($fullFiltersCatalog['filters_deferred'] ?? false) ? 'yes' : 'no') . "\n";
        $inlineResultFilters = is_array($fullFiltersCatalog['resultFilters'] ?? null) ? $fullFiltersCatalog['resultFilters'] : [];
        echo 'result materialTypes: ' . count(is_array($inlineResultFilters['materialTypes'] ?? null) ? $inlineResultFilters['materialTypes'] : []) . "\n";
        echo 'Elapsed ms: ' . $fullFiltersMs . "\n";
        if (count(is_array($inlineResultFilters['materialTypes'] ?? null) ? $inlineResultFilters['materialTypes'] : []) === 0) {
            echo "Note: clear storage/cache if facetFilters still hits a deferred cache entry.\n";
        }

    $started = microtime(true);
    $payload = StoreCatalogService::getClientFiltersPayload();
    $deferredPayloadMs = (int) round((microtime(true) - $started) * 1000);
    echo "\n--- deferred API payload ---\n";
    $payloadResultFilters = is_array($payload['resultFilters'] ?? null) ? $payload['resultFilters'] : [];
    echo 'result materialTypes: ' . count(is_array($payloadResultFilters['materialTypes'] ?? null) ? $payloadResultFilters['materialTypes'] : []) . "\n";
    echo 'Elapsed ms: ' . $deferredPayloadMs . "\n";

    echo "\nOK\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
