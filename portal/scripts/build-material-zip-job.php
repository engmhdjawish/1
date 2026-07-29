<?php

declare(strict_types=1);

/**
 * Background worker for material image ZIP jobs (CLI — does not block PHP-FPM).
 *
 * Usage: php scripts/build-material-zip-job.php
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Config;
use Portal\Services\MaterialImageZipJobService;

$logDir = rtrim(Config::storagePath(), '/\\') . '/zip-jobs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
$logPath = $logDir . '/worker.log';

$log = static function (string $message) use ($logPath): void {
    @file_put_contents($logPath, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
};

try {
    $log('worker bootstrap');
    MaterialImageZipJobService::runWorker();
    $log('worker finished');
} catch (Throwable $exception) {
    $log('worker failed: ' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
