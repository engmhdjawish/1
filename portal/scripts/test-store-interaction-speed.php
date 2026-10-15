<?php

declare(strict_types=1);

/**
 * Cart and currency click-path checks that do not need Postgres.
 * Usage: php scripts/test-store-interaction-speed.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ShareCartService;
use Portal\Services\StoreCartService;
use Portal\Support\SessionGate;
use Portal\Support\StoreCartApi;
use Portal\Support\StorePricePreference;

$sessionDir = sys_get_temp_dir() . '/portal-session-speed-' . getmypid();
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    fwrite(STDERR, "FAIL: could not create session directory\n");
    exit(1);
}
session_save_path($sessionDir);
session_id(bin2hex(random_bytes(16)));
session_start();
ob_start();

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$assert('add looks up the material', StoreCartApi::actionNeedsMaterialLookup('add') === true);
$assert('add_to_cart looks up the material', StoreCartApi::actionNeedsMaterialLookup('add_to_cart') === true);
$assert('increasing bump looks up the material', StoreCartApi::actionNeedsMaterialLookup('bump', 1) === true);
$assert('decreasing bump skips the material lookup', StoreCartApi::actionNeedsMaterialLookup('bump', -1) === false);
$assert('remove skips the material lookup', StoreCartApi::actionNeedsMaterialLookup('remove') === false);

$root = dirname(__DIR__);
$filterApi = (string) file_get_contents($root . '/public/api/store-filter-options.php');
$releaseAt = strpos($filterApi, 'SessionGate::release()');
$payloadAt = strpos($filterApi, 'getClientFiltersPayload');
$assert(
    'filter options release the session before catalog work',
    $releaseAt !== false && $payloadAt !== false && $releaseAt < $payloadAt
);

$cartApi = (string) file_get_contents($root . '/public/api/store-cart.php');
$assert(
    'read-only cart GET releases the session',
    str_contains($cartApi, 'SessionGate::release()')
);

$catalog = (string) file_get_contents($root . '/src/Services/StoreCatalogService.php');
$assert(
    'material lookup drops the session lock',
    str_contains($catalog, 'SessionGate::withoutLock')
);

$prefJs = (string) file_get_contents($root . '/public/assets/store-pref.js');
$clickAt = strpos($prefJs, "btn.addEventListener('click'");
$applyAt = $clickAt === false ? false : strpos($prefJs, 'applyCurrency(currency)', $clickAt);
$fetchAt = $clickAt === false ? false : strpos($prefJs, 'await fetch', $clickAt);
$assert(
    'currency UI updates before the preference request',
    $applyAt !== false && $fetchAt !== false && $applyAt < $fetchAt
);

$cartJs = (string) file_get_contents($root . '/public/assets/store-cart.js');
$removeAt = strpos($cartJs, "action: 'remove'");
$cardRemoveAt = $removeAt === false ? false : strrpos(substr($cartJs, 0, $removeAt), 'card?.remove()');
$assert('cart line is removed in the UI before the delete request', $cardRemoveAt !== false);
$assert('add paints the in-cart quantity before the request', str_contains($cartJs, 'setFormCartMode(form, nextQty)'));

$_SESSION['cart_probe'] = 'before';
$lockHeldInside = true;
SessionGate::withoutLock(static function () use (&$lockHeldInside): void {
    $lockHeldInside = session_status() === PHP_SESSION_ACTIVE;
    $_SESSION['during_lookup'] = 'discard-me';
});
$assert('session lock is released during material lookup', $lockHeldInside === false);
$assert('session value survives the unlocked lookup', ($_SESSION['cart_probe'] ?? '') === 'before');
$assert('writes during the unlocked lookup are not kept', !isset($_SESSION['during_lookup']));

$_SESSION['cart_probe'] = 'after';
session_write_close();
session_start();
$assert('cart session writes after resume are persisted', ($_SESSION['cart_probe'] ?? '') === 'after');

$assert('older currency click is ignored', StorePricePreference::claimWrite(20) === true);
$assert('claim stores the newer seq', StorePricePreference::claimWrite(10) === false);
$assert('a newer currency click is kept', StorePricePreference::claimWrite(30) === true);
$assert('clients without a seq still persist', StorePricePreference::claimWrite(0) === true);

$token = StoreCartService::TOKEN;
$_SESSION['share_carts'][$token] = [
    'items' => [
        'guid-speed' => [
            'material_guid' => 'guid-speed',
            'material_name_ar' => 'حذاء',
            'quantity' => 2,
            'packaging' => 1,
            'sale_price_sp' => 1000,
            'sale_price_usd' => 2,
        ],
    ],
];
$decreased = ShareCartService::updateQuantity($token, 'guid-speed', 1, null, false);
$assert('decrease persists without a stock lookup', ($decreased['ok'] ?? false) === true);
$assert(
    'decreased quantity is stored',
    (float) ($_SESSION['share_carts'][$token]['items']['guid-speed']['quantity'] ?? 0) === 1.0
);
$removed = ShareCartService::updateQuantity($token, 'guid-speed', 0, null, false);
$assert('delete persists without a stock lookup', ($removed['ok'] ?? false) === true);
$assert('deleted line is gone from the session cart', !isset($_SESSION['share_carts'][$token]['items']['guid-speed']));

session_write_close();

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " failed\n");
    exit(1);
}

echo "All store interaction speed checks passed\n";
