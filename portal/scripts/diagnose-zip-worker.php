<?php

declare(strict_types=1);

/**
 * Diagnose material ZIP background worker setup.
 * Usage: php scripts/diagnose-zip-worker.php
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Config;
use Portal\Database;
use Portal\Services\MaterialImageZipJobService;

$portalRoot = $base;
$script = $portalRoot . '/scripts/build-material-zip-job.php';
$envFile = $portalRoot . '/.env';
$jobsDir = rtrim(Config::storagePath(), '/\\') . '/zip-jobs';
$phpCandidates = ['/usr/bin/php8.5', '/usr/bin/php8.4', '/usr/bin/php', 'php'];

echo "=== Material ZIP worker diagnostics ===\n";
echo 'Portal root: ' . $portalRoot . "\n";
echo 'Storage: ' . Config::storagePath() . "\n";
echo 'Jobs dir: ' . $jobsDir . "\n\n";

echo '[1] Worker script: ' . (is_file($script) ? 'OK' : 'MISSING') . "\n";
echo '[2] .env file: ' . (is_file($envFile) ? 'OK' : 'MISSING');
if (is_file($envFile)) {
    echo is_readable($envFile) ? ' (readable)' : ' (NOT readable by current user)';
}
echo "\n";

$phpBin = null;
foreach ($phpCandidates as $candidate) {
    if ($candidate === 'php' || is_executable($candidate)) {
        $phpBin = $candidate;
        break;
    }
}
echo '[3] PHP CLI: ' . ($phpBin ?? 'NOT FOUND') . "\n";

if (!is_dir($jobsDir)) {
    echo '[4] Jobs dir: missing (will try create)... ';
    if (@mkdir($jobsDir, 0775, true)) {
        echo "created\n";
    } else {
        echo "FAILED\n";
    }
} else {
    echo '[4] Jobs dir: OK';
    echo is_writable($jobsDir) ? ' (writable)' : ' (NOT writable)';
    echo "\n";
}

try {
    MaterialImageZipJobService::ensureTable();
    $pdo = Database::pdo();
    $queued = (int) $pdo->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'queued'")->fetchColumn();
    $building = (int) $pdo->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'building'")->fetchColumn();
    $ready = (int) $pdo->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'ready'")->fetchColumn();
    echo "[5] Database: OK (queued={$queued}, building={$building}, ready={$ready})\n";
} catch (Throwable $exception) {
    echo '[5] Database: FAILED — ' . $exception->getMessage() . "\n";
}

$spawnLog = $jobsDir . '/spawn.log';
$workerLog = $jobsDir . '/worker.log';
echo '[6] spawn.log: ' . (is_file($spawnLog) ? 'exists' : 'missing') . "\n";
echo '[7] worker.log: ' . (is_file($workerLog) ? 'exists' : 'missing') . "\n";

if (is_file($workerLog)) {
    $tail = trim((string) shell_exec('tail -n 5 ' . escapeshellarg($workerLog)));
    if ($tail !== '') {
        echo "\n--- worker.log (last 5 lines) ---\n" . $tail . "\n";
    }
}

echo "\nManual run:\n";
echo '  sudo -u www-data ' . ($phpBin ?? 'php') . ' ' . $script . "\n";
echo "\nCron (install once):\n";
echo '  * * * * * www-data cd ' . $portalRoot . ' && ' . ($phpBin ?? 'php') . ' scripts/build-material-zip-job.php >> storage/zip-jobs/worker.log 2>&1' . "\n";
