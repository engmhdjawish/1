<?php

declare(strict_types=1);

/**
 * Pure math checks for procurement carton logic (no DB/API).
 */

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$pcs = 12.0;
$warehouse = 50.0;
$reserved = 10.0;
$availablePrimary = max(0.0, $warehouse - $reserved);
$availableCartons = $availablePrimary / $pcs;
$min = 4.0;
$max = 20.0;

if ($availableCartons <= $min) {
    $needPrimary = max(0.0, $max * $pcs - $availablePrimary);
    $orderCartons = (int) ceil($needPrimary / $pcs);
} else {
    $orderCartons = 0;
}

// available = 40/12 ≈ 3.33 <= 4 → order to reach max 20: need 240-40=200 primary → ceil(200/12)=17
if ($orderCartons !== 17) {
    $fail('expected 17 cartons, got ' . $orderCartons);
}

if ($availableCartons > $min) {
    $fail('should trigger shortage');
}

fwrite(STDOUT, "OK procurement shortage math\n");
exit(0);
