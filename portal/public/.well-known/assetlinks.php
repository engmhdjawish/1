<?php

declare(strict_types=1);

/**
 * Digital Asset Links for Google Play Trusted Web Activity (TWA).
 * Served at /.well-known/assetlinks.json (via nginx/IIS rewrite).
 *
 * Set PORTAL_ANDROID_PACKAGE_ID and PORTAL_ANDROID_SHA256_FINGERPRINTS in .env
 * (upload keystore SHA-256; Play App Signing may add a second fingerprint from Play Console).
 */
define('PORTAL_NO_SESSION', true);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Config;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, must-revalidate');
header('X-Content-Type-Options: nosniff');

$package = trim((string) (Config::get('PORTAL_ANDROID_PACKAGE_ID', 'sy.jawishco.portal') ?? 'sy.jawishco.portal'));
$rawFingerprints = trim((string) (Config::get('PORTAL_ANDROID_SHA256_FINGERPRINTS', '') ?? ''));

$fingerprints = [];
if ($rawFingerprints !== '') {
    foreach (preg_split('/[\s,;]+/', $rawFingerprints) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $normalized = strtoupper(str_replace([' ', '-'], ':', $part));
        if (!preg_match('/^(?:[0-9A-F]{2}:){19}[0-9A-F]{2}$/', $normalized)) {
            continue;
        }
        $fingerprints[] = $normalized;
    }
    $fingerprints = array_values(array_unique($fingerprints));
}

if ($package === '' || $fingerprints === []) {
    echo "[]\n";
    exit;
}

$statement = [
    'relation' => ['delegate_permission/common.handle_all_urls'],
    'target' => [
        'namespace' => 'android_app',
        'package_name' => $package,
        'sha256_cert_fingerprints' => $fingerprints,
    ],
];

echo json_encode([$statement], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";