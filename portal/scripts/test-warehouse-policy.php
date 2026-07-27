<?php

declare(strict_types=1);

/**
 * Diagnose guest access-policy warehouse scoping for the store catalog.
 * Usage: php scripts/test-warehouse-policy.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\AccessPolicyService;
use Portal\Services\ApiClient;
use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;

echo "=== Warehouse policy diagnostics ===\n\n";

$guest = StorePolicyService::guestPolicy();
if ($guest === null) {
    echo "FAIL: no guest policy configured in store_guest_settings\n";
    exit(1);
}

$policyId = trim((string) ($guest['id'] ?? ''));
$policyName = trim((string) ($guest['name_ar'] ?? ''));
echo "Guest policy: {$policyName} ({$policyId})\n";

$parsed = AccessPolicyService::parsedFiltersForPolicyId($policyId);
$rules = is_array($parsed['rules'] ?? null) ? $parsed['rules'] : [];
$storeGuids = array_values(array_map('strval', is_array($rules['store_guids'] ?? null) ? $rules['store_guids'] : []));
echo 'Policy store_guids count: ' . count($storeGuids) . "\n";
foreach ($storeGuids as $guid) {
    echo "  - {$guid}\n";
}

if ($storeGuids === []) {
    echo "\nWARN: guest policy has no warehouses. Store will show materials from all warehouses.\n";
    exit(0);
}

$resolved = StoreCatalogService::resolvedPolicyStoreGuids(null);
echo 'resolvedPolicyStoreGuids count: ' . count($resolved) . "\n";

$query = [
    'page' => 1,
    'pageSize' => 24,
    'storeGuids' => implode(',', $storeGuids),
    'isAvailable' => 'true',
    'includeTotalCount' => 'true',
];

echo "\nCalling /api/materials with policy storeGuids + isAvailable=true...\n";
try {
    $response = ApiClient::get('/api/materials', $query, 20);
} catch (Throwable $exception) {
    echo 'FAIL: API error: ' . $exception->getMessage() . "\n";
    exit(1);
}

if (!($response['ok'] ?? false)) {
    echo 'FAIL: API status ' . (int) ($response['status'] ?? 0) . ' ' . (string) ($response['error'] ?? '') . "\n";
    exit(1);
}

$data = is_array($response['data'] ?? null) ? $response['data'] : [];
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$total = (int) ($data['totalCount'] ?? 0);
echo "API totalCount: {$total}\n";
echo 'API page items: ' . count($items) . "\n";

$zeroQty = 0;
$missingQty = 0;
$positiveQty = 0;
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (!array_key_exists('warehouseQuantity', $item) && !array_key_exists('WarehouseQuantity', $item)) {
        $missingQty++;
        continue;
    }
    $qty = (float) ($item['warehouseQuantity'] ?? $item['WarehouseQuantity'] ?? 0);
    if ($qty > 0) {
        $positiveQty++;
    } else {
        $zeroQty++;
        $code = (string) ($item['materialCode'] ?? $item['code'] ?? $item['guid'] ?? '?');
        echo "  zero-qty leak candidate: {$code} qty={$qty}\n";
    }
}

echo "positive warehouseQuantity: {$positiveQty}\n";
echo "zero warehouseQuantity: {$zeroQty}\n";
echo "missing warehouseQuantity: {$missingQty}\n";

$catalog = StoreCatalogService::catalogFromRequest([]);
$catalogProducts = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
echo "\nStore catalogFromRequest products: " . count($catalogProducts) . "\n";
echo 'catalog apiError: ' . (string) ($catalog['apiError'] ?? 'none') . "\n";

$catalogZero = 0;
foreach ($catalogProducts as $product) {
    if (!is_array($product)) {
        continue;
    }
    $qty = (float) ($product['warehouseQuantity'] ?? $product['WarehouseQuantity'] ?? 0);
    if ($qty <= 0) {
        $catalogZero++;
    }
}
echo "catalog products with warehouseQuantity<=0: {$catalogZero}\n";

if ($zeroQty > 0) {
    echo "\nNOTE: API returned items with qty=0 in allowed stores (old Any-row behavior).\n";
    echo "Portal local filter should drop them after deploy.\n";
}

echo "\nDone\n";
