<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;
use Throwable;

final class MaterialImageSyncService
{
    public static function ensureTable(): void
    {
        Database::pdo()->exec(
            "DO $$
             BEGIN
                 IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'material_image_sync_status') THEN
                     CREATE TYPE material_image_sync_status AS ENUM ('pending', 'syncing', 'synced', 'failed');
                 END IF;
             END $$"
        );

        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS material_image_sync_queue (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                file_name VARCHAR(255) NOT NULL,
                local_file_path VARCHAR(1000) NOT NULL,
                local_thumb_path VARCHAR(1000),
                local_size_bytes BIGINT,
                local_sha256 VARCHAR(64),
                amine_image_guid UUID,
                sync_status material_image_sync_status NOT NULL DEFAULT \'pending\',
                amine_sync_error_ar VARCHAR(500),
                synced_to_amine_at TIMESTAMPTZ,
                uploaded_by_web_user_id UUID REFERENCES web_users (id),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
             )'
        );

        Database::pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_material_image_sync_file ON material_image_sync_queue (file_name)'
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS ix_material_image_sync_status ON material_image_sync_queue (sync_status, created_at)'
        );
        Database::pdo()->exec(
            'ALTER TABLE material_image_sync_queue
                ADD COLUMN IF NOT EXISTS local_size_bytes BIGINT,
                ADD COLUMN IF NOT EXISTS local_sha256 VARCHAR(64)'
        );
    }

    public static function enqueue(
        string $fileName,
        string $localFilePath,
        ?string $localThumbPath,
        ?string $uploadedByUserId
    ): void {
        self::ensureTable();
        $fingerprint = self::fileFingerprint($localFilePath);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO material_image_sync_queue (
                file_name, local_file_path, local_thumb_path, local_size_bytes, local_sha256,
                uploaded_by_web_user_id, sync_status
             ) VALUES (
                :file_name, :local_file_path, :local_thumb_path, :local_size_bytes, :local_sha256,
                :uploaded_by_web_user_id, \'pending\'
             )
             ON CONFLICT (file_name) DO UPDATE SET
                local_file_path = EXCLUDED.local_file_path,
                local_thumb_path = EXCLUDED.local_thumb_path,
                local_size_bytes = EXCLUDED.local_size_bytes,
                local_sha256 = EXCLUDED.local_sha256,
                sync_status = CASE
                    WHEN material_image_sync_queue.sync_status = \'synced\'
                         AND material_image_sync_queue.local_sha256 IS NOT NULL
                         AND EXCLUDED.local_sha256 IS NOT NULL
                         AND material_image_sync_queue.local_sha256 = EXCLUDED.local_sha256
                    THEN \'synced\'
                    WHEN material_image_sync_queue.sync_status = \'synced\'
                    THEN \'pending\'
                    ELSE \'pending\'
                END,
                amine_sync_error_ar = CASE
                    WHEN material_image_sync_queue.sync_status = \'synced\'
                         AND material_image_sync_queue.local_sha256 IS NOT NULL
                         AND EXCLUDED.local_sha256 IS NOT NULL
                         AND material_image_sync_queue.local_sha256 = EXCLUDED.local_sha256
                    THEN material_image_sync_queue.amine_sync_error_ar
                    ELSE NULL
                END,
                updated_at = NOW()'
        );
        $stmt->execute([
            'file_name' => $fileName,
            'local_file_path' => $localFilePath,
            'local_thumb_path' => $localThumbPath,
            'local_size_bytes' => $fingerprint['size_bytes'] ?? null,
            'local_sha256' => $fingerprint['sha256'] ?? null,
            'uploaded_by_web_user_id' => $uploadedByUserId !== null && $uploadedByUserId !== '' ? $uploadedByUserId : null,
        ]);
    }

    /** @return array{pending: int, syncing: int, synced: int, failed: int, total: int} */
    public static function stats(): array
    {
        self::ensureTable();
        $stmt = Database::pdo()->query(
            "SELECT sync_status::text AS status, COUNT(*)::int AS count
             FROM material_image_sync_queue
             GROUP BY sync_status"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $counts = ['pending' => 0, 'syncing' => 0, 'synced' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['count'] ?? 0);
            }
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int,
     *   has_more: bool
     * }
     */
    public static function listQueuePage(int $page = 1, int $pageSize = 20): array
    {
        self::ensureTable();
        $page = max(1, $page);
        $pageSize = max(5, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $totalStmt = Database::pdo()->query('SELECT COUNT(*)::int FROM material_image_sync_queue');
        $totalCount = (int) ($totalStmt->fetchColumn() ?: 0);

        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                file_name,
                local_file_path,
                local_size_bytes,
                local_sha256,
                amine_image_guid::text AS amine_image_guid,
                sync_status::text AS sync_status,
                amine_sync_error_ar,
                synced_to_amine_at::text AS synced_to_amine_at,
                created_at::text AS created_at,
                updated_at::text AS updated_at
             FROM material_image_sync_queue
             ORDER BY
                CASE sync_status
                    WHEN \'syncing\' THEN 0
                    WHEN \'pending\' THEN 1
                    WHEN \'failed\' THEN 2
                    ELSE 3
                END,
                created_at ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => $totalCount,
            'has_more' => ($offset + count($items)) < $totalCount,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listQueue(int $limit = 80): array
    {
        return self::listQueuePage(1, max(1, min(200, $limit)))['items'];
    }

    /**
     * @return array{
     *   reconciled: int,
     *   content_changed: int,
     *   offline: bool,
     *   message: string
     * }
     */
    public static function reconcileQueueWithAmine(): array
    {
        self::ensureTable();
        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return [
                'reconciled' => 0,
                'content_changed' => 0,
                'offline' => true,
                'message' => 'الأمين غير متصل.',
            ];
        }

        $reconciled = 0;
        $contentChanged = 0;
        $stmt = Database::pdo()->query(
            "SELECT
                id::text AS id,
                file_name,
                local_file_path,
                local_thumb_path,
                sync_status::text AS sync_status
             FROM material_image_sync_queue
             WHERE sync_status IN ('pending', 'failed', 'syncing')"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $outcome = self::reconcileQueueRow($row);
            if ($outcome === 'reconciled') {
                $reconciled++;
            } elseif ($outcome === 'content_changed') {
                $contentChanged++;
            }
        }

        return [
            'reconciled' => $reconciled,
            'content_changed' => $contentChanged,
            'offline' => false,
            'message' => self::reconcileMessage($reconciled, $contentChanged, 0, 0),
        ];
    }

    /** @return array{added: int, skipped: int, reconciled: int, content_changed: int, offline?: bool, message: string} */
    public static function scanLocalFiles(?string $uploadedByUserId = null): array
    {
        self::ensureTable();
        $added = 0;
        $skipped = 0;
        $reconciled = 0;
        $contentChanged = 0;

        $queueReconcile = self::reconcileQueueWithAmine();
        if ($queueReconcile['offline'] ?? false) {
            return [
                'added' => 0,
                'skipped' => 0,
                'reconciled' => 0,
                'content_changed' => 0,
                'offline' => true,
                'message' => (string) ($queueReconcile['message'] ?? 'الأمين غير متصل.'),
            ];
        }
        $reconciled += (int) ($queueReconcile['reconciled'] ?? 0);
        $contentChanged += (int) ($queueReconcile['content_changed'] ?? 0);

        foreach (MaterialImageStorageService::listLocalFiles() as $file) {
            $fileName = (string) ($file['file_name'] ?? '');
            $localPath = (string) ($file['local_path'] ?? '');
            if ($fileName === '' || $localPath === '') {
                continue;
            }

            $fingerprint = self::fileFingerprint($localPath);
            if ($fingerprint === null) {
                continue;
            }

            $existing = self::getQueueRowByFileName($fileName);
            if ($existing !== null) {
                $outcome = self::reconcileQueueRow($existing);
                if ($outcome === 'reconciled') {
                    $reconciled++;
                    $skipped++;
                    continue;
                }
                if ($outcome === 'content_changed') {
                    $contentChanged++;
                    $skipped++;
                    continue;
                }
                if ((string) ($existing['sync_status'] ?? '') === 'synced') {
                    $skipped++;
                    continue;
                }
                $skipped++;
                continue;
            }

            $amine = self::lookupOnAmine($fileName);
            if ($amine !== null && self::fingerprintsMatch($fingerprint, $amine)) {
                self::upsertSyncedMatch($fileName, $localPath, null, $amine, $fingerprint, $uploadedByUserId);
                $reconciled++;
                continue;
            }

            if ($amine !== null) {
                self::enqueue($fileName, $localPath, null, $uploadedByUserId);
                $contentChanged++;
                $added++;
                continue;
            }

            self::enqueue($fileName, $localPath, null, $uploadedByUserId);
            $added++;
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'reconciled' => $reconciled,
            'content_changed' => $contentChanged,
            'message' => self::reconcileMessage($reconciled, $contentChanged, $added, $skipped),
        ];
    }

    /** @return array{ok: bool, offline?: bool, done?: bool, skipped?: bool, message: string, item?: array<string, mixed>} */
    public static function syncNext(): array
    {
        self::ensureTable();

        $health = PortalSettingsService::apiHealth();
        if (!$health['ok']) {
            return [
                'ok' => false,
                'offline' => true,
                'message' => 'الأمين غير متصل: ' . (string) ($health['message'] ?? ''),
            ];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query(
                "SELECT
                    id::text AS id,
                    file_name,
                    local_file_path,
                    local_thumb_path,
                    sync_status::text AS sync_status
                 FROM material_image_sync_queue
                 WHERE sync_status IN ('pending', 'failed')
                 ORDER BY created_at ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->commit();

                return [
                    'ok' => true,
                    'done' => true,
                    'message' => 'لا توجد صور بانتظار المزامنة مع الأمين.',
                ];
            }

            $id = (string) ($row['id'] ?? '');
            $fileName = (string) ($row['file_name'] ?? '');
            $localPath = (string) ($row['local_file_path'] ?? '');

            $update = $pdo->prepare(
                "UPDATE material_image_sync_queue
                 SET sync_status = 'syncing', amine_sync_error_ar = NULL, updated_at = NOW()
                 WHERE id = :id"
            );
            $update->execute(['id' => $id]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        if (!is_file($localPath)) {
            self::markFailed($id, 'الملف غير موجود على سيرفر الموقع.');

            return [
                'ok' => false,
                'message' => 'الملف غير موجود: ' . $fileName,
                'item' => self::getById($id),
            ];
        }

        $fingerprint = self::fileFingerprint($localPath);
        $amine = self::lookupOnAmine($fileName);
        if ($fingerprint !== null && $amine !== null && self::fingerprintsMatch($fingerprint, $amine)) {
            self::markSyncedFromMatch($id, $amine, $fingerprint);

            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'الصورة موجودة مسبقاً على الأمين بنفس المحتوى — تم اعتبارها متزامنة: ' . $fileName,
                'item' => self::getById($id),
            ];
        }

        try {
            $response = ApiClient::postMultipart('/api/material-images', [], [[
                'name' => 'Files',
                'path' => $localPath,
                'mime' => MaterialImageStorageService::mimeForPath($localPath),
                'filename' => $fileName,
            ]]);
        } catch (Throwable $exception) {
            self::markFailed($id, $exception->getMessage());

            return [
                'ok' => false,
                'offline' => str_contains($exception->getMessage(), 'API'),
                'message' => $exception->getMessage(),
                'item' => self::getById($id),
            ];
        }

        if (!($response['ok'] ?? false)) {
            $message = (string) ($response['error'] ?? ($response['data']['message'] ?? 'فشل رفع الصورة للأمين.'));
            self::markFailed($id, $message);

            return [
                'ok' => false,
                'message' => $message,
                'item' => self::getById($id),
            ];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $amineGuid = trim((string) ($data['id'] ?? $data['Id'] ?? ''));
        if ($amineGuid === '') {
            self::markFailed($id, 'لم يُرجع API معرف الصورة.');

            return [
                'ok' => false,
                'message' => 'لم يُرجع API معرف الصورة.',
                'item' => self::getById($id),
            ];
        }

        self::markSynced($id, $amineGuid, $fingerprint);

        return [
            'ok' => true,
            'message' => 'تمت مزامنة ' . $fileName . ' مع الأمين.',
            'item' => self::getById($id),
        ];
    }

    public static function resetFailedToPending(): int
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare(
            "UPDATE material_image_sync_queue
             SET sync_status = 'pending', amine_sync_error_ar = NULL, updated_at = NOW()
             WHERE sync_status = 'failed'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    public static function recoverStaleSyncing(): int
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare(
            "UPDATE material_image_sync_queue
             SET sync_status = 'pending', updated_at = NOW()
             WHERE sync_status = 'syncing'
               AND updated_at < NOW() - INTERVAL '10 minutes'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** @return array{size_bytes: int, sha256: string}|null */
    public static function fileFingerprint(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            return null;
        }

        return [
            'size_bytes' => (int) (filesize($path) ?: 0),
            'sha256' => strtolower($hash),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function lookupOnAmine(string $fileName): ?array
    {
        try {
            $response = ApiClient::get('/api/material-images/lookup', ['fileName' => $fileName]);
            if (($response['status'] ?? 0) === 404) {
                return null;
            }
            if (!($response['ok'] ?? false)) {
                return null;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : null;

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{size_bytes: int, sha256: string} $local */
    /** @param array<string, mixed> $amine */
    private static function fingerprintsMatch(array $local, array $amine): bool
    {
        $existsOnDisk = (bool) ($amine['fileExistsOnDisk'] ?? $amine['FileExistsOnDisk'] ?? false);
        if (!$existsOnDisk) {
            return false;
        }

        $localSha = strtolower(trim((string) ($local['sha256'] ?? '')));
        $amineSha = strtolower(trim((string) ($amine['sha256'] ?? $amine['Sha256'] ?? '')));
        if ($localSha !== '' && $amineSha !== '') {
            return hash_equals($amineSha, $localSha);
        }

        $localSize = (int) ($local['size_bytes'] ?? 0);
        $amineSize = (int) ($amine['sizeBytes'] ?? $amine['SizeBytes'] ?? 0);

        return $localSize > 0 && $localSize === $amineSize;
    }

    /** @param array<string, mixed> $row */
    private static function reconcileQueueRow(array $row): ?string
    {
        $fileName = (string) ($row['file_name'] ?? '');
        $localPath = (string) ($row['local_file_path'] ?? '');
        $id = (string) ($row['id'] ?? '');
        if ($fileName === '' || $localPath === '' || !is_file($localPath)) {
            return null;
        }

        $fingerprint = self::fileFingerprint($localPath);
        if ($fingerprint === null) {
            return null;
        }

        $amine = self::lookupOnAmine($fileName);
        if ($amine === null) {
            return null;
        }

        if (self::fingerprintsMatch($fingerprint, $amine)) {
            if ($id !== '') {
                self::markSyncedFromMatch($id, $amine, $fingerprint);
            } else {
                self::upsertSyncedMatch(
                    $fileName,
                    $localPath,
                    isset($row['local_thumb_path']) ? (string) $row['local_thumb_path'] : null,
                    $amine,
                    $fingerprint,
                    null
                );
            }

            return 'reconciled';
        }

        if ($id !== '' && (string) ($row['sync_status'] ?? '') !== 'pending') {
            self::markPendingWithFingerprint($id, $fingerprint, 'المحتوى على الموقع يختلف عن نسخة الأمين — ستُعاد المزامنة.');
        }

        return 'content_changed';
    }

    /** @param array<string, mixed> $amine */
    /** @param array{size_bytes: int, sha256: string} $fingerprint */
    private static function upsertSyncedMatch(
        string $fileName,
        string $localPath,
        ?string $localThumbPath,
        array $amine,
        array $fingerprint,
        ?string $uploadedByUserId
    ): void {
        $amineGuid = self::normalizeAmineGuid($amine['id'] ?? $amine['Id'] ?? null);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO material_image_sync_queue (
                file_name, local_file_path, local_thumb_path, local_size_bytes, local_sha256,
                amine_image_guid, uploaded_by_web_user_id, sync_status, synced_to_amine_at
             ) VALUES (
                :file_name, :local_file_path, :local_thumb_path, :local_size_bytes, :local_sha256,
                :amine_image_guid, :uploaded_by_web_user_id, \'synced\', NOW()
             )
             ON CONFLICT (file_name) DO UPDATE SET
                local_file_path = EXCLUDED.local_file_path,
                local_thumb_path = EXCLUDED.local_thumb_path,
                local_size_bytes = EXCLUDED.local_size_bytes,
                local_sha256 = EXCLUDED.local_sha256,
                amine_image_guid = EXCLUDED.amine_image_guid,
                sync_status = \'synced\',
                amine_sync_error_ar = NULL,
                synced_to_amine_at = NOW(),
                updated_at = NOW()'
        );
        $stmt->execute([
            'file_name' => $fileName,
            'local_file_path' => $localPath,
            'local_thumb_path' => $localThumbPath,
            'local_size_bytes' => $fingerprint['size_bytes'],
            'local_sha256' => $fingerprint['sha256'],
            'amine_image_guid' => $amineGuid,
            'uploaded_by_web_user_id' => $uploadedByUserId !== null && $uploadedByUserId !== '' ? $uploadedByUserId : null,
        ]);
    }

    /** @param array<string, mixed> $amine */
    /** @param array{size_bytes: int, sha256: string} $fingerprint */
    private static function markSyncedFromMatch(string $id, array $amine, array $fingerprint): void
    {
        $amineGuid = self::normalizeAmineGuid($amine['id'] ?? $amine['Id'] ?? null);
        $stmt = Database::pdo()->prepare(
            "UPDATE material_image_sync_queue
             SET sync_status = 'synced',
                 amine_image_guid = :amine_image_guid,
                 local_size_bytes = :local_size_bytes,
                 local_sha256 = :local_sha256,
                 synced_to_amine_at = NOW(),
                 amine_sync_error_ar = NULL,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'amine_image_guid' => $amineGuid,
            'local_size_bytes' => $fingerprint['size_bytes'],
            'local_sha256' => $fingerprint['sha256'],
        ]);
    }

    /** @param array{size_bytes: int, sha256: string}|null $fingerprint */
    private static function markSynced(string $id, string $amineGuid, ?array $fingerprint = null): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE material_image_sync_queue
             SET sync_status = 'synced',
                 amine_image_guid = :amine_image_guid,
                 local_size_bytes = COALESCE(:local_size_bytes, local_size_bytes),
                 local_sha256 = COALESCE(:local_sha256, local_sha256),
                 synced_to_amine_at = NOW(),
                 amine_sync_error_ar = NULL,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'amine_image_guid' => $amineGuid,
            'local_size_bytes' => $fingerprint['size_bytes'] ?? null,
            'local_sha256' => $fingerprint['sha256'] ?? null,
        ]);
    }

    /** @param array{size_bytes: int, sha256: string} $fingerprint */
    private static function markPendingWithFingerprint(string $id, array $fingerprint, string $note): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE material_image_sync_queue
             SET sync_status = 'pending',
                 local_size_bytes = :local_size_bytes,
                 local_sha256 = :local_sha256,
                 amine_sync_error_ar = :note,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'local_size_bytes' => $fingerprint['size_bytes'],
            'local_sha256' => $fingerprint['sha256'],
            'note' => $note,
        ]);
    }

    private static function markFailed(string $id, string $error): void
    {
        $error = trim($error);
        if (strlen($error) > 480) {
            $error = substr($error, 0, 480) . '...';
        }

        $stmt = Database::pdo()->prepare(
            "UPDATE material_image_sync_queue
             SET sync_status = 'failed',
                 amine_sync_error_ar = :error,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'error' => $error !== '' ? $error : 'فشل غير معروف',
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function getQueueRowByFileName(string $fileName): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                file_name,
                local_file_path,
                local_thumb_path,
                sync_status::text AS sync_status
             FROM material_image_sync_queue
             WHERE file_name = :file_name
             LIMIT 1'
        );
        $stmt->execute(['file_name' => $fileName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private static function getById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                file_name,
                amine_image_guid::text AS amine_image_guid,
                sync_status::text AS sync_status,
                amine_sync_error_ar,
                synced_to_amine_at::text AS synced_to_amine_at
             FROM material_image_sync_queue
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function normalizeAmineGuid(mixed $value): ?string
    {
        $guid = trim((string) $value);
        if ($guid === '' || strcasecmp($guid, '00000000-0000-0000-0000-000000000000') === 0) {
            return null;
        }

        return $guid;
    }

    private static function reconcileMessage(int $reconciled, int $contentChanged, int $added, int $skipped): string
    {
        $parts = [];
        if ($reconciled > 0) {
            $parts[] = 'تطابقت ' . $reconciled . ' صورة مع الأمين (بدون رفع)';
        }
        if ($added > 0) {
            $parts[] = 'أُضيف ' . $added . ' للطابور';
        }
        if ($contentChanged > 0) {
            $parts[] = $contentChanged . ' بمحتوى مختلف عن الأمين — ستُرفع عند المزامنة';
        }
        if ($skipped > 0) {
            $parts[] = 'تُخطّى ' . $skipped;
        }
        if ($parts === []) {
            return 'لا تغييرات.';
        }

        return implode('، ', $parts) . '.';
    }
}
