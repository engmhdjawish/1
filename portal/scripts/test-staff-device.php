<?php

declare(strict_types=1);

/**
 * Cookie round-trip for the staff-device marker. No database required.
 */

define('PORTAL_NO_SESSION', true);
require dirname(__DIR__) . '/bootstrap.php';

use Portal\Support\StaffDevice;

$pass = 0;
$fail = 0;
$ok = static function (string $message) use (&$pass): void {
    $pass++;
    echo "  ✓ {$message}\n";
};
$bad = static function (string $message) use (&$fail): void {
    $fail++;
    echo "  ✗ {$message}\n";
};

$staffId = '11111111-1111-4111-8111-111111111111';
unset($_COOKIE['jawish_staff']);
StaffDevice::remember($staffId);

echo "=== Staff device cookie ===\n";

$read = StaffDevice::id();
if ($read === $staffId) {
    $ok('signed cookie resolves to the staff id');
} else {
    $bad('cookie did not round-trip, got ' . var_export($read, true));
}

$_COOKIE['jawish_staff'] .= 'tampered';
if (StaffDevice::id() === null) {
    $ok('tampered cookie is ignored');
} else {
    $bad('tampered cookie was accepted');
}

$_COOKIE['jawish_staff'] = 'not-a-token';
if (StaffDevice::id() === null) {
    $ok('malformed cookie is ignored');
} else {
    $bad('malformed cookie was accepted');
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
