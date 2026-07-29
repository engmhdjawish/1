<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use Portal\Support\Utf8Text;
use PDO;
use Throwable;

final class MaterialImageZipJobService
{
    public const JOB_TTL_SECONDS = 7200;
    public const MAX_PENDING_JOBS_PER_USER = 5;
    public const STALE_QUEUED_SECONDS = 1800;

    public static function ensureTable(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS material_image_zip_jobs (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                requested_by_web_user_id UUID REFERENCES web_users (id) ON DELETE SET NULL,
                mode VARCHAR(32) NOT NULL,
                params JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                status VARCHAR(32) NOT NULL DEFAULT \'queued\',
                progress_pct SMALLINT NOT NULL DEFAULT 0 CHECK (progress_pct >= 0 AND progress_pct <= 100),
                progress_message VARCHAR(500),
                file_path VARCHAR(1000),
                file_name VARCHAR(255),
                image_count INT,
                error_message VARCHAR(500),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                started_at TIMESTAMPTZ,
                finished_at TIMESTAMPTZ,
                downloaded_at TIMESTAMPTZ,
                expires_at TIMESTAMPTZ
            )'
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_material_image_zip_jobs_status
                ON material_image_zip_jobs (status, created_at)'
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_material_image_zip_jobs_user
                ON material_image_zip_jobs (requested_by_web_user_id, created_at DESC)'
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function createJob(?string $userId, array $params): string
    {
        self::ensureTable();
        self::cleanupExpiredJobs();
        self::expireStaleQueuedJobs();

        $mode = trim((string) ($params['mode'] ?? 'materials'));
        if (!in_array($mode, ['materials', 'invoice'], true)) {
            throw new \RuntimeException('نوع التحميل غير مدعوم.');
        }

        if ($userId !== null && $userId !== '') {
            $pendingStmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM material_image_zip_jobs
                 WHERE requested_by_web_user_id = :user_id
                   AND status IN (\'queued\', \'building\')'
            );
            $pendingStmt->execute(['user_id' => $userId]);
            $pendingCount = (int) $pendingStmt->fetchColumn();
            if ($pendingCount >= self::MAX_PENDING_JOBS_PER_USER) {
                throw new \RuntimeException('لديك طلبات تحضير ZIP قيد التنفيذ. انتظر انتهاءها ثم حاول مجدداً.');
            }
        }

        $encoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            throw new \RuntimeException('تعذر حفظ معلمات التحميل.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO material_image_zip_jobs (
                requested_by_web_user_id, mode, params, status, progress_message
             ) VALUES (
                :user_id, :mode, CAST(:params AS jsonb), \'queued\', :progress_message
             )
             RETURNING id'
        );
        $stmt->execute([
            'user_id' => $userId !== null && $userId !== '' ? $userId : null,
            'mode' => $mode,
            'params' => $encoded,
            'progress_message' => 'في قائمة الانتظار...',
        ]);

        $jobId = trim((string) $stmt->fetchColumn());
        if ($jobId === '') {
            throw new \RuntimeException('تعذر إنشاء طلب التحميل.');
        }

        self::spawnWorker();

        return $jobId;
    }

    public static function getStatus(string $jobId, ?string $userId): array
    {
        self::ensureTable();
        self::recoverStaleBuildingJobs();
        self::expireStaleQueuedJobs();

        if (self::countQueuedJobs() > 0) {
            self::spawnWorker();
        }

        $job = self::getJobForUser($jobId, $userId, false);

        $queuedSeconds = 0;
        if (($job['status'] ?? '') === 'queued' && !empty($job['created_at'])) {
            $createdAt = strtotime((string) $job['created_at']);
            if ($createdAt !== false) {
                $queuedSeconds = max(0, time() - $createdAt);
            }
        }

        return [
            'jobId' => $job['id'],
            'status' => $job['status'],
            'progressPct' => (int) ($job['progress_pct'] ?? 0),
            'progressMessage' => (string) ($job['progress_message'] ?? ''),
            'imageCount' => (int) ($job['image_count'] ?? 0),
            'fileName' => (string) ($job['file_name'] ?? ''),
            'errorMessage' => (string) ($job['error_message'] ?? ''),
            'queuedSeconds' => $queuedSeconds,
            'downloadUrl' => ($job['status'] ?? '') === 'ready'
                ? '/api/material-images-zip-jobs.php?jobId=' . rawurlencode((string) $job['id']) . '&download=1'
                : null,
        ];
    }

    public static function spawnWorker(): bool
    {
        if (self::isWorkerRunning()) {
            self::logSpawn('worker already running — skipped spawn');

            return true;
        }

        $php = self::phpBinary();
        $script = self::workerScriptPath();
        if (!is_file($script)) {
            self::logSpawn('worker script missing: ' . $script);

            return false;
        }

        $logPath = self::jobsDir(true) . '/worker.log';
        $spawned = self::dispatchBackgroundProcess($php, $script, $logPath);
        self::logSpawn($spawned
            ? 'spawned worker with ' . $php . ' ' . $script
            : 'failed to spawn worker with ' . $php . ' ' . $script);

        return $spawned;
    }

    public static function logWorker(string $message): void
    {
        $line = '[' . date('c') . '] ' . $message . PHP_EOL;
        @file_put_contents(self::jobsDir(true) . '/worker.log', $line, FILE_APPEND);
    }

    public static function countQueuedJobs(): int
    {
        self::ensureTable();
        $stmt = Database::pdo()->query(
            'SELECT COUNT(*) FROM material_image_zip_jobs WHERE status = \'queued\''
        );

        return (int) ($stmt?->fetchColumn() ?: 0);
    }

    public static function runWorker(): void
    {
        self::ensureTable();
        self::cleanupExpiredJobs();
        self::recoverStaleBuildingJobs();
        self::expireStaleQueuedJobs();

        $queuedCount = self::countQueuedJobs();
        self::logWorker('runWorker: queued=' . $queuedCount);
        if ($queuedCount === 0) {
            return;
        }

        $lockPath = self::workerLockPath();
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            self::logWorker('runWorker: failed to open worker.lock');

            return;
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            self::logSpawn('worker lock busy — another worker is active (queued=' . $queuedCount . ')');
            self::logWorker('runWorker: lock busy (queued=' . $queuedCount . ')');

            return;
        }

        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        fwrite($lockHandle, (string) getmypid());
        fflush($lockHandle);

        try {
            while (true) {
                $job = self::claimNextQueuedJob();
                if ($job === null) {
                    self::logWorker('runWorker: no queued job claimed (remaining=' . self::countQueuedJobs() . ')');
                    break;
                }
                self::logWorker('runWorker: processing job ' . (string) ($job['id'] ?? ''));
                self::processJob($job);
                self::logWorker('runWorker: finished job ' . (string) ($job['id'] ?? ''));
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function claimNextQueuedJob(): ?array
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $stmt = $pdo->query(
            'UPDATE material_image_zip_jobs
             SET status = \'building\',
                 started_at = NOW(),
                 updated_at = NOW(),
                 progress_pct = 5,
                 progress_message = \'جاري تحضير الملف...\'
             WHERE id = (
                 SELECT id FROM material_image_zip_jobs
                 WHERE status = \'queued\'
                 ORDER BY created_at ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED
             )
             RETURNING *'
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalizeJobRow($row) : null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function processJob(array $job): void
    {
        $jobId = (string) ($job['id'] ?? '');
        $mode = (string) ($job['mode'] ?? '');
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];

        self::jobsDir(true);
        $outputPath = self::jobsDir() . '/' . $jobId . '.zip';

        try {
            self::updateProgress($jobId, 15, 'جاري جمع الصور من الموقع...');

            $imageCount = 0;
            $fileName = 'material-images';

            if ($mode === 'invoice') {
                $typeGuid = trim((string) ($params['typeGuid'] ?? ''));
                $typeName = trim((string) ($params['type'] ?? ''));
                $number = (int) ($params['number'] ?? 0);
                if ($number <= 0) {
                    throw new \RuntimeException('أدخل رقم الفاتورة.');
                }

                $billGuid = MaterialImageZipService::findInvoiceGuid(
                    $typeGuid !== '' ? $typeGuid : null,
                    $typeName !== '' ? $typeName : null,
                    $number
                );
                if ($billGuid === null) {
                    throw new \RuntimeException('لم يتم العثور على فاتورة بهذا النوع والرقم.');
                }

                self::updateProgress($jobId, 35, 'جاري ضغط صور الفاتورة...');
                $fileName = 'invoice-' . $number . '-images';
                $imageCount = MaterialImageZipService::buildLocalInvoiceImagesZipFile(
                    $billGuid,
                    $fileName,
                    $outputPath
                );
            } else {
                $splitBy = trim((string) ($params['splitBy'] ?? ''));
                $archiveName = trim((string) ($params['archiveName'] ?? ''));
                $fileName = $archiveName !== '' ? $archiveName : ($splitBy !== '' ? 'split-material-images' : 'filtered-material-images');

                self::updateProgress($jobId, 35, 'جاري ضغط الصور...');
                if ($splitBy !== '') {
                    $imageCount = MaterialImageZipService::buildSplitMaterialImagesZipFile($params, $outputPath);
                } else {
                    $imageCount = MaterialImageZipService::buildLocalMaterialImagesZipFile($params, $fileName, $outputPath);
                }
            }

            $size = filesize($outputPath);
            if ($size === false || $size < 22) {
                throw new \RuntimeException('ملف ZIP فارغ أو تالف.');
            }

            $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . self::JOB_TTL_SECONDS . ' seconds');

            $stmt = Database::pdo()->prepare(
                'UPDATE material_image_zip_jobs
                 SET status = \'ready\',
                     progress_pct = 100,
                     progress_message = :progress_message,
                     file_path = :file_path,
                     file_name = :file_name,
                     image_count = :image_count,
                     error_message = NULL,
                     finished_at = NOW(),
                     updated_at = NOW(),
                     expires_at = :expires_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $jobId,
                'progress_message' => 'الملف جاهز للتحميل.',
                'file_path' => $outputPath,
                'file_name' => MaterialImageZipService::sanitizeFilename($fileName),
                'image_count' => $imageCount,
                'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            ]);
        } catch (Throwable $exception) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            self::markFailed($jobId, $exception->getMessage());
        }
    }

    public static function streamDownload(string $jobId, ?string $userId): void
    {
        self::ensureTable();
        $job = self::getJobForUser($jobId, $userId, true);
        if (($job['status'] ?? '') !== 'ready') {
            throw new \RuntimeException('الملف غير جاهز بعد.');
        }

        $path = trim((string) ($job['file_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('ملف التحميل غير موجود أو انتهت صلاحيته.');
        }

        $fileName = trim((string) ($job['file_name'] ?? 'material-images'));
        MaterialImageZipService::streamExistingZipFile($path, $fileName);

        $stmt = Database::pdo()->prepare(
            'UPDATE material_image_zip_jobs
             SET downloaded_at = NOW(), updated_at = NOW(), status = \'downloaded\'
             WHERE id = :id'
        );
        $stmt->execute(['id' => $jobId]);

        @unlink($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getJobForUser(string $jobId, ?string $userId, bool $requireReady): array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            throw new \RuntimeException('معرّف الطلب مطلوب.');
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM material_image_zip_jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('طلب التحميل غير موجود.');
        }

        $job = self::normalizeJobRow($row);
        $ownerId = trim((string) ($job['requested_by_web_user_id'] ?? ''));
        if ($ownerId !== '' && $userId !== null && $userId !== '' && $ownerId !== $userId) {
            throw new \RuntimeException('غير مصرح لك بتحميل هذا الملف.');
        }

        if (($job['status'] ?? '') === 'expired') {
            throw new \RuntimeException('انتهت صلاحية ملف التحميل.');
        }

        if ($requireReady && ($job['status'] ?? '') !== 'ready') {
            throw new \RuntimeException('الملف غير جاهز بعد.');
        }

        return $job;
    }

    private static function markFailed(string $jobId, string $message): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE material_image_zip_jobs
             SET status = \'failed\',
                 progress_pct = 100,
                 progress_message = :progress_message,
                 error_message = :error_message,
                 finished_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'progress_message' => 'تعذر تحضير الملف.',
            'error_message' => Utf8Text::substr($message, 0, 480),
        ]);
    }

    private static function updateProgress(string $jobId, int $pct, string $message): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE material_image_zip_jobs
             SET progress_pct = :pct,
                 progress_message = :message,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'pct' => max(0, min(100, $pct)),
            'message' => Utf8Text::substr($message, 0, 480),
        ]);
    }

    public static function expireStaleQueuedJobs(): void
    {
        self::ensureTable();
        Database::pdo()->exec(
            'UPDATE material_image_zip_jobs
             SET status = \'failed\',
                 progress_pct = 100,
                 progress_message = \'انتهت مهلة الانتظار — أعد المحاولة.\',
                 error_message = \'تعذر بدء التحضير في الوقت المحدد. تأكد من cron/worker على السيرفر.\',
                 finished_at = NOW(),
                 updated_at = NOW()
             WHERE status = \'queued\'
               AND created_at < NOW() - INTERVAL \'' . (int) self::STALE_QUEUED_SECONDS . ' seconds\''
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listActiveJobsForUser(?string $userId, int $limit = 10): array
    {
        self::ensureTable();
        self::expireStaleQueuedJobs();
        self::recoverStaleBuildingJobs();

        if ($userId === null || $userId === '') {
            return [];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM material_image_zip_jobs
             WHERE requested_by_web_user_id = :user_id
               AND status IN (\'queued\', \'building\', \'ready\')
             ORDER BY CASE status WHEN \'ready\' THEN 0 WHEN \'building\' THEN 1 ELSE 2 END, created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('user_id', $userId);
        $stmt->bindValue('limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $job = self::normalizeJobRow($row);
            $jobs[] = [
                'jobId' => $job['id'],
                'status' => $job['status'],
                'progressMessage' => (string) ($job['progress_message'] ?? ''),
                'fileName' => (string) ($job['file_name'] ?? ''),
                'imageCount' => (int) ($job['image_count'] ?? 0),
                'createdAt' => (string) ($job['created_at'] ?? ''),
                'downloadUrl' => ($job['status'] ?? '') === 'ready'
                    ? '/api/material-images-zip-jobs.php?jobId=' . rawurlencode((string) $job['id']) . '&download=1'
                    : null,
            ];
        }

        return $jobs;
    }

    public static function cleanupExpiredJobs(): void
    {
        self::ensureTable();

        $stmt = Database::pdo()->query(
            'SELECT id, file_path FROM material_image_zip_jobs
             WHERE status IN (\'ready\', \'downloaded\', \'failed\')
               AND expires_at IS NOT NULL
               AND expires_at < NOW()'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = trim((string) ($row['file_path'] ?? ''));
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        Database::pdo()->exec(
            'UPDATE material_image_zip_jobs
             SET status = \'expired\', file_path = NULL, updated_at = NOW()
             WHERE status IN (\'ready\', \'downloaded\', \'failed\')
               AND expires_at IS NOT NULL
               AND expires_at < NOW()'
        );

        Database::pdo()->exec(
            'DELETE FROM material_image_zip_jobs
             WHERE status = \'expired\'
               AND updated_at < NOW() - INTERVAL \'7 days\''
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeJobRow(array $row): array
    {
        $params = $row['params'] ?? [];
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }

        $row['params'] = is_array($params) ? $params : [];

        return $row;
    }

    private static function jobsDir(bool $create = false): string
    {
        $dir = rtrim(Config::storagePath(), '/\\') . '/zip-jobs';
        if ($create && !is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function workerLockPath(): string
    {
        return self::jobsDir(true) . '/worker.lock';
    }

    public static function recoverStaleBuildingJobs(): void
    {
        self::ensureTable();
        Database::pdo()->exec(
            'UPDATE material_image_zip_jobs
             SET status = \'queued\',
                 progress_pct = 0,
                 progress_message = \'إعادة محاولة التحضير...\',
                 started_at = NULL,
                 updated_at = NOW()
             WHERE status = \'building\'
               AND started_at IS NOT NULL
               AND started_at < NOW() - INTERVAL \'20 minutes\''
        );
    }

    private static function isWorkerRunning(): bool
    {
        $lockPath = self::workerLockPath();
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            return false;
        }

        if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);

            return false;
        }

        fclose($lockHandle);

        $pid = (int) trim((string) @file_get_contents($lockPath));
        if ($pid > 0 && !self::isProcessAlive($pid)) {
            @unlink($lockPath);

            return false;
        }

        return true;
    }

    private static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_file('/proc/' . $pid . '/stat');
    }

    private static function dispatchBackgroundProcess(string $php, string $script, string $logPath): bool
    {
        $cwd = self::portalRoot();
        $shellCommand = sprintf(
            'cd %s && exec %s %s >> %s 2>&1',
            escapeshellarg($cwd),
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($logPath)
        );
        $backgroundCommand = '/bin/bash -c ' . escapeshellarg($shellCommand . ' &');

        if (function_exists('proc_open') && !self::isFunctionDisabled('proc_open')) {
            $process = @proc_open(
                $backgroundCommand,
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ],
                $pipes,
                $cwd
            );
            if (is_resource($process)) {
                proc_close($process);

                return true;
            }
        }

        if (function_exists('exec') && !self::isFunctionDisabled('exec')) {
            exec($backgroundCommand);

            return true;
        }

        if (function_exists('shell_exec') && !self::isFunctionDisabled('shell_exec')) {
            shell_exec($backgroundCommand);

            return true;
        }

        return false;
    }

    private static function isFunctionDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }

    private static function logSpawn(string $message): void
    {
        $line = '[' . date('c') . '] ' . $message . PHP_EOL;
        @file_put_contents(self::jobsDir(true) . '/spawn.log', $line, FILE_APPEND);
    }

    private static function portalRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function workerScriptPath(): string
    {
        return self::portalRoot() . '/scripts/build-material-zip-job.php';
    }

    private static function phpBinary(): string
    {
        $configured = Config::get('PORTAL_PHP_CLI_BIN');
        if ($configured !== null && trim($configured) !== '') {
            return trim($configured);
        }

        $candidates = [];
        if (defined('PHP_BINARY')) {
            $binary = (string) PHP_BINARY;
            if (str_contains($binary, 'php-fpm')) {
                $candidates[] = preg_replace('#php-fpm#', 'php', $binary) ?: '';
                $candidates[] = dirname($binary) . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            } elseif (str_contains($binary, 'php')) {
                $candidates[] = $binary;
            }
        }

        foreach (['8.5', '8.4', '8.3', '8.2', ''] as $suffix) {
            $candidates[] = $suffix === '' ? '/usr/bin/php' : '/usr/bin/php' . $suffix;
        }
        $candidates[] = 'php';

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if (str_contains($candidate, 'php-fpm')) {
                continue;
            }
            if ($candidate === 'php' || @is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
