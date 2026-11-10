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
    private const PORTAL_CHUNK = 80;
    private const PURGE_CHUNK = 25;
    private const REGISTRY_PAGE_SIZE = 100;
    private const PORTAL_BATCHES_PER_REQUEST = 8;
    private const REGISTRY_INDEX_TIMEOUT_SEC = 8;
    private const REGISTRY_PAGE_TIMEOUT_SEC = 25;

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

        $scanId = bin2hex(random_bytes(8));
        $payload = [
            'scan_id' => $scanId,
            'created_at' => time(),
            'registry' => self::emptyRegistry(),
            'registry_page' => 1,
            'registry_done' => false,
            'registry_total' => 0,
            'local_files' => [],
            'local_index_done' => false,
            'portal_orphans' => [],
            'amine_orphans' => [],
            'stale_queue' => [],
            'portal_offset' => 0,
            'amine_offset' => 0,
            'amine_done' => false,
            'amine_skipped' => false,
            'amine_skip_reason' => '',
            'queue_scanned' => false,
        ];
        self::writeCache($scanId, $payload);

        return [
            'ok' => true,
            'scan_id' => $scanId,
            'message' => 'بدء الفحص — سيتم تحميل سجل الأمين على دفعات (لتجنب انتهاء المهلة).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadRegistry(string $scanId): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        if ($cache['registry_done'] ?? false) {
            $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : self::emptyRegistry();

            return [
                'ok' => true,
                'done' => true,
                'use_chunked' => false,
                'registered_count' => (int) ($registry['registered_count'] ?? 0),
                'message' => 'سجل الأمين جاهز.',
            ];
        }

        try {
            $apiResponse = ApiClient::get(
                '/api/material-images/registry-index',
                [],
                self::REGISTRY_INDEX_TIMEOUT_SEC
            );
            if (!($apiResponse['ok'] ?? false)) {
                return [
                    'ok' => true,
                    'done' => false,
                    'use_chunked' => true,
                    'message' => 'تعذر التحميل السريع للسجل — متابعة على دفعات صغيرة.',
                ];
            }
            $payload = self::apiPayload($apiResponse);
            if (($payload['ok'] ?? false) && is_array($payload['fileNames'] ?? null)) {
                $fileNames = [];
                foreach ($payload['fileNames'] as $name) {
                    $base = strtolower(basename(str_replace('\\', '/', (string) $name)));
                    if ($base !== '') {
                        $fileNames[$base] = true;
                    }
                }
                $guids = [];
                foreach ($payload['guids'] ?? [] as $guid) {
                    $key = strtolower(trim((string) $guid));
                    if ($key !== '') {
                        $guids[$key] = true;
                    }
                }
                $registry = [
                    'file_names' => $fileNames,
                    'guids' => $guids,
                    'registered_count' => count($fileNames),
                ];
                $cache['registry'] = $registry;
                $cache['registry_done'] = true;
                $cache['registry_total'] = (int) ($payload['count'] ?? count($fileNames));
                self::writeCache($scanId, $cache);

                return [
                    'ok' => true,
                    'done' => true,
                    'use_chunked' => false,
                    'registered_count' => count($fileNames),
                    'registry_total' => (int) ($payload['count'] ?? count($fileNames)),
                    'message' => 'تحميل سجل الأمين دفعة واحدة — ' . count($fileNames) . ' صورة مسجّلة.',
                ];
            }
        } catch (Throwable) {
            // API قديم بدون registry-index — نكمل بالدفعات
        }

        return [
            'ok' => true,
            'done' => false,
            'use_chunked' => true,
            'message' => 'تحميل السجل على دفعات (حدّث API الأمين لتسريع أكبر).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scanRegistryChunk(string $scanId): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        if ($cache['registry_done'] ?? false) {
            $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : self::emptyRegistry();

            return [
                'ok' => true,
                'done' => true,
                'registered_count' => (int) ($registry['registered_count'] ?? 0),
                'registry_total' => (int) ($cache['registry_total'] ?? 0),
                'message' => 'اكتمل تحميل سجل الأمين.',
            ];
        }

        $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : self::emptyRegistry();
        $fileNames = is_array($registry['file_names'] ?? null) ? $registry['file_names'] : [];
        $guids = is_array($registry['guids'] ?? null) ? $registry['guids'] : [];
        $page = max(1, (int) ($cache['registry_page'] ?? 1));
        $totalCount = (int) ($cache['registry_total'] ?? 0);

        // Lightweight page read — never use listSourcesPage/mapLinkFileRow (lookups + previews freeze for minutes).
        try {
            $apiResponse = ApiClient::get(
                '/api/material-images/link-files',
                [
                    'page' => $page,
                    'pageSize' => self::REGISTRY_PAGE_SIZE,
                ],
                self::REGISTRY_PAGE_TIMEOUT_SEC
            );
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'تعذر قراءة سجل الأمين: ' . $exception->getMessage(),
            ];
        }

        if (!($apiResponse['ok'] ?? false)) {
            $status = (int) ($apiResponse['status'] ?? 0);
            $error = (string) ($apiResponse['error'] ?? '');

            return [
                'ok' => false,
                'message' => 'تعذر قراءة صفحة سجل الأمين'
                    . ($status > 0 ? " (HTTP {$status})" : '')
                    . ($error !== '' ? ': ' . $error : '.')
                    . ' أعد المحاولة أو انشر API الأمين (registry-index).',
            ];
        }

        $data = self::apiPayload($apiResponse);
        $rows = is_array($data['items'] ?? null)
            ? $data['items']
            : (is_array($data['Items'] ?? null) ? $data['Items'] : []);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawName = (string) ($row['fileName'] ?? $row['FileName'] ?? $row['name'] ?? $row['Name'] ?? '');
            $name = strtolower(basename(str_replace('\\', '/', $rawName)));
            if ($name !== '') {
                $fileNames[$name] = true;
            }
            $rawGuid = (string) (
                $row['imageGuid']
                ?? $row['ImageGuid']
                ?? $row['id']
                ?? $row['Id']
                ?? $row['guid']
                ?? $row['Guid']
                ?? ''
            );
            $guid = strtolower(trim($rawGuid));
            if ($guid !== '' && $guid !== '00000000-0000-0000-0000-000000000000') {
                $guids[$guid] = true;
            }
        }

        $totalCount = max(
            $totalCount,
            (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0)
        );
        $responsePage = max(1, (int) ($data['page'] ?? $data['Page'] ?? $page));
        $responsePageSize = max(1, (int) ($data['pageSize'] ?? $data['PageSize'] ?? self::REGISTRY_PAGE_SIZE));
        $nextPage = $responsePage + 1;
        $done = ($responsePage * $responsePageSize) >= $totalCount || $rows === [];

        $registry['file_names'] = $fileNames;
        $registry['guids'] = $guids;
        $registry['registered_count'] = count($fileNames);

        $cache['registry'] = $registry;
        $cache['registry_page'] = $nextPage;
        $cache['registry_total'] = $totalCount;
        $cache['registry_done'] = $done;
        self::writeCache($scanId, $cache);

        $loaded = count($fileNames);
        $message = $done
            ? ('اكتمل سجل الأمين — ' . $loaded . ' صورة مسجّلة.')
            : ('تحميل سجل الأمين: صفحة ' . $responsePage . ' — ' . $loaded . ' / ' . max($totalCount, $loaded));

        return [
            'ok' => true,
            'done' => $done,
            'page' => $nextPage,
            'registered_count' => $loaded,
            'registry_total' => $totalCount,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function indexLocalFiles(string $scanId): array
    {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        if (!($cache['registry_done'] ?? false)) {
            return ['ok' => false, 'message' => 'أكمل تحميل سجل الأمين أولاً.'];
        }

        if ($cache['local_index_done'] ?? false) {
            return [
                'ok' => true,
                'done' => true,
                'local_file_count' => count($cache['local_files'] ?? []),
                'message' => 'فهرس الموقع جاهز.',
            ];
        }

        $localFiles = MaterialImageStorageService::listLocalImageEntriesForScan();
        $cache['local_files'] = $localFiles;
        $cache['local_index_done'] = true;
        $cache['portal_offset'] = 0;
        $cache['portal_orphans'] = [];
        self::writeCache($scanId, $cache);

        return [
            'ok' => true,
            'done' => true,
            'local_file_count' => count($localFiles),
            'message' => 'فُهرست ' . count($localFiles) . ' ملفاً على الموقع.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scanPortalChunk(
        string $scanId,
        int $chunkSize = self::PORTAL_CHUNK,
        int $batches = self::PORTAL_BATCHES_PER_REQUEST
    ): array {
        $cache = self::readCache($scanId);
        if ($cache === null) {
            return ['ok' => false, 'message' => 'انتهت جلسة الفحص — أعد «فحص آمن».'];
        }

        if (!($cache['registry_done'] ?? false) || !($cache['local_index_done'] ?? false)) {
            return ['ok' => false, 'message' => 'أكمل تحميل السجل وفهرسة الموقع قبل فحص الملفات.'];
        }

        $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : [];
        $files = is_array($cache['local_files'] ?? null) ? $cache['local_files'] : [];
        $offset = max(0, (int) ($cache['portal_offset'] ?? 0));
        $chunkSize = max(20, min(120, $chunkSize));
        $batches = max(1, min(15, $batches));

        $orphans = is_array($cache['portal_orphans'] ?? null) ? $cache['portal_orphans'] : [];
        for ($batch = 0; $batch < $batches; $batch++) {
            $slice = array_slice($files, $offset, $chunkSize);
            if ($slice === []) {
                break;
            }
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
            $offset += count($slice);
            if ($offset >= count($files)) {
                break;
            }
        }

        $cache['portal_orphans'] = $orphans;
        $cache['portal_offset'] = $offset;
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
            $apiResponse = ApiClient::get(
                '/api/material-images/orphan-disk-files',
                [
                    'offset' => $offset,
                    'limit' => $limit,
                ],
                45
            );
        } catch (Throwable $exception) {
            return self::skipAmineDiskScan(
                $scanId,
                $cache,
                'تعذر فحص قرص الأمين: ' . $exception->getMessage()
            );
        }

        if (!($apiResponse['ok'] ?? false)) {
            return self::skipAmineDiskScan(
                $scanId,
                $cache,
                self::formatApiFailureMessage(
                    $apiResponse,
                    'تعذر فحص قرص الأمين — غالباً API الأمين بدون مسار orphan-disk-files أو انتهت المهلة. انشر ExistingDb.Api.'
                )
            );
        }

        $response = self::apiPayload($apiResponse);
        if (!($response['ok'] ?? false)) {
            return self::skipAmineDiskScan(
                $scanId,
                $cache,
                (string) ($response['message'] ?? 'تعذر فحص قرص الأمين (استجابة API غير ناجحة).')
            );
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
        $cache['amine_skipped'] = false;
        $cache['amine_skip_reason'] = '';
        self::writeCache($scanId, $cache);

        $total = (int) ($response['total'] ?? count($cache['amine_orphans']));

        return [
            'ok' => true,
            'done' => $done,
            'skipped' => false,
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

        if (!($cache['registry_done'] ?? false)) {
            return ['ok' => false, 'message' => 'أكمل تحميل سجل الأمين أولاً.'];
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
        $amineSkipped = (bool) ($cache['amine_skipped'] ?? false);
        $amineSkipReason = trim((string) ($cache['amine_skip_reason'] ?? ''));

        return [
            'ok' => true,
            'portal_orphan_count' => count($portalOrphans),
            'amine_orphan_count' => count($amineOrphans),
            'stale_queue_count' => count($staleQueue),
            'amine_skipped' => $amineSkipped,
            'amine_skip_reason' => $amineSkipReason,
            'samples' => [
                'portal' => array_slice($portalOrphans, 0, 15),
                'amine' => array_slice($amineOrphans, 0, 15),
                'queue' => array_slice($staleQueue, 0, 15),
            ],
            'ready' => ($cache['registry_done'] ?? false)
                && ($cache['local_index_done'] ?? false)
                && ($cache['portal_offset'] ?? 0) >= count($cache['local_files'] ?? [])
                && ($cache['amine_done'] ?? false)
                && ($cache['queue_scanned'] ?? false),
            'message' => $amineSkipped
                ? ('معاينة موقع/طابور جاهزة. قرص الأمين تُخطّي: ' . ($amineSkipReason !== '' ? $amineSkipReason : 'API غير محدّث.'))
                : 'معاينة فقط — الحذف يتطلب تأكيداً.',
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

        if (!($cache['registry_done'] ?? false)) {
            return ['ok' => false, 'message' => 'أكمل الفحص أولاً قبل الحذف.'];
        }

        $registry = is_array($cache['registry'] ?? null) ? $cache['registry'] : self::emptyRegistry();

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
    private static function emptyRegistry(): array
    {
        return [
            'file_names' => [],
            'guids' => [],
            'registered_count' => 0,
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

    /**
     * Soft-fail Amine disk so portal orphan results are not discarded.
     *
     * @param array<string, mixed> $cache
     * @return array<string, mixed>
     */
    private static function skipAmineDiskScan(string $scanId, array $cache, string $reason): array
    {
        $cache['amine_orphans'] = is_array($cache['amine_orphans'] ?? null) ? $cache['amine_orphans'] : [];
        $cache['amine_done'] = true;
        $cache['amine_skipped'] = true;
        $cache['amine_skip_reason'] = $reason;
        self::writeCache($scanId, $cache);

        return [
            'ok' => true,
            'done' => true,
            'skipped' => true,
            'offset' => (int) ($cache['amine_offset'] ?? 0),
            'total' => count($cache['amine_orphans']),
            'amine_orphan_count' => count($cache['amine_orphans']),
            'message' => $reason . ' — متابعة بنتائج الموقع فقط.',
        ];
    }

    /** @param array<string, mixed> $apiResponse */
    private static function formatApiFailureMessage(array $apiResponse, string $fallback): string
    {
        $status = (int) ($apiResponse['status'] ?? 0);
        $error = trim((string) ($apiResponse['error'] ?? ''));
        $data = is_array($apiResponse['data'] ?? null) ? $apiResponse['data'] : [];
        $detail = trim((string) (
            $data['message']
            ?? $data['Message']
            ?? $data['title']
            ?? $data['Title']
            ?? $data['detail']
            ?? $data['Detail']
            ?? ''
        ));

        if ($status === 404) {
            return 'مسار orphan-disk-files غير موجود على API الأمين (HTTP 404) — انشر ExistingDb.Api المحدَّث.';
        }
        if ($status > 0 && $detail !== '') {
            return "تعذر فحص قرص الأمين (HTTP {$status}): {$detail}";
        }
        if ($status > 0 && $error !== '') {
            return "تعذر فحص قرص الأمين (HTTP {$status}): {$error}";
        }
        if ($status > 0) {
            return "تعذر فحص قرص الأمين (HTTP {$status}).";
        }
        if ($error !== '') {
            return 'تعذر فحص قرص الأمين: ' . $error;
        }
        if ($detail !== '') {
            return 'تعذر فحص قرص الأمين: ' . $detail;
        }

        return $fallback;
    }
}
