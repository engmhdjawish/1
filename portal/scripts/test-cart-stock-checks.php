<?php

declare(strict_types=1);

/**
 * Stock checks on order submit: fresh reservations under the lock, one warehouse lookup per material.
 * Usage: php scripts/test-cart-stock-checks.php   (needs Postgres)
 */

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Database;
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

$guid = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
$upper = strtoupper($guid);
$pdo = Database::pdo();
$phone = '0988' . random_int(100000, 999999);

StockReservationService::forgetWarehouseMemo();
StockReservationService::seedWarehousePrimary($guid, 10);
$assert('memoised warehouse figure is reused', StockReservationService::fetchWarehousePrimary($upper) === 10.0);
$assert('nothing reserved yet', StockReservationService::reservedPrimaryFor($upper) === 0.0);

try {
    $orderId = (string) $pdo->query(
        "INSERT INTO orders (order_number, guest_name_ar, guest_phone, status, quote_access_token)
         VALUES ('ORD-STK-" . bin2hex(random_bytes(3)) . "', 'اختبار', '{$phone}', 'pending', '" . bin2hex(random_bytes(16)) . "')
         RETURNING id::text"
    )->fetchColumn();
    $pdo->exec("INSERT INTO order_items (order_id, material_guid, material_name_ar, quantity, pcs_per_box) VALUES ('{$orderId}', '{$guid}', 'مادة', 8, 1)");

    $split = StockReservationService::splitCartByAvailability([
        ['material_guid' => $upper, 'material_name_ar' => 'مادة', 'quantity' => 5, 'pcs_per_box' => 1],
    ], true);
    $line = $split['available'][0] ?? null;
    $assert('re-check sees an order placed after the first read', is_array($line) && abs((float) $line['quantity'] - 2.0) < 0.0001);
    $assert('reservations match upper-case GUIDs', (StockReservationService::reservedPrimaryByMaterial([$upper])[$upper] ?? 0.0) === 8.0);

    $pdo->exec("INSERT INTO order_items (order_id, material_guid, material_name_ar, quantity, pcs_per_box) VALUES ('{$orderId}', '{$guid}', 'مادة', 2, 1)");
    $sold = StockReservationService::splitCartByAvailability([
        ['material_guid' => $guid, 'material_name_ar' => 'مادة', 'quantity' => 1, 'pcs_per_box' => 1],
    ], true);
    $assert('sold-out material is refused on re-check', $sold['available'] === [] && count($sold['unavailable']) === 1);
} finally {
    $pdo->exec("DELETE FROM orders WHERE guest_phone = '{$phone}'");
    StockReservationService::forgetWarehouseMemo();
}

$root = dirname(__DIR__);
$share = (string) file_get_contents($root . '/src/Services/ShareCartService.php');
$assert('cart stock check prefetches all lines in parallel', str_contains($share, 'StockReservationService::prefetchWarehousePrimary('));
$pricing = (string) file_get_contents($root . '/src/Services/StoreCartPricingService.php');
$assert('price re-check loads all lines in one batch', str_contains($pricing, 'StoreCatalogService::prefetchMaterials('));

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}
echo "\nCart stock checks ok\n";
