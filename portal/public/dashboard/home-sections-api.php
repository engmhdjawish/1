<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;

header('Content-Type: application/json; charset=utf-8');

WebSession::requireAnyPermission(['home_sections.manage', 'special_offers.manage', 'price_checker.manage']);

$q = trim((string) ($_GET['q'] ?? ''));
$guidParam = strtolower(trim((string) ($_GET['guid'] ?? '')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = max(1, min(48, (int) ($_GET['pageSize'] ?? 24)));

$nullableNumber = static function (mixed $value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }

    return (float) $value;
};

/** @param array<string, mixed> $row */
$mapMaterialRow = static function (array $row) use ($nullableNumber): ?array {
    $guid = strtolower(trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? $row['guid'] ?? $row['Guid'] ?? '')));
    if ($guid === '') {
        return null;
    }
    $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
    $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? $row['code'] ?? $row['Code'] ?? ''));
    $label = $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $guid;
    $imageGuid = trim((string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? $row['pictureGuid'] ?? $row['PictureGuid'] ?? ''));
    if ($imageGuid === '00000000-0000-0000-0000-000000000000') {
        $imageGuid = '';
    }
    // Half = sale USD, Whole = sale SYP, EndUser = purchase USD (Jawish card).
    $saleUsd = $nullableNumber(
        $row['unitSalePriceUsd'] ?? $row['UnitSalePriceUsd'] ?? $row['half'] ?? $row['Half'] ?? null
    );
    $saleSyp = $nullableNumber(
        $row['unitSalePriceSyp'] ?? $row['UnitSalePriceSyp'] ?? $row['whole'] ?? $row['Whole'] ?? null
    );
    $purchaseUsd = $nullableNumber(
        $row['unitPurchasePriceUsd'] ?? $row['UnitPurchasePriceUsd'] ?? $row['endUser'] ?? $row['EndUser'] ?? null
    );
    $currencyVal = $nullableNumber($row['currencyVal'] ?? $row['CurrencyVal'] ?? null);
    $warehouseQty = $nullableNumber(
        $row['warehouseQuantity'] ?? $row['WarehouseQuantity'] ?? $row['warehouse_quantity'] ?? null
    );
    $packaging = $nullableNumber(
        $row['packageConversionFactor']
        ?? $row['PackageConversionFactor']
        ?? $row['unit2Fact']
        ?? $row['Unit2Fact']
        ?? $row['packaging']
        ?? null
    );
    if ($packaging === null || $packaging <= 0) {
        $packaging = 1.0;
    }
    $availablePackages = $warehouseQty !== null
        ? max(0.0, round($warehouseQty / $packaging, 4))
        : null;

    return [
        'value' => $guid,
        'label' => $label,
        'code' => $code,
        'name' => $name,
        'image' => $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '',
        'unitSalePriceUsd' => $saleUsd,
        'unitSalePriceSyp' => $saleSyp,
        'unitPurchasePriceUsd' => $purchaseUsd,
        'currencyVal' => $currencyVal,
        'warehouseQuantity' => $warehouseQty,
        'packageConversionFactor' => $packaging,
        'availablePackages' => $availablePackages,
        'half' => $saleUsd,
        'whole' => $saleSyp,
        'endUser' => $purchaseUsd,
    ];
};

// Single-material enrich (prices for an already-picked GUID).
if ($guidParam !== '') {
    try {
        $response = ApiClient::get('/api/materials/' . rawurlencode($guidParam));
        if (!$response['ok'] || !is_array($response['data'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'المادة غير موجودة.', 'item' => null], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $item = $mapMaterialRow($response['data']);
        echo json_encode(['ok' => $item !== null, 'item' => $item], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'تعذر جلب تفاصيل المادة.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($q === '') {
    echo json_encode([
        'ok' => true,
        'items' => [],
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 0,
        'hasMore' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
$total = 0;
$hasMore = false;

try {
    $response = ApiClient::get('/api/materials', [
        'page' => $page,
        'pageSize' => $pageSize,
        'keyword' => $q,
    ]);
    if ($response['ok']) {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        $total = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $mapMaterialRow($row);
            if ($mapped === null) {
                continue;
            }
            $items[] = $mapped;
        }
        $hasMore = ($page * $pageSize) < $total;
    }
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر البحث عن المواد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'items' => $items,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'hasMore' => $hasMore,
], JSON_UNESCAPED_UNICODE);
