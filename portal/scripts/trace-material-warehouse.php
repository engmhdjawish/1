<?php

declare(strict_types=1);

/**
 * Trace why a material appears in the store under the guest warehouse policy.
 *
 * Usage:
 *   php scripts/trace-material-warehouse.php 76123
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\AccessPolicyService;
use Portal\Services\ApiClient;
use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;

$code = trim((string) ($argv[1] ?? ''));
if ($code === '') {
    fwrite(STDERR, "Usage: php scripts/trace-material-warehouse.php <materialCode>\n");
    exit(1);
}

echo "=== Trace material {$code} vs warehouse policy ===\n\n";

$guest = StorePolicyService::guestPolicy();
if ($guest === null) {
    echo "FAIL: no guest policy configured\n";
    exit(1);
}

$policyId = trim((string) ($guest['id'] ?? ''));
$policyName = trim((string) ($guest['name_ar'] ?? ''));
$parsed = AccessPolicyService::parsedFiltersForPolicyId($policyId);
$rules = is_array($parsed['rules'] ?? null) ? $parsed['rules'] : [];
$policyStoreGuids = array_values(array_map('strval', is_array($rules['store_guids'] ?? null) ? $rules['store_guids'] : []));
$policyStoreMap = [];
foreach ($policyStoreGuids as $guid) {
    $policyStoreMap[strtolower($guid)] = true;
}

echo "Guest policy: {$policyName}\n";
echo "Policy id: {$policyId}\n";
echo 'Allowed warehouses: ' . count($policyStoreGuids) . "\n";
foreach ($policyStoreGuids as $guid) {
    echo "  - {$guid}\n";
}
echo "\n";

$unscoped = ApiClient::get('/api/materials', [
    'search' => $code,
    'page' => 1,
    'pageSize' => 10,
    'includeTotalCount' => 'false',
], 20);

if (!($unscoped['ok'] ?? false)) {
    echo 'FAIL: unscoped search status=' . (int) ($unscoped['status'] ?? 0) . ' ' . (string) ($unscoped['error'] ?? '') . "\n";
    exit(1);
}

$unscopedItems = is_array($unscoped['data']['items'] ?? null) ? $unscoped['data']['items'] : [];
$match = null;
foreach ($unscopedItems as $item) {
    if (!is_array($item)) {
        continue;
    }
    $itemCode = trim((string) ($item['materialCode'] ?? $item['MaterialCode'] ?? $item['code'] ?? ''));
    $itemNumber = trim((string) ($item['recordNumber'] ?? $item['RecordNumber'] ?? $item['number'] ?? ''));
    if ($itemCode === $code || $itemNumber === $code) {
        $match = $item;
        break;
    }
}
if ($match === null && $unscopedItems !== [] && is_array($unscopedItems[0])) {
    $match = $unscopedItems[0];
    echo "WARN: exact code match not found; using first search hit\n";
}
if ($match === null) {
    echo "FAIL: material not found via /api/materials?search={$code}\n";
    exit(1);
}

$guid = trim((string) ($match['guid'] ?? $match['Guid'] ?? $match['materialGuid'] ?? $match['MaterialGuid'] ?? ''));
$name = trim((string) ($match['name'] ?? $match['Name'] ?? ''));
$materialCode = trim((string) ($match['materialCode'] ?? $match['MaterialCode'] ?? ''));
$totalQty = $match['warehouseQuantity'] ?? $match['WarehouseQuantity'] ?? null;

echo "Material found\n";
echo "  code: {$materialCode}\n";
echo "  name: {$name}\n";
echo "  guid: {$guid}\n";
echo '  warehouseQuantity (no store filter): ' . ($totalQty === null ? 'n/a' : (string) $totalQty) . "\n\n";

if ($policyStoreGuids === []) {
    echo "ROOT CAUSE: guest policy has ZERO store_guids.\n";
    echo "The store is not warehouse-scoped, so any material with total stock can appear.\n";
    exit(0);
}

$scoped = ApiClient::get('/api/materials', [
    'search' => $code,
    'storeGuids' => implode(',', $policyStoreGuids),
    'isAvailable' => 'true',
    'page' => 1,
    'pageSize' => 10,
    'includeTotalCount' => 'false',
], 20);

$scopedItems = ($scoped['ok'] ?? false) && is_array($scoped['data']['items'] ?? null)
    ? $scoped['data']['items']
    : [];
$scopedMatch = null;
foreach ($scopedItems as $item) {
    if (!is_array($item)) {
        continue;
    }
    $itemGuid = strtolower(trim((string) ($item['guid'] ?? $item['Guid'] ?? $item['materialGuid'] ?? $item['MaterialGuid'] ?? '')));
    if ($itemGuid !== '' && $itemGuid === strtolower($guid)) {
        $scopedMatch = $item;
        break;
    }
}

echo "API with policy storeGuids + isAvailable=true\n";
if (!($scoped['ok'] ?? false)) {
    echo '  FAIL status=' . (int) ($scoped['status'] ?? 0) . "\n";
} elseif ($scopedMatch === null) {
    echo "  NOT RETURNED — material should be hidden by warehouse policy.\n";
    echo "  If it still appears in the store UI, the catalog path is not sending storeGuids (or cache is stale).\n";
} else {
    $scopedQty = $scopedMatch['warehouseQuantity'] ?? $scopedMatch['WarehouseQuantity'] ?? null;
    echo '  RETURNED with warehouseQuantity=' . ($scopedQty === null ? 'n/a' : (string) $scopedQty) . "\n";
    echo "  Verdict: material has positive stock inside allowed warehouses (inclusion rule).\n";
    echo "  Showing it is expected; quantity should reflect allowed stores only.\n";
}
echo "\n";

if ($guid !== '') {
    $byGuidScoped = ApiClient::get('/api/materials/' . rawurlencode($guid), [
        'storeGuids' => implode(',', $policyStoreGuids),
    ], 15);
    echo "GET /api/materials/{guid}?storeGuids=policy\n";
    if (!($byGuidScoped['ok'] ?? false)) {
        echo '  status=' . (int) ($byGuidScoped['status'] ?? 0) . " (404 means no stock in allowed stores)\n";
    } else {
        $q = $byGuidScoped['data']['warehouseQuantity'] ?? $byGuidScoped['data']['WarehouseQuantity'] ?? null;
        echo '  warehouseQuantity=' . ($q === null ? 'n/a' : (string) $q) . "\n";
    }
    echo "\n";

    $inventory = ApiClient::get('/api/materials/' . rawurlencode($guid) . '/store-quantities', [], 20);
    echo "Per-store inventory (/store-quantities)\n";
    if (!($inventory['ok'] ?? false)) {
        echo '  endpoint unavailable status=' . (int) ($inventory['status'] ?? 0) . " — deploy latest API to enable this breakdown\n";
    } else {
        $rows = is_array($inventory['data'] ?? null) ? $inventory['data'] : [];
        $allowedSum = 0.0;
        $excludedSum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $storeGuid = trim((string) ($row['storeGuid'] ?? $row['StoreGuid'] ?? ''));
            $storeName = trim((string) ($row['storeName'] ?? $row['StoreName'] ?? $storeGuid));
            $qty = (float) ($row['quantity'] ?? $row['Quantity'] ?? 0);
            $allowed = isset($policyStoreMap[strtolower($storeGuid)]);
            $flag = $allowed ? 'ALLOWED' : 'EXCLUDED';
            if ($allowed) {
                $allowedSum += $qty;
            } else {
                $excludedSum += $qty;
            }
            if ($qty == 0.0) {
                continue;
            }
            echo sprintf("  [%s] %s = %s\n", $flag, $storeName, rtrim(rtrim(sprintf('%.4F', $qty), '0'), '.'));
        }
        echo "  SUM allowed: {$allowedSum}\n";
        echo "  SUM excluded: {$excludedSum}\n";
        if ($allowedSum > 0) {
            echo "  ROOT CAUSE: material has stock in allowed warehouses ({$allowedSum}) — store correctly shows it; displayed qty should be {$allowedSum}.\n";
        } elseif ($excludedSum > 0) {
            echo "  ROOT CAUSE: stock only in excluded warehouses — must NOT appear. If it appears, storeGuids are not applied on the catalog request/API.\n";
        } else {
            echo "  ROOT CAUSE: no positive inventory rows found in vwMaterialInventory.\n";
        }
    }
    echo "\n";
}

$foundViaCatalog = StoreCatalogService::findMaterial($guid);
echo "StoreCatalogService::findMaterial (policy-aware)\n";
if ($foundViaCatalog === null) {
    echo "  null — hidden for store detail/search under current policy\n";
} else {
    $q = $foundViaCatalog['warehouseQuantity'] ?? $foundViaCatalog['WarehouseQuantity'] ?? null;
    echo '  found, warehouseQuantity=' . ($q === null ? 'n/a' : (string) $q) . "\n";
}

echo "\nDone\n";
