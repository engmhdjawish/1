<?php

declare(strict_types=1);

/**
 * Background worker for material image ZIP jobs (CLI — does not block PHP-FPM).
 *
 * Usage: php scripts/build-material-zip-job.php
 */

$base = dirname(__DIR__);
$logDir = $base . '/storage/zip-jobs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logPath = $logDir . '/worker.log';
@file_put_contents($logPath, '[' . date('c') . '] worker invoked' . PHP_EOL, FILE_APPEND);

define('PORTAL_NO_SESSION', true);

try {
    require $base . '/bootstrap.php';
} catch (Throwable $exception) {
    @file_put_contents(
        $logPath,
        '[' . date('c') . '] bootstrap failed: ' . $exception->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

use Portal\Services\MaterialImageZipJobService;

$log = static function (string $message) use ($logPath): void {
    @file_put_contents($logPath, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
};

try {
    $log('worker bootstrap ok');
    $queued = MaterialImageZipJobService::countQueuedJobs();
    $log('queued jobs: ' . $queued);
    MaterialImageZipJobService::runWorker();
    $log('worker finished');
} catch (Throwable $exception) {
    $log('worker failed: ' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
