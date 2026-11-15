<?php

declare(strict_types=1);

/**
 * Smoke test for /.well-known/assetlinks.php output shape.
 * Run: php scripts/test-android-assetlinks.php
 */
$_ENV['PORTAL_ANDROID_PACKAGE_ID'] = 'sy.jawishco.portal';
$_ENV['PORTAL_ANDROID_SHA256_FINGERPRINTS'] = 'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99';
putenv('PORTAL_ANDROID_PACKAGE_ID=sy.jawishco.portal');
putenv('PORTAL_ANDROID_SHA256_FINGERPRINTS=' . $_ENV['PORTAL_ANDROID_SHA256_FINGERPRINTS']);

$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require dirname(__DIR__) . '/public/.well-known/assetlinks.php';
$body = ob_get_clean();

$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data) || count($data) !== 1) {
    fwrite(STDERR, "Expected one JSON object in array.\n");
    exit(1);
}
$target = $data[0]['target'] ?? [];
if (($target['package_name'] ?? '') !== 'sy.jawishco.portal') {
    fwrite(STDERR, "Unexpected package_name.\n");
    exit(1);
}
if (!is_array($target['sha256_cert_fingerprints'] ?? null) || count($target['sha256_cert_fingerprints']) !== 1) {
    fwrite(STDERR, "Expected one fingerprint.\n");
    exit(1);
}

fwrite(STDOUT, "ok\n");