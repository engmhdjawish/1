<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;
use Throwable;

/**
 * Safe cleanup for image files with no bm000 record (portal disk, Amine disk, stale queue).
 */
final class MaterialImageOrphanCleanupService
{
    private const SCAN_TTL_SECONDS = 3600;
    private const PORTAL_CHUNK = 40;
    private const PURGE_CHUNK = 25;

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   scan_id?: string,
     *   registry_count?: int,
     *   local_file_count?: int,
     *   offline?: bool
     * }
     */
    public static function initScan(): array
    {
        $health = PortalSettingsService::apiHealth();
        if (!($health['ok'] ?? false)) {
            return [
                'ok' => false,
                'offline' => true,
                'message' => (string) ($health['message'] ?? 'الأمين غير متصل.'),
            ];
        }

        try {
            $registry = self::buildRegistryIndex();
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'تعذر قراءة سجل الأمين: ' . $exception->getMessage(),
            ];
        }

        $localFiles = MaterialImageStorageService::listLocalFiles();
        $scanId = bin2hex(random_bytes(8));
        $payload = [
            'scan_id' => $scanId,
            'created_at' => time(),
            'registry' => $registry,
            'local_files' => array_values(array_map(
                static fn (array $file): array => [
                    'file_name' => (string) ($file['file_name'] ?? ''),
                    'local_path' => (string) ($file['local_path'] ?? ''),
                ],
                $localFiles
            )),
            'portal_orphans' => [],
            'amine_orphans' => [],
            'stale_queue' => [],
            'portal_offset' => 0,
            'amine_offset' => 0,
            'amine_done' => false,
            'queue_scanned' => false,
        ];
        self::writeCache($scanId, $payload);

        return [
            'ok' => true,
            'scan_id' => $scanId,
            'registry_count' => (int) ($registry['registered_count'] ?? 0),
            'local_file_count' => count($localFiles),
            'message' => 'جاهز لفحص الموقع وقرص الأمين — لن يُحذف شيء حتى تؤكد الحذف.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scanPortalChunk(string $scanId, int $chunkSize = self::PORTAL_CHUNK): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : [];
        $files = is_array($cache['local_files'] ?? null) ? $cache['local_files'] : [];
        $offset = max(0, (int) ($cache['portal_offset'] ?? 0));
        $chunkSize = max(5, min(80, $chunkSize));
        $slice = array_slice($files, $offset, $chunkSize);

        $orphans = is_array($cache['portal_orphans'] ?? null) ? $cache['portal_orphans'] : [];
        foreach ($slice as $file) {
            $fileName = (string) ($file['file_name'] ?? '');
            if ($fileName === '') {
                continue;
            }
            $reason = self::classifyPortalFile($fileName, $registry);
            if ($reason !== null) {
                $orphans[] = [
                    'file_name' => $fileName,
                    'reason' => $reason,
                    'scope' => 'portal',
                ];
            }
        }

        $cache['portal_orphans'] = $orphans;
        $cache['portal_offset'] = $offset + count($slice);
        self::writeCache($scanId, $cache);

        $total = count($files);
        $done = $cache['portal_offset'] >= $total;

        return [
            'ok' => true,
            'done' => $done,
            'offset' => $cache['portal_offset'],
            'total' => $total,
            'portal_orphan_count' => count($orphans),
            'message' => $done
                ? ('انتهى فحص الموقع — ' . count($orphans) . ' ملف يتيم.')
                : ('فحص الموقع: ' . $cache['portal_offset'] . ' / ' . $total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scanAmineChunk(string $scanId, int $limit = 80): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        if ($cache['amine_done'] ?? false) {
            $orphans = is_array($cache['amine_orphans'] ?? null) ? $cache['amine_orphans'] : [];

            return [
                'ok' => true,
                'done' => true,
                'amine_orphan_count' => count($orphans),
                'message' => 'انتهى فحص قرص الأمين.',
            ];
        }

        $offset = max(0, (int) ($cache['amine_offset'] ?? 0));
        $limit = max(10, min(100, $limit));

        try {
            $response = ApiClient::get('/api/material-images/orphan-disk-files', [
                'offset' => $offset,
                'limit' => $limit,
            ]);
            $response = self::apiPayload($response);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'تعذر فحص قرص الأمين: ' . $exception->getMessage(),
            ];
        }

        if (!($response['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($response['message'] ?? 'تعذر فحص قرص الأمين.'),
            ];
        }

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        $orphans = is_array($cache['amine_orphans'] ?? null) ? $cache['amine_orphans'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fileName = basename(str_replace('\\', '/', (string) ($row['fileName'] ?? $row['file_name'] ?? '')));
            if ($fileName === '' || str_contains($fileName, '..')) {
                continue;
            }
            $orphans[$fileName] = [
                'file_name' => $fileName,
                'size_bytes' => (int) ($row['sizeBytes'] ?? $row['size_bytes'] ?? 0),
                'scope' => 'amine',
            ];
        }

        $cache['amine_orphans'] = array_values($orphans);
        $cache['amine_offset'] = (int) ($response['offset'] ?? ($offset + count($items)));
        $done = (bool) ($response['done'] ?? (count($items) === 0));
        $cache['amine_done'] = $done;
        self::writeCache($scanId, $cache);

        $total = (int) ($response['total'] ?? count($cache['amine_orphans']));

        return [
            'ok' => true,
            'done' => $done,
            'offset' => $cache['amine_offset'],
            'total' => $total,
            'amine_orphan_count' => count($cache['amine_orphans']),
            'message' => $done
                ? ('انتهى فحص الأمين — ' . count($cache['amine_orphans']) . ' ملف بلا سجل.')
                : ('فحص الأمين: ' . $cache['amine_offset'] . ' / ' . $total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scanStaleQueue(string $scanId): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : [];
        $stale = self::findStaleQueueRows($registry);
        $cache['stale_queue'] = $stale;
        $cache['queue_scanned'] = true;
        self::writeCache($scanId, $cache);

        return [
            'ok' => true,
            'stale_queue_count' => count($stale),
            'message' => count($stale) > 0
                ? ('وُجد ' . count($stale) . ' سجل طابور بلا صورة في الأمين.')
                : 'لا توجد سجلات طابور يتيمة.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(string $scanId): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص.'];
        }

        $portalOrphans = is_array($cache['portal_orphans'] ?? null) ? $cache['portal_orphans'] : [];
        $amineOrphans = is_array($cache['amine_orphans'] ?? null) ? $cache['amine_orphans'] : [];
        $staleQueue = is_array($cache['stale_queue'] ?? null) ? $cache['stale_queue'] : [];

        return [
            'ok' => true,
            'portal_orphan_count' => count($portalOrphans),
            'amine_orphan_count' => count($amineOrphans),
            'stale_queue_count' => count($staleQueue),
            'samples' => [
                'portal' => array_slice($portalOrphans, 0, 15),
                'amine' => array_slice($amineOrphans, 0, 15),
                'queue' => array_slice($staleQueue, 0, 15),
            ],
            'ready' => ($cache['portal_offset'] ?? 0) >= count($cache['local_files'] ?? [])
                && ($cache['amine_done'] ?? false)
                && ($cache['queue_scanned'] ?? false),
            'message' => 'معاينة فقط — الحذف يتطلب تأكيداً.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function purge(
        string $scanId,
        bool $purgePortal,
        bool $purgeAmine,
        bool $purgeQueue,
        bool $dryRun = false
    ): array {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد الفحص قبل الحذف.'];
        }

        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'الأمين غير متصل.'];
        }

        try {
            $registry = self::buildRegistryIndex();
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'تعذر التحقق من سجل الأمين: ' . $exception->getMessage()];
        }

        $portalDeleted = 0;
        $portalSkipped = 0;
        $amineDeleted = 0;
        $amineSkipped = 0;
        $queuePurged = 0;

        if ($purgePortal) {
            $rows = is_array($cache['portal_orphans'] ?? null) ? $cache['portal_orphans'] : [];
            foreach ($rows as $row) {
                $fileName = basename(str_replace('\\', '/', (string) ($row['file_name'] ?? '')));
                if ($fileName === '' || self::classifyPortalFile($fileName, $registry) === null) {
                    $portalSkipped++;
                    continue;
                }
                if (!$dryRun) {
                    MaterialImageSyncService::forgetFileName($fileName);
                }
                $portalDeleted++;
            }
        }

        if ($purgeAmine) {
            $rows = is_array($cache['amine_orphans'] ?? null) ? $cache['amine_orphans'] : [];
            $fileNames = [];
            foreach ($rows as $row) {
                $fileName = basename(str_replace('\\', '/', (string) ($row['file_name'] ?? '')));
                if ($fileName === '') {
                    continue;
                }
                $fileNames[] = $fileName;
            }
            foreach (array_chunk($fileNames, self::PURGE_CHUNK) as $chunk) {
                try {
                    $apiResponse = ApiClient::postJson('/api/material-images/purge-orphan-disk-files', [
                        'fileNames' => $chunk,
                        'dryRun' => $dryRun,
                    ]);
                    $response = self::apiPayload($apiResponse);
                } catch (Throwable $exception) {
                    return [
                        'ok' => false,
                        'message' => 'تعذر حذف ملفات الأمين: ' . $exception->getMessage(),
                        'portal_deleted' => $portalDeleted,
                    ];
                }
                if (!($response['ok'] ?? false) && !isset($response['deleted'])) {
                    return [
                        'ok' => false,
                        'message' => (string) ($response['message'] ?? 'تعذر حذف ملفات الأمين.'),
                        'portal_deleted' => $portalDeleted,
                    ];
                }
                $amineDeleted += count(is_array($response['deleted'] ?? null) ? $response['deleted'] : []);
                $amineSkipped += count(is_array($response['skipped'] ?? null) ? $response['skipped'] : []);
            }
        }

        if ($purgeQueue) {
            $rows = is_array($cache['stale_queue'] ?? null) ? $cache['stale_queue'] : [];
            foreach ($rows as $row) {
                $fileName = basename(str_replace('\\', '/', (string) ($row['file_name'] ?? '')));
                $guid = strtolower(trim((string) ($row['amine_image_guid'] ?? '')));
                $stillStale = $guid !== '' && !isset($registry['guids'][$guid]);
                if (!$stillStale && $fileName !== '') {
                    $stillStale = self::classifyPortalFile($fileName, $registry) !== null;
                }
                if (!$stillStale) {
                    continue;
                }
                if (!$dryRun) {
                    if ($fileName !== '') {
                        MaterialImageSyncService::forgetFileName($fileName);
                    } elseif ($guid !== '') {
                        MaterialImageSyncService::purgeImageRecords($guid, '');
                    }
                }
                $queuePurged++;
            }
        }

        $message = $dryRun
            ? sprintf(
                'معاينة حذف: موقع %d، أمين %d، طابور %d (بدون تنفيذ).',
                $portalDeleted,
                $amineDeleted,
                $queuePurged
            )
            : sprintf(
                'تم الحذف — موقع %d، أمين %d، طابور %d%s.',
                $portalDeleted,
                $amineDeleted,
                $queuePurged,
                $amineSkipped > 0 ? " (تخطي أمين $amineSkipped — أُعيد تسجيلها)" : ''
            );

        if (!$dryRun) {
            self::deleteCache($scanId);
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'portal_deleted' => $portalDeleted,
            'portal_skipped' => $portalSkipped,
            'amine_deleted' => $amineDeleted,
            'amine_skipped' => $amineSkipped,
            'queue_purged' => $queuePurged,
            'message' => $message,
        ];
    }

    /**
     * @return array{
     *   file_names: array<string, true>,
     *   guids: array<string, true>,
     *   registered_count: int
     * }
     */
    private static function buildRegistryIndex(): array
    {
        $fileNames = [];
        $guids = [];
        $page = 1;

        do {
            $chunk = MaterialImageLinkService::listSourcesPage($page, 60, 'all', '');
            foreach ($chunk['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = strtolower(basename(str_replace('\\', '/', (string) ($item['file_name'] ?? ''))));
                if ($name !== '') {
                    $fileNames[$name] = true;
                }
                $guid = strtolower(trim((string) ($item['amine_image_guid'] ?? '')));
                if ($guid !== '') {
                    $guids[$guid] = true;
                }
            }
            $page++;
        } while ($chunk['has_more'] ?? false);

        return [
            'file_names' => $fileNames,
            'guids' => $guids,
            'registered_count' => count($fileNames),
        ];
    }

    /**
     * @param array{file_names: array<string, true>, guids: array<string, true>} $registry
     */
    private static function classifyPortalFile(string $fileName, array $registry): ?string
    {
        $base = strtolower(basename(str_replace('\\', '/', trim($fileName))));
        if ($base === '' || str_contains($base, '..')) {
            return null;
        }

        if (isset($registry['file_names'][$base])) {
            return null;
        }

        if (preg_match('/^([0-9a-f-]{36})\.(jpe?g|png|gif|webp)$/i', $base, $matches) === 1) {
            $guid = strtolower($matches[1]);
            if (isset($registry['guids'][$guid])) {
                return null;
            }

            return 'guid_not_in_bm000';
        }

        return 'no_bm000_record';
    }

    /**
     * @param array{file_names: array<string, true>, guids: array<string, true>} $registry
     * @return list<array<string, mixed>>
     */
    private static function findStaleQueueRows(array $registry): array
    {
        MaterialImageSyncService::ensureTable();
        $stmt = Database::pdo()->query(
            'SELECT id::text AS id, file_name, amine_image_guid::text AS amine_image_guid, sync_status
             FROM material_image_sync_queue
             ORDER BY created_at ASC'
        );
        $stale = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $guid = strtolower(trim((string) ($row['amine_image_guid'] ?? '')));
            $fileName = strtolower(basename(str_replace('\\', '/', (string) ($row['file_name'] ?? ''))));
            $inRegistry = ($guid !== '' && isset($registry['guids'][$guid]))
                || ($fileName !== '' && isset($registry['file_names'][$fileName]));
            if ($inRegistry) {
                continue;
            }
            if ($guid === '' && $fileName !== '' && self::classifyPortalFile((string) ($row['file_name'] ?? ''), $registry) === null) {
                continue;
            }
            $stale[] = [
                'id' => (string) ($row['id'] ?? ''),
                'file_name' => (string) ($row['file_name'] ?? ''),
                'amine_image_guid' => (string) ($row['amine_image_guid'] ?? ''),
                'sync_status' => (string) ($row['sync_status'] ?? ''),
                'scope' => 'queue',
            ];
        }

        return $stale;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeCache(string $scanId, array $payload): void
    {
        $dir = Config::storagePath() . '/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = self::cachePath($scanId);
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private static function readCache(string $scanId): ?array
    {
        $scanId = preg_replace('/[^a-f0-9]/', '', strtolower($scanId)) ?? '';
        if ($scanId === '') {
            return null;
        }
        $path = self::cachePath($scanId);
        if (!is_file($path)) {
            return null;
        }
        $mtime = (int) filemtime($path);
        if ($mtime > 0 && (time() - $mtime) > self::SCAN_TTL_SECONDS) {
            unlink($path);

            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function deleteCache(string $scanId): void
    {
        $path = self::cachePath($scanId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function cachePath(string $scanId): string
    {
        $scanId = preg_replace('/[^a-f0-9]/', '', strtolower($scanId)) ?? 'invalid';

        return Config::storagePath() . '/cache/material-orphan-scan-' . $scanId . '.json';
    }

    /** @param array<string, mixed> $response */
    private static function apiPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }
}
