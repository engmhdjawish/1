<?php

declare(strict_types=1);

/**
 * Reservation sync decisions that must stay true without a live API or SQL Server.
 * Usage: php scripts/test-amine-reservation-sync.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\AmineReservationSyncService;
use Portal\Services\OrderService;
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

echo "=== Ameen reservation sync ===\n\n";

$billGuid = '22222222-2222-2222-2222-222222222222';

$assert(
    'only automatic and manual are accepted, and the default is manual',
    AmineReservationSyncService::normalizeMode(null) === 'manual'
        && AmineReservationSyncService::normalizeMode(' ') === 'manual'
        && AmineReservationSyncService::normalizeMode('live') === 'manual'
        && AmineReservationSyncService::normalizeMode('automatic') === 'automatic'
        && AmineReservationSyncService::shouldInvokeApi('manual') === false
        && AmineReservationSyncService::shouldInvokeApi('automatic') === true
);

$assert(
    'manual mode keeps a new order pending without requiring a bill guid',
    AmineReservationSyncService::localSyncStatusAfterChange('pending', null) === 'pending'
        && AmineReservationSyncService::portalReservesOrder('pending', 'pending', null) === true
        && AmineReservationSyncService::operationFor('pending', null, 2) === 'create'
);

$failedCreate = AmineReservationSyncService::applyRemoteResult(
    'pending',
    'create',
    null,
    null,
    false,
    null,
    null,
    'تعذر الاتصال بخدمة الأمين.'
);
$assert(
    'automatic create failure keeps the portal reservation and records failed',
    $failedCreate['amine_sync_status'] === 'failed'
        && $failedCreate['amine_bill_guid'] === null
        && $failedCreate['order_status'] === 'pending'
        && $failedCreate['portal_reserves'] === true
        && $failedCreate['touch_synced_at'] === false
);

$synced = AmineReservationSyncService::applyRemoteResult(
    'pending',
    'create',
    null,
    null,
    true,
    $billGuid,
    15,
    null
);
$assert(
    'successful sync stores the bill and releases the portal reservation',
    $synced['amine_sync_status'] === 'synced'
        && $synced['amine_bill_guid'] === $billGuid
        && $synced['amine_bill_number'] === 15
        && $synced['amine_sync_error_ar'] === null
        && $synced['order_status'] === 'pending'
        && $synced['portal_reserves'] === false
        && $synced['touch_synced_at'] === true
);

$failedUpdate = AmineReservationSyncService::applyRemoteResult(
    'confirmed',
    'update',
    $billGuid,
    15,
    false,
    null,
    null,
    'فشلت مزامنة فاتورة الحجز مع الأمين.'
);
$assert(
    'a failed update keeps the previous bill and does not reserve the whole order again',
    $failedUpdate['amine_sync_status'] === 'failed'
        && $failedUpdate['amine_bill_guid'] === $billGuid
        && $failedUpdate['amine_bill_number'] === 15
        && $failedUpdate['order_status'] === 'confirmed'
        && $failedUpdate['portal_reserves'] === false
);

$deleted = AmineReservationSyncService::applyRemoteResult(
    'cancelled',
    'delete',
    $billGuid,
    15,
    true,
    $billGuid,
    15,
    null
);
$assert(
    'a successful delete clears the bill guid and releases any portal reservation',
    $deleted['amine_sync_status'] === 'synced'
        && $deleted['amine_bill_guid'] === null
        && $deleted['order_status'] === 'cancelled'
        && $deleted['portal_reserves'] === false
);

$assert(
    'a manual edit after a previous sync is pending again and stays off the portal hold',
    AmineReservationSyncService::localSyncStatusAfterChange('pending', $billGuid) === 'pending'
        && AmineReservationSyncService::portalReservesOrder('pending', 'pending', $billGuid) === false
        && AmineReservationSyncService::operationFor('pending', $billGuid, 1) === 'update'
);

$assert(
    'cancelling an order that was never synced does not ask for a bill',
    AmineReservationSyncService::localSyncStatusAfterChange('cancelled', null) === 'none'
        && AmineReservationSyncService::operationFor('cancelled', null, 0) === 'skip'
        && AmineReservationSyncService::portalReservesOrder('cancelled', 'none', null) === false
);

$material = '33333333-3333-3333-3333-333333333333';
$lines = AmineReservationSyncService::linesForApi([
    [
        'material_guid' => $material,
        'material_code' => 'A-1',
        'quantity' => 2,
        'sale_price_usd' => 12.5,
        'status' => 'active',
    ],
    [
        'material_guid' => strtoupper($material),
        'quantity' => 1,
        'sale_price_usd' => 4,
        'is_cancelled' => false,
    ],
    [
        'material_guid' => '44444444-4444-4444-4444-444444444444',
        'quantity' => 9,
        'sale_price_usd' => 3,
        'is_cancelled' => true,
    ],
]);
$assert(
    'API lines use the package payload shape and skip cancelled items',
    ($lines['ok'] ?? false) === true
        && count($lines['lines']) === 1
        && $lines['lines'][0]['materialGuid'] === $material
        && abs($lines['lines'][0]['quantity'] - 3.0) < 0.0001
        && abs($lines['lines'][0]['unitPrice'] - 12.5) < 0.0001
        && OrderService::amineSyncPackagePriceUsd(['sale_price_usd' => 12.5]) === 12.5
);

$assert(
    'owner name comes from the order customer',
    AmineReservationSyncService::ownerName([
        'customer_name_ar' => 'أحمد',
        'guest_name_ar' => 'ضيف',
    ], 'orderCustomerName') === 'أحمد'
        && AmineReservationSyncService::ownerName(['guest_name_ar' => 'ضيف'], 'orderCustomerName') === 'ضيف'
);

$arabic = AmineReservationSyncService::arabicFailureMessage(0, 'connection timed out');
$assert(
    'sync failures are stored in Arabic',
    str_contains($arabic, 'تعذر الاتصال')
        && AmineReservationSyncService::arabicFailureMessage(409, 'تعذر الحفظ') === 'تعذر الحفظ'
);

$holdSql = StockReservationService::portalHoldFilter('o');
$stockSource = (string) file_get_contents(dirname(__DIR__) . '/src/Services/StockReservationService.php');
$assert(
    'portal stock hold stops once Ameen has the bill',
    str_contains($holdSql, "amine_sync_status <> 'synced'")
        && str_contains($holdSql, 'amine_bill_guid IS NULL')
        && str_contains($holdSql, "status IN ('pending', 'confirmed')")
        && str_contains($stockSource, 'self::portalHoldFilter(')
);

$orderSource = (string) file_get_contents(dirname(__DIR__) . '/src/Services/OrderService.php');
$schema = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/portal-db-schema.sql');
$assert(
    'no new order status was added',
    str_contains($orderSource, "private const ALLOWED_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled'];")
        && str_contains($schema, "CREATE TYPE order_status AS ENUM ('pending', 'confirmed', 'completed', 'cancelled');")
        && !str_contains($orderSource, 'لا يمكن الإلغاء لأن الطلب قيد المزامنة')
);

$settingsView = (string) file_get_contents(dirname(__DIR__) . '/views/dashboard/settings.php');
$accounting = (string) file_get_contents(dirname(__DIR__) . '/public/dashboard/accounting-sync.php');
$assert(
    'sync mode is chosen on the reservation settings tab and the queue does not sync itself',
    str_contains($settingsView, 'name="sync_mode"')
        && str_contains($settingsView, 'value="manual"')
        && str_contains($settingsView, 'value="automatic"')
        && !str_contains($accounting, 'sync_now')
        && str_contains($accounting, 'لا ترسل الفواتير بنفسها')
);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "All checks passed.\n";
