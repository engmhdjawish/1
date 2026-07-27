<?php

declare(strict_types=1);

/**
 * Usage: php scripts/test-api-query-string.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\ApiClient;

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$guids = [
    '2543e24d-3213-46d6-8b9c-4a2f641a65e9',
    '51f06a01-dc00-4ae0-a5e3-42c852447fa5',
];
$qs = ApiClient::buildQueryString([
    'storeGuids' => implode(',', $guids),
    'isAvailable' => 'true',
    'page' => 1,
]);

$assert('keeps literal commas between GUIDs', str_contains($qs, $guids[0] . ',' . $guids[1]));
$assert('does not encode commas as %2C in GUID CSV', !str_contains($qs, '%2C'));
$assert('includes isAvailable', str_contains($qs, 'isAvailable=true'));

$legacy = http_build_query(['storeGuids' => implode(',', $guids)]);
$assert('legacy http_build_query encodes commas (documents the bug)', str_contains($legacy, '%2C'));

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}
echo "All checks passed\n";
