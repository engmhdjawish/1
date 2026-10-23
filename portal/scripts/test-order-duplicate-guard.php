<?php

declare(strict_types=1);

/**
 * A repeated order submit must not create a second order.
 * Usage: php scripts/test-order-duplicate-guard.php   (needs Postgres)
 */

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Database;
use Portal\Services\OrderService;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$guidA = '11111111-2222-3333-4444-555555555555';
$guidB = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';

$assert(
    'fingerprint ignores order and GUID case',
    OrderService::orderFingerprint([
        ['material_guid' => $guidB, 'quantity' => 2],
        ['material_guid' => $guidA, 'quantity' => 1.5],
    ]) === OrderService::orderFingerprint([
        ['material_guid' => $guidA, 'quantity' => '1.5000'],
        ['material_guid' => strtolower($guidB), 'quantity' => 2],
    ])
);
$assert(
    'fingerprint differs when a quantity changes',
    OrderService::orderFingerprint([['material_guid' => $guidA, 'quantity' => 1]])
        !== OrderService::orderFingerprint([['material_guid' => $guidA, 'quantity' => 2]])
);

$pdo = Database::pdo();
$phone = '0999' . random_int(100000, 999999);
$pdo->beginTransaction();
try {
    $orderId = (string) $pdo->query(
        "INSERT INTO orders (order_number, guest_name_ar, guest_phone, status, quote_access_token)
         VALUES ('ORD-TEST-" . bin2hex(random_bytes(3)) . "', 'اختبار', '{$phone}', 'pending', '" . bin2hex(random_bytes(16)) . "')
         RETURNING id::text"
    )->fetchColumn();
    $pdo->exec(
        "INSERT INTO order_items (order_id, material_guid, material_name_ar, quantity) VALUES
         ('{$orderId}', '{$guidA}', 'مادة أ', 1.5),
         ('{$orderId}', '{$guidB}', 'مادة ب', 2)"
    );
    $pdo->commit();

    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE guest_phone = '{$phone}'")->fetchColumn();
    $repeat = OrderService::createGuestShareOrder('', 'اختبار', $phone, null, [
        ['material_guid' => $guidB, 'material_name_ar' => 'مادة ب', 'quantity' => 2],
        ['material_guid' => $guidA, 'material_name_ar' => 'مادة أ', 'quantity' => 1.5],
    ]);
    $countAfter = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE guest_phone = '{$phone}'")->fetchColumn();

    $assert('repeat submit succeeds', ($repeat['ok'] ?? false) === true);
    $assert('repeat submit is flagged as duplicate', !empty($repeat['duplicate']));
    $assert('repeat submit returns the existing order', ($repeat['order']['id'] ?? '') === $orderId);
    $assert('repeat submit does not insert a new order', $countAfter === $countBefore);
    $assert(
        'repeat submit clears the submitted lines from the cart',
        in_array($guidA, $repeat['submitted_material_guids'] ?? [], true) && in_array($guidB, $repeat['submitted_material_guids'] ?? [], true)
    );

    $pdo->exec("UPDATE orders SET created_at = NOW() - INTERVAL '1 hour' WHERE id = '{$orderId}'");
    $method = new ReflectionMethod(OrderService::class, 'findRecentDuplicateOrder');
    $stale = $method->invoke(null, $pdo, $phone, '', [
        ['material_guid' => $guidA, 'quantity' => 1.5],
        ['material_guid' => $guidB, 'quantity' => 2],
    ]);
    $assert('an old identical order is not treated as a repeat', $stale === null);

    $pdo->exec("UPDATE orders SET created_at = NOW(), status = 'cancelled' WHERE id = '{$orderId}'");
    $cancelled = $method->invoke(null, $pdo, $phone, '', [
        ['material_guid' => $guidA, 'quantity' => 1.5],
        ['material_guid' => $guidB, 'quantity' => 2],
    ]);
    $assert('a cancelled order is not treated as a repeat', $cancelled === null);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("DELETE FROM orders WHERE guest_phone = '{$phone}'");
}

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/assets/store-cart.js');
$assert('checkout button is disabled while sending', str_contains($js, "form.dataset.submitting === '1'") && str_contains($js, 'btn.disabled = true'));
$assert('checkout stays locked while redirecting', str_contains($js, 'leaving = true'));
$cart = (string) file_get_contents($root . '/public/cart.php');
$assert('share cart form submits once', str_contains($cart, 'data-once-submit') && str_contains($cart, 'share_last_order'));
$request = (string) file_get_contents($root . '/src/Support/StoreCartRequest.php');
$assert('empty cart right after an order replays that order', str_contains($request, 'recentSubmitReplay()'));

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}
echo "\nOrder duplicate guard ok\n";
