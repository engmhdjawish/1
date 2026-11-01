<?php

declare(strict_types=1);

/**
 * Cron-friendly sync of Amine material GUIDs into procurement_materials.
 *
 * Usage: php scripts/procurement-sync.php
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Services\ProcurementService;

if (!ProcurementService::tableReady()) {
    fwrite(STDERR, "procurement tables missing — run migration 030-procurement.sql\n");
    exit(1);
}

$result = ProcurementService::syncMaterialsFromAmine(null);
fwrite(STDOUT, ($result['message'] ?? '') . PHP_EOL);
exit($result['ok'] ? 0 : 1);
