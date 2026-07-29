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

$runWorker = in_array('--run', $argv ?? [], true);

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

    $recent = $pdo->query(
        "SELECT id, status, file_name, requested_by_web_user_id, created_at
         FROM material_image_zip_jobs
         WHERE status IN ('queued', 'building', 'ready')
         ORDER BY created_at DESC
         LIMIT 10"
    );
    $rows = $recent ? $recent->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($rows !== []) {
        echo "\n--- active jobs (latest 10) ---\n";
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo sprintf(
                "%s | %s | %s | user=%s | %s\n",
                substr((string) ($row['id'] ?? ''), 0, 8),
                (string) ($row['status'] ?? ''),
                (string) ($row['file_name'] ?? '-'),
                substr((string) ($row['requested_by_web_user_id'] ?? '-'), 0, 8),
                (string) ($row['created_at'] ?? '')
            );
        }
    }
} catch (Throwable $exception) {
    echo '[5] Database: FAILED — ' . $exception->getMessage() . "\n";
}

$spawnLog = $jobsDir . '/spawn.log';
$workerLog = $jobsDir . '/worker.log';
echo '[6] spawn.log: ' . (is_file($spawnLog) ? 'exists' : 'missing') . "\n";
echo '[7] worker.log: ' . (is_file($workerLog) ? 'exists' : 'missing') . "\n";

if (is_file($spawnLog)) {
    $spawnTail = trim((string) shell_exec('tail -n 5 ' . escapeshellarg($spawnLog)));
    if ($spawnTail !== '') {
        echo "\n--- spawn.log (last 5 lines) ---\n" . $spawnTail . "\n";
    }
}

if (is_file($workerLog)) {
    $tail = trim((string) shell_exec('tail -n 10 ' . escapeshellarg($workerLog)));
    if ($tail !== '') {
        echo "\n--- worker.log (last 10 lines) ---\n" . $tail . "\n";
    }
}

if ($runWorker) {
    echo "\n==> Running worker now...\n";
    MaterialImageZipJobService::runWorker();
    MaterialImageZipJobService::ensureTable();
    $queuedAfter = (int) Database::pdo()->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'queued'")->fetchColumn();
    $buildingAfter = (int) Database::pdo()->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'building'")->fetchColumn();
    $readyAfter = (int) Database::pdo()->query("SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = 'ready'")->fetchColumn();
    echo "After run: queued={$queuedAfter}, building={$buildingAfter}, ready={$readyAfter}\n";
    if (is_file($workerLog)) {
        $tailAfter = trim((string) shell_exec('tail -n 10 ' . escapeshellarg($workerLog)));
        if ($tailAfter !== '') {
            echo "\n--- worker.log (last 10 lines) ---\n" . $tailAfter . "\n";
        }
    }
}

echo "\nManual run:\n";
echo '  sudo -u www-data ' . ($phpBin ?? 'php') . ' ' . $script . "\n";
echo '  sudo -u www-data ' . ($phpBin ?? 'php') . ' ' . $portalRoot . '/scripts/diagnose-zip-worker.php --run' . "\n";
echo "\nCron (install once):\n";
echo '  * * * * * www-data cd ' . $portalRoot . ' && ' . ($phpBin ?? 'php') . ' scripts/build-material-zip-job.php >> storage/zip-jobs/worker.log 2>&1' . "\n";
