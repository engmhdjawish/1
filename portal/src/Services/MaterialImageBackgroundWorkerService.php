<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Throwable;

/**
 * CLI/cron worker: Amine sync queue, auto-retry failures, repair missing local GUID copies.
 */
final class MaterialImageBackgroundWorkerService
{
    private const STATUS_FILE = 'status.json';
    private const LOG_FILE = 'worker.log';

    public static function isEnabled(): bool
    {
        $raw = Config::get('PORTAL_MATERIAL_IMAGE_WORKER', '1');
        $value = strtolower(trim((string) $raw));

        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    public static function syncBatchSize(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_SYNC_BATCH', '5') ?? '5');

        return max(1, min(20, $n > 0 ? $n : 5));
    }

    public static function repairMissingBatchSize(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_REPAIR_MISSING', '5') ?? '5');

        return max(0, min(30, $n));
    }

    public static function autoRetryFailed(): bool
    {
        $raw = Config::get('PORTAL_MATERIAL_IMAGE_WORKER_AUTO_RETRY_FAILED', '1');
        $value = strtolower(trim((string) $raw));

        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    /** Minimum seconds between automatic "failed → pending" resets. */
    public static function autoRetryCooldownSeconds(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_RETRY_COOLDOWN', '900') ?? '900');

        return max(60, min(86400, $n > 0 ? $n : 900));
    }

    /** Minimum seconds between missing-local repair passes. */
    public static function repairCooldownSeconds(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_REPAIR_COOLDOWN', '1800') ?? '1800');

        return max(300, min(86400, $n > 0 ? $n : 1800));
    }

    public static function workerDir(): string
    {
        $dir = rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'material-image-worker';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function logPath(): string
    {
        return self::workerDir() . DIRECTORY_SEPARATOR . self::LOG_FILE;
    }

    public static function appendLog(string $message): void
    {
        $line = '[' . date('c') . '] ' . $message . PHP_EOL;
        @file_put_contents(self::logPath(), $line, FILE_APPEND | LOCK_EX);
    }

    /** @return array<string, mixed> */
    public static function readStatus(): array
    {
        $path = self::workerDir() . DIRECTORY_SEPARATOR . self::STATUS_FILE;
        if (!is_file($path)) {
            return self::defaultStatus();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_merge(self::defaultStatus(), $decoded) : self::defaultStatus();
    }

    /** @param array<string, mixed> $patch */
    public static function writeStatus(array $patch): void
    {
        $current = self::readStatus();
        $merged = array_merge($current, $patch, [
            'updated_at' => date('c'),
        ]);
        $path = self::workerDir() . DIRECTORY_SEPARATOR . self::STATUS_FILE;
        file_put_contents(
            $path,
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /** @return array<string, mixed> */
    private static function defaultStatus(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'last_run_at' => null,
            'last_run_ok' => null,
            'last_message' => '',
            'last_auto_retry_at' => null,
            'last_auto_retry_count' => 0,
            'last_repair_at' => null,
            'last_repair_pulled' => 0,
            'last_repair_failed' => 0,
            'last_sync_attempts' => 0,
            'last_sync_successes' => 0,
            'last_offline' => false,
            'recent_failures' => [],
            'updated_at' => null,
        ];
    }

    /**
     * One cron tick.
     *
     * @param null|callable(string):void $onLog
     * @return array<string, mixed>
     */
    public static function runTick(?callable $onLog = null): array
    {
        $log = static function (string $message) use ($onLog): void {
            self::appendLog($message);
            if ($onLog !== null) {
                $onLog($message);
            }
        };

        if (!self::isEnabled()) {
            $result = [
                'ok' => true,
                'skipped' => true,
                'message' => 'العامل معطّل (PORTAL_MATERIAL_IMAGE_WORKER=0).',
            ];
            self::writeStatus(array_merge($result, ['last_run_at' => date('c'), 'last_run_ok' => true]));

            return $result;
        }

        MaterialImageStorageService::ensureSettings();
        MaterialImageSyncService::ensureTable();

        $syncAttempts = 0;
        $syncSuccesses = 0;
        $offline = false;
        $messages = [];

        try {
            MaterialImageSyncService::recoverStaleSyncing();
            MaterialImageSyncService::recoverSyncedWithoutGuid();

            $stats = MaterialImageSyncService::stats();
            $health = PortalSettingsService::apiHealth();
            $apiOk = (bool) ($health['ok'] ?? false);

            if (!$apiOk) {
                $offline = true;
                $messages[] = 'الأمين غير متصل — تخطّي المزامنة.';
            }

            if ($apiOk && self::autoRetryFailed() && (int) ($stats['failed'] ?? 0) > 0) {
                $status = self::readStatus();
                $lastRetry = strtotime((string) ($status['last_auto_retry_at'] ?? '')) ?: 0;
                if (time() - $lastRetry >= self::autoRetryCooldownSeconds()) {
                    $reset = MaterialImageSyncService::resetFailedToPending();
                    if ($reset > 0) {
                        $log('أُعيدت ' . $reset . ' صورة فاشلة إلى الانتظار.');
                        $messages[] = 'إعادة محاولة تلقائية: ' . $reset . ' فاشلة → انتظار.';
                        self::writeStatus([
                            'last_auto_retry_at' => date('c'),
                            'last_auto_retry_count' => $reset,
                        ]);
                        $stats = MaterialImageSyncService::stats();
                    }
                }
            }

            if ($apiOk) {
                $batch = self::syncBatchSize();
                for ($i = 0; $i < $batch; $i++) {
                    if ((int) ($stats['pending'] ?? 0) === 0 && (int) ($stats['failed'] ?? 0) === 0) {
                        break;
                    }

                    $syncAttempts++;
                    $result = MaterialImageSyncService::syncNext();
                    if ($result['done'] ?? false) {
                        break;
                    }
                    if ($result['offline'] ?? false) {
                        $offline = true;
                        $messages[] = (string) ($result['message'] ?? 'انقطاع الأمين.');
                        break;
                    }
                    if ($result['ok'] ?? false) {
                        $syncSuccesses++;
                    } else {
                        $messages[] = (string) ($result['message'] ?? 'فشل مزامنة.');
                        break;
                    }
                    $stats = MaterialImageSyncService::stats();
                }
            }

            try {
                $reindex = MaterialImageSyncService::reindexLocalPaths();
                if (($reindex['updated'] ?? 0) > 0) {
                    $log('reindex: ' . (string) ($reindex['message'] ?? ''));
                }
            } catch (Throwable $exception) {
                $log('reindex error: ' . $exception->getMessage());
            }

            $repairPulled = 0;
            $repairFailed = 0;
            $repairLimit = self::repairMissingBatchSize();
            if ($apiOk && $repairLimit > 0) {
                $status = self::readStatus();
                $lastRepair = strtotime((string) ($status['last_repair_at'] ?? '')) ?: 0;
                if (time() - $lastRepair >= self::repairCooldownSeconds()) {
                    $pull = MaterialImageStorageService::pullAllMissingLocals(
                        $repairLimit,
                        static function (string $message) use ($log): void {
                            $log('repair: ' . $message);
                        }
                    );
                    $repairPulled = (int) ($pull['pulled'] ?? 0);
                    $repairFailed = (int) ($pull['failed'] ?? 0);
                    if ($repairPulled > 0 || $repairFailed > 0) {
                        $messages[] = (string) ($pull['message'] ?? 'إصلاح نسخ محلية.');
                    }
                    self::writeStatus([
                        'last_repair_at' => date('c'),
                        'last_repair_pulled' => $repairPulled,
                        'last_repair_failed' => $repairFailed,
                    ]);
                }
            }

            $failures = MaterialImageSyncService::listQueuePage(1, 8, 'failed');
            $recentFailures = [];
            foreach ($failures['items'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $recentFailures[] = [
                    'file_name' => (string) ($row['file_name'] ?? ''),
                    'amine_sync_error_ar' => (string) ($row['amine_sync_error_ar'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }

            $finalStats = MaterialImageSyncService::stats();
            $missingCount = null;
            if ($apiOk) {
                try {
                    $count = MaterialImageStorageService::countMissingLocals();
                    if ($count['ok'] ?? false) {
                        $missingCount = (int) ($count['missing'] ?? 0);
                    }
                } catch (Throwable) {
                    $missingCount = null;
                }
            }

            $summary = $messages !== []
                ? implode(' ', $messages)
                : ('مزامنة: ' . $syncSuccesses . '/' . $syncAttempts
                    . ' | طابور: pending=' . (int) ($finalStats['pending'] ?? 0)
                    . ' failed=' . (int) ($finalStats['failed'] ?? 0));

            $result = [
                'ok' => !$offline || $syncSuccesses > 0 || $repairPulled > 0,
                'skipped' => false,
                'message' => $summary,
                'offline' => $offline,
                'sync_attempts' => $syncAttempts,
                'sync_successes' => $syncSuccesses,
                'repair_pulled' => $repairPulled,
                'repair_failed' => $repairFailed,
                'sync' => $finalStats,
                'missing_local_count' => $missingCount,
                'recent_failures' => $recentFailures,
            ];

            self::writeStatus([
                'enabled' => true,
                'last_run_at' => date('c'),
                'last_run_ok' => (bool) $result['ok'],
                'last_message' => $summary,
                'last_sync_attempts' => $syncAttempts,
                'last_sync_successes' => $syncSuccesses,
                'last_offline' => $offline,
                'recent_failures' => $recentFailures,
                'missing_local_count' => $missingCount,
            ]);

            $log($summary);

            return $result;
        } catch (Throwable $exception) {
            $message = 'خطأ في العامل: ' . $exception->getMessage();
            $log($message);
            self::writeStatus([
                'last_run_at' => date('c'),
                'last_run_ok' => false,
                'last_message' => $message,
            ]);

            return [
                'ok' => false,
                'skipped' => false,
                'message' => $message,
            ];
        }
    }

    /** @return array<string, mixed> */
    public static function dashboardSnapshot(): array
    {
        $status = self::readStatus();
        $sync = MaterialImageSyncService::stats();
        $health = PortalSettingsService::apiHealth();

        return [
            'worker_enabled' => self::isEnabled(),
            'worker' => $status,
            'sync' => $sync,
            'api' => [
                'ok' => (bool) ($health['ok'] ?? false),
                'message' => (string) ($health['message'] ?? ''),
            ],
            'log_tail' => self::readLogTail(12),
        ];
    }

    /** @return list<string> */
    public static function readLogTail(int $lines = 20): array
    {
        $path = self::logPath();
        if (!is_file($path)) {
            return [];
        }

        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return [];
        }

        return array_slice($content, -max(1, $lines));
    }
}
