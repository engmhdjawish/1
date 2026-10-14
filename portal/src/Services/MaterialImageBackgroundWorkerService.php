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

    /** Minimum seconds between missing-local repair passes when none are known missing. */
    public static function repairCooldownSeconds(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_REPAIR_COOLDOWN', '1800') ?? '1800');

        return max(300, min(86400, $n > 0 ? $n : 1800));
    }

    /**
     * Retry delay while the dashboard still shows images that are not ready.
     * Much shorter than the idle discovery cooldown so a failed pull is not silent for half an hour.
     */
    public static function repairRetrySeconds(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_REPAIR_RETRY', '90') ?? '90');

        return max(60, min(900, $n > 0 ? $n : 90));
    }

    /** Full-queue path reindex is expensive; once the paths are stable it does not need to run every minute. */
    public static function reindexCooldownSeconds(): int
    {
        $n = (int) (Config::get('PORTAL_MATERIAL_IMAGE_WORKER_REINDEX_COOLDOWN', '21600') ?? '21600');

        return max(300, min(604800, $n > 0 ? $n : 21600));
    }

    public static function workerStaleAfterSeconds(): int
    {
        return 180;
    }

    /** True while a worker tick holds the run lock (including a long repair). */
    public static function isTickRunning(): bool
    {
        $path = self::workerDir() . DIRECTORY_SEPARATOR . 'worker.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        $running = !flock($handle, LOCK_EX | LOCK_NB);
        if (!$running) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $running;
    }

    /**
     * @param array<string, mixed> $status
     */
    public static function shouldRepairNow(array $status, ?int $now = null): bool
    {
        $now = $now ?? time();
        $knownMissing = self::knownMissingCount($status);
        $lastRepair = strtotime((string) ($status['last_repair_at'] ?? '')) ?: 0;
        $cooldown = ($knownMissing === null || $knownMissing > 0)
            ? self::repairRetrySeconds()
            : self::repairCooldownSeconds();

        return ($now - $lastRepair) >= $cooldown;
    }

    /** @param array<string, mixed> $status */
    private static function knownMissingCount(array $status): ?int
    {
        if (!array_key_exists('missing_local_count', $status) || $status['missing_local_count'] === null) {
            return null;
        }

        return max(0, (int) $status['missing_local_count']);
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
            'recent_repair_failures' => [],
            'unavailable_images' => [],
            'last_reindex_at' => null,
            'missing_local_count' => null,
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
        $lockPath = self::workerDir() . DIRECTORY_SEPARATOR . 'worker.lock';
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle !== false && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);

            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'دورة سابقة ما زالت تعمل.',
            ];
        }

        try {
            return self::executeTick($onLog);
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    /**
     * @param null|callable(string):void $onLog
     * @return array<string, mixed>
     */
    private static function executeTick(?callable $onLog = null): array
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

            $statusBefore = self::readStatus();
            $knownMissing = self::knownMissingCount($statusBefore);

            $repairPulled = 0;
            $repairFailed = 0;
            $repairRan = false;
            $inferMissingZero = false;
            $recentRepairFailures = is_array($statusBefore['recent_repair_failures'] ?? null)
                ? $statusBefore['recent_repair_failures']
                : [];
            $unavailableImages = self::normalizeUnavailableImages($statusBefore);
            $repairLimit = self::repairMissingBatchSize();
            if ($apiOk && $repairLimit > 0 && self::shouldRepairNow($statusBefore)) {
                $repairRan = true;
                $log('بدء جلب الصور غير الجاهزة للعرض…');
                $pull = MaterialImageStorageService::pullAllMissingLocals(
                    $repairLimit,
                    static function (string $message) use ($log): void {
                        $log('repair: ' . $message);
                    }
                );
                $repairPulled = (int) ($pull['pulled'] ?? 0);
                $missingFound = (int) ($pull['missing_found'] ?? 0);
                $scanComplete = (bool) ($pull['scan_complete'] ?? false);
                $messages[] = (string) ($pull['message'] ?? 'إصلاح نسخ محلية.');
                $recentRepairFailures = [];
                $seenUnavailable = [];
                $catalogComplete = $scanComplete || $missingFound < $repairLimit;
                foreach (is_array($pull['items'] ?? null) ? $pull['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $guid = strtolower(trim((string) ($item['image_guid'] ?? '')));
                    if (($item['ok'] ?? false) && $guid !== '') {
                        unset($unavailableImages[$guid]);
                        continue;
                    }
                    if (!empty($item['unavailable']) && $guid !== '') {
                        $seenUnavailable[$guid] = [
                            'image_guid' => $guid,
                            'material_code' => trim((string) ($item['material_code'] ?? '')),
                            'message' => (string) ($item['message'] ?? 'ملف الصورة غير موجود في المحاسبة. أعد رفع الصورة لهذه المادة.'),
                        ];
                        continue;
                    }
                    $repairFailed++;
                    if (count($recentRepairFailures) < 8) {
                        $recentRepairFailures[] = [
                            'material_code' => (string) ($item['material_code'] ?? ''),
                            'message' => (string) ($item['message'] ?? 'تعذر الجلب من المحاسبة.'),
                        ];
                    }
                }
                if ($catalogComplete) {
                    $unavailableImages = $seenUnavailable;
                    if ($repairFailed === 0) {
                        $inferMissingZero = true;
                    }
                } else {
                    foreach ($seenUnavailable as $guid => $row) {
                        $unavailableImages[$guid] = $row;
                    }
                }
                self::writeStatus([
                    'last_repair_at' => date('c'),
                    'last_repair_pulled' => $repairPulled,
                    'last_repair_failed' => $repairFailed,
                    'recent_repair_failures' => $recentRepairFailures,
                    'unavailable_images' => array_values($unavailableImages),
                ]);
            } elseif ($knownMissing !== null && $knownMissing > 0) {
                $messages[] = 'الصور غير الجاهزة بانتظار الدورة التالية من المعالجة.';
            }

            $lastReindex = strtotime((string) ($statusBefore['last_reindex_at'] ?? '')) ?: 0;
            if (time() - $lastReindex >= self::reindexCooldownSeconds()) {
                try {
                    $reindex = MaterialImageSyncService::reindexLocalPaths();
                    if (($reindex['updated'] ?? 0) > 0) {
                        $log('reindex: ' . (string) ($reindex['message'] ?? ''));
                    }
                    self::writeStatus(['last_reindex_at' => date('c')]);
                } catch (Throwable $exception) {
                    $log('reindex error: ' . $exception->getMessage());
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
            $missingCount = $knownMissing;
            if ($inferMissingZero) {
                $missingCount = 0;
                $recentRepairFailures = [];
            } elseif ($apiOk && ($repairRan || $knownMissing === null)) {
                try {
                    $count = MaterialImageStorageService::countMissingLocals();
                    if ($count['ok'] ?? false) {
                        $missingCount = (int) ($count['missing'] ?? 0);
                        if ($missingCount === 0) {
                            $recentRepairFailures = [];
                        }
                    }
                } catch (Throwable) {
                    // Keep the previous count so the dashboard does not forget images that are still missing.
                }
            }
            if ($repairFailed === 0 && $unavailableImages !== [] && $missingCount !== null && $missingCount <= count($unavailableImages)) {
                $missingCount = 0;
                $recentRepairFailures = [];
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
                'recent_repair_failures' => $recentRepairFailures,
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

    /**
     * Short status for dashboard (no server/cron/technical worker logs).
     *
     * @param array<string, mixed> $workerStatus
     * @param array{pending: int, syncing: int, synced: int, failed: int, total: int} $sync
     */
    public static function userFacingSummary(
        array $workerStatus,
        array $sync,
        bool $apiOk,
        bool $workerEnabled,
        ?int $missingLocal = null
    ): string {
        if (!$apiOk) {
            return 'لا يوجد اتصال مع نظام المحاسبة — قد تتأخر مزامنة الصور.';
        }
        if (!$workerEnabled) {
            return 'المزامنة التلقائية غير مفعّلة — راجع مسؤول الموقع.';
        }

        $failed = (int) ($sync['failed'] ?? 0);
        $pending = (int) ($sync['pending'] ?? 0);
        if ($failed > 0) {
            return $failed === 1
                ? 'صورة واحدة فشلت في المزامنة — راجع قائمة الفشل.'
                : ('هناك ' . $failed . ' صور فشلت في المزامنة — راجع قائمة الفشل.');
        }
        if ($pending > 0) {
            return $pending === 1
                ? 'صورة واحدة بانتظار الإرسال للمحاسبة.'
                : ('هناك ' . $pending . ' صور بانتظار الإرسال للمحاسبة.');
        }
        $unavailable = self::normalizeUnavailableImages($workerStatus);
        if ($unavailable !== [] && ($missingLocal === null || $missingLocal === 0)) {
            return self::unavailableSummary($unavailable);
        }
        if ($missingLocal !== null && $missingLocal > 0) {
            return self::missingLocalSummary($missingLocal, $workerStatus);
        }

        $lastRun = trim((string) ($workerStatus['last_run_at'] ?? ''));

        return $lastRun === ''
            ? 'المزامنة التلقائية جاهزة.'
            : 'المزامنة التلقائية تعمل.';
    }

    /** @param array<string, mixed> $workerStatus */
    private static function missingLocalSummary(int $missingLocal, array $workerStatus): string
    {
        $lastRun = strtotime((string) ($workerStatus['last_run_at'] ?? '')) ?: 0;
        if ($lastRun > 0 && (time() - $lastRun) > self::workerStaleAfterSeconds() && !self::isTickRunning()) {
            return $missingLocal === 1
                ? 'صورة واحدة غير جاهزة للعرض، والمعالجة التلقائية متوقفة حالياً.'
                : ('هناك ' . $missingLocal . ' صور غير جاهزة للعرض، والمعالجة التلقائية متوقفة حالياً.');
        }

        $repairFailed = (int) ($workerStatus['last_repair_failed'] ?? 0);
        $repairPulled = (int) ($workerStatus['last_repair_pulled'] ?? 0);
        $lastRepair = trim((string) ($workerStatus['last_repair_at'] ?? ''));
        if ($lastRepair !== '' && $repairFailed > 0 && $repairPulled === 0) {
            return $missingLocal === 1
                ? 'تعذر جلب صورة واحدة من المحاسبة — ستُعاد المحاولة تلقائياً.'
                : ('تعذر جلب ' . $missingLocal . ' صور من المحاسبة — ستُعاد المحاولة تلقائياً.');
        }

        return $missingLocal === 1
            ? 'صورة واحدة غير جاهزة للعرض في المتجر — جاري المعالجة تلقائياً.'
            : ('هناك ' . $missingLocal . ' صور غير جاهزة للعرض — جاري المعالجة تلقائياً.');
    }

    /** @param array{pending: int, syncing: int, synced: int, failed: int, total: int} $sync */
    public static function dashboardNeedsAttention(
        array $sync,
        bool $apiOk,
        ?int $missingLocal = null,
        int $unavailableCount = 0
    ): bool {
        if (!$apiOk) {
            return true;
        }
        if ((int) ($sync['failed'] ?? 0) > 0) {
            return true;
        }
        if ((int) ($sync['pending'] ?? 0) > 0) {
            return true;
        }
        if ($unavailableCount > 0) {
            return true;
        }

        return $missingLocal !== null && $missingLocal > 0;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, array{image_guid: string, material_code: string, message: string}>
     */
    private static function normalizeUnavailableImages(array $status): array
    {
        $rows = $status['unavailable_images'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $guid = strtolower(trim((string) ($row['image_guid'] ?? '')));
            if ($guid === '') {
                continue;
            }
            $normalized[$guid] = [
                'image_guid' => $guid,
                'material_code' => trim((string) ($row['material_code'] ?? '')),
                'message' => (string) ($row['message'] ?? 'ملف الصورة غير موجود في المحاسبة. أعد رفع الصورة لهذه المادة.'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, array{image_guid: string, material_code: string, message: string}> $images
     */
    private static function unavailableSummary(array $images): string
    {
        $codes = [];
        foreach ($images as $image) {
            $code = trim((string) ($image['material_code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $listed = $codes !== [] ? (' (' . implode('، ', array_slice($codes, 0, 8)) . ')') : '';
        $count = count($images);
        if ($count === 1) {
            return 'مادة واحدة صورتها مسجّلة في المحاسبة والملف غير موجود' . $listed . '. أعد رفع الصورة لهذه المادة.';
        }

        return 'هناك ' . $count . ' مواد صورتها مسجّلة في المحاسبة والملف غير موجود' . $listed . '. أعد رفع الصورة لهذه المواد.';
    }

    /** @return array<string, mixed> */
    public static function dashboardSnapshot(): array
    {
        $status = self::readStatus();
        $sync = MaterialImageSyncService::stats();
        $health = PortalSettingsService::apiHealth();
        $apiOk = (bool) ($health['ok'] ?? false);
        $missingLocal = isset($status['missing_local_count']) ? (int) $status['missing_local_count'] : null;
        $unavailableCount = count(self::normalizeUnavailableImages($status));

        return [
            'worker_enabled' => self::isEnabled(),
            'worker' => $status,
            'sync' => $sync,
            'api' => [
                'ok' => $apiOk,
                'message' => (string) ($health['message'] ?? ''),
            ],
            'user_summary' => self::userFacingSummary($status, $sync, $apiOk, self::isEnabled(), $missingLocal),
            'needs_attention' => self::dashboardNeedsAttention($sync, $apiOk, $missingLocal, $unavailableCount),
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
