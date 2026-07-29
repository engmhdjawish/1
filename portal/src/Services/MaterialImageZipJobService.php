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

        $mode = trim((string) ($params['mode'] ?? 'materials'));
        if (!in_array($mode, ['materials', 'invoice'], true)) {
            throw new \RuntimeException('نوع التحميل غير مدعوم.');
        }

        if ($userId !== null && $userId !== '') {
            $pendingStmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM material_image_zip_jobs
                 WHERE requested_by_web_user_id = :user_id
                   AND status IN (\'queued\', \'building\', \'ready\')'
            );
            $pendingStmt->execute(['user_id' => $userId]);
            $pendingCount = (int) $pendingStmt->fetchColumn();
            if ($pendingCount >= self::MAX_PENDING_JOBS_PER_USER) {
                throw new \RuntimeException('لديك طلبات تحضير ZIP قيد الانتظار. انتظر انتهاءها أو حمّل الملف الجاهز.');
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

    public static function spawnWorker(): void
    {
        $lockPath = self::workerLockPath();
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            return;
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return;
        }

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        $php = self::phpBinary();
        $script = dirname(__DIR__, 2) . '/scripts/build-material-zip-job.php';
        if (!is_file($script)) {
            return;
        }

        $logPath = self::jobsDir(true) . '/worker.log';
        $command = sprintf(
            'nohup %s %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($logPath)
        );
        exec($command);
    }

    public static function runWorker(): void
    {
        self::ensureTable();
        self::cleanupExpiredJobs();

        $lockPath = self::workerLockPath();
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            exit(1);
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            exit(0);
        }

        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        fwrite($lockHandle, (string) getmypid());
        fflush($lockHandle);

        try {
            while (true) {
                $job = self::claimNextQueuedJob();
                if ($job === null) {
                    break;
                }
                self::processJob($job);
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
        $stmt = Database::pdo()->query(
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

    public static function getStatus(string $jobId, ?string $userId): array
    {
        self::ensureTable();
        $job = self::getJobForUser($jobId, $userId, false);

        return [
            'jobId' => $job['id'],
            'status' => $job['status'],
            'progressPct' => (int) ($job['progress_pct'] ?? 0),
            'progressMessage' => (string) ($job['progress_message'] ?? ''),
            'imageCount' => (int) ($job['image_count'] ?? 0),
            'fileName' => (string) ($job['file_name'] ?? ''),
            'errorMessage' => (string) ($job['error_message'] ?? ''),
            'downloadUrl' => ($job['status'] ?? '') === 'ready'
                ? '/api/material-images-zip-jobs.php?jobId=' . rawurlencode((string) $job['id']) . '&download=1'
                : null,
        ];
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

    private static function phpBinary(): string
    {
        $configured = Config::get('PORTAL_PHP_CLI_BIN');
        if ($configured !== null && trim($configured) !== '') {
            return trim($configured);
        }

        if (\PHP_SAPI === 'cli' && defined('PHP_BINARY') && str_contains((string) PHP_BINARY, 'php')) {
            return (string) PHP_BINARY;
        }

        return 'php';
    }

    private static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL', $output);

            return implode("\n", $output) !== '' && !str_contains(implode("\n", $output), 'No tasks');
        }

        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
    }
}
