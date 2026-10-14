<?php

declare(strict_types=1);

/**
 * Smoke test for PortalSystemDiagnosticService.
 * Usage: php scripts/test-system-diagnostic.php
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Services\PortalSystemDiagnosticService;

$fail = 0;

$report = PortalSystemDiagnosticService::run();
if (!is_array($report)) {
    fwrite(STDERR, "FAIL: run() did not return array\n");
    exit(1);
}

foreach (['generated_at', 'duration_ms', 'severity', 'headline_ar', 'checks', 'recommendations', 'execution_sapi'] as $key) {
    if (!array_key_exists($key, $report)) {
        fwrite(STDERR, "FAIL: missing key {$key}\n");
        $fail++;
    }
}

$checks = $report['checks'] ?? null;
if (!is_array($checks) || $checks === []) {
    fwrite(STDERR, "FAIL: checks empty\n");
    $fail++;
}

$ids = [];
foreach (is_array($checks) ? $checks : [] as $check) {
    if (!is_array($check)) {
        continue;
    }
    $id = (string) ($check['id'] ?? '');
    if ($id !== '') {
        $ids[] = $id;
    }
}

foreach (['php_runtime', 'php_fpm_web', 'database', 'api_health', 'api_materials'] as $required) {
    if (!in_array($required, $ids, true)) {
        fwrite(STDERR, "FAIL: missing check id {$required}\n");
        $fail++;
    }
}

$severity = (string) ($report['severity'] ?? '');
if (!in_array($severity, ['ok', 'warning', 'critical', 'info'], true)) {
    fwrite(STDERR, "FAIL: invalid severity {$severity}\n");
    $fail++;
}

if ($fail > 0) {
    exit(1);
}

echo 'OK system diagnostic (' . count($ids) . " checks, severity={$severity})\n";
exit(0);
