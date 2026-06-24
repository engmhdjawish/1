<?php

declare(strict_types=1);

/**
 * Test Amine API health + service login (same as store uses).
 * Usage: php scripts/test-amine-api-login.php
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Config;
use Portal\Services\ApiClient;
use Portal\Services\PortalSettingsService;

$baseUrl = rtrim((string) Config::get('AMINE_API_BASE_URL', ''), '/');
$user = (string) Config::get('AMINE_API_USERNAME', '');
$passSet = trim((string) Config::get('AMINE_API_PASSWORD', '')) !== '';

echo "AMINE_API_BASE_URL: {$baseUrl}\n";
echo "AMINE_API_USERNAME: {$user}\n";
echo 'AMINE_API_PASSWORD: ' . ($passSet ? '(set)' : '(missing)') . "\n\n";

$health = PortalSettingsService::apiHealth();
echo 'Health (/api/health): ' . (($health['ok'] ?? false) ? 'OK' : 'FAIL') . "\n";
echo '  ' . ($health['message'] ?? '') . "\n\n";

if (!$passSet || $user === '') {
    fwrite(STDERR, "Set AMINE_API_USERNAME and AMINE_API_PASSWORD in .env\n");
    exit(1);
}

try {
    $materials = ApiClient::get('/api/materials', ['page' => 1, 'pageSize' => 1]);
    if ($materials['ok'] ?? false) {
        echo "Login + materials.read: OK\n";
        exit(0);
    }
    echo "Login worked but materials request failed:\n";
    echo json_encode($materials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Login failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "\nCreate ApiManagementDb user (e.g. portal-service) with materials.read.\n");
    fwrite(STDERR, "Do NOT use PostgreSQL admin here.\n");
    exit(1);
}
