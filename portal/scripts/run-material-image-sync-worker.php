<?php

declare(strict_types=1);

/**
 * Background worker: Amine sync queue + auto-retry + repair missing local copies.
 *
 * Usage: php scripts/run-material-image-sync-worker.php
 * Cron:  deploy/templates/portal/material-image-sync-worker.cron
 */

$base = dirname(__DIR__);

define('PORTAL_NO_SESSION', true);

if (function_exists('proc_nice')) {
    @proc_nice(10);
}

require $base . '/bootstrap.php';

use Portal\Services\MaterialImageBackgroundWorkerService;

$log = static function (string $message): void {
    MaterialImageBackgroundWorkerService::appendLog($message);
    echo $message . PHP_EOL;
};

try {
    $result = MaterialImageBackgroundWorkerService::runTick($log);
    if (!($result['ok'] ?? false) && !($result['skipped'] ?? false)) {
        exit(1);
    }
} catch (Throwable $exception) {
    $log('fatal: ' . $exception->getMessage());
    exit(1);
}
