<?php

declare(strict_types=1);

/**
 * تشخيص أداء Portal + API + طابور الصور (JSON).
 * Usage: php scripts/run-system-diagnostic.php
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Services\PortalSystemDiagnosticService;

$report = PortalSystemDiagnosticService::run();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

$severity = (string) ($report['severity'] ?? 'ok');
exit($severity === 'critical' ? 2 : ($severity === 'warning' ? 1 : 0));
