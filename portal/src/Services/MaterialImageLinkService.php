<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\Text;
use PDO;
use Throwable;

final class MaterialImageLinkService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listSources(): array
    {
        MaterialImageSyncService::ensureTable();
        $queueByFile = self::queueByFileName();
        $shaIndex = self::queueSha256Index();

        $sources = [];
        foreach (MaterialImageStorageService::listLocalFiles() as $file) {
            $fileName = (string) ($file['file_name'] ?? '');
            if ($fileName === '') {
                continue;
            }

            $queue = $queueByFile[$fileName] ?? null;
            if (self::isProbablyMaterialCopy($fileName, is_array($queue) ? $queue : null, $shaIndex)) {
                continue;
            }

            $amineGuid = trim((string) ($queue['amine_image_guid'] ?? ''));
            $sources[] = [
                'file_name' => $fileName,
                'local_path' => (string) ($file['local_path'] ?? ''),
                'local_sha256' => strtolower(trim((string) ($queue['local_sha256'] ?? ''))),
                'preview_url' => (string) ($file['preview_thumb_url'] ?? $file['preview_url'] ?? ''),
                'amine_image_guid' => $amineGuid,
                'is_synced' => (string) ($queue['sync_status'] ?? '') === 'synced'
                    && ($amineGuid !== '' || trim((string) ($queue['local_sha256'] ?? '')) !== ''),
            ];
        }

        return $sources;
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
    public static function listSourcesPage(
        int $page = 1,
        int $pageSize = 24,
        string $linkFilter = 'all',
        string $materialQuery = ''
    ): array
    {
        $all = self::listSources();
        $all = self::applyLinkHints($all);
        $materialQuery = trim($materialQuery);
        $materialContext = $materialQuery !== '' ? self::materialSearchContext($materialQuery) : [];

        if ($linkFilter === 'linked') {
            $all = array_values(array_filter($all, static fn (array $item): bool => !empty($item['link_hint'])));
        } elseif ($linkFilter === 'unlinked') {
            $all = array_values(array_filter($all, static fn (array $item): bool => empty($item['link_hint'])));
        }

        if ($materialQuery !== '') {
            $needle = Text::lower($materialQuery);
            $all = array_values(array_filter(
                $all,
                static fn (array $item): bool => self::matchesMaterialQuery($item, $needle, $materialContext)
            ));
        }

        $page = max(1, $page);
        $pageSize = max(6, min(60, $pageSize));
        $totalCount = count($all);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($all, $offset, $pageSize);
        $items = self::enrichLinkState($items);

        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => $totalCount,
            'has_more' => ($offset + count($items)) < $totalCount,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function applyLinkHints(array $items): array
    {
        $shaIndex = self::queueSha256Index();
        foreach ($items as $index => $item) {
            $fileName = (string) ($item['file_name'] ?? '');
            $sha = strtolower(trim((string) ($item['local_sha256'] ?? '')));
            $items[$index]['link_hint'] = false;
            if ($fileName === '' || $sha === '') {
                continue;
            }

            foreach ($shaIndex[$sha] ?? [] as $row) {
                $relatedName = (string) ($row['file_name'] ?? '');
                if ($relatedName === '' || strcasecmp($relatedName, $fileName) === 0) {
                    continue;
                }
                if (!self::isProbablyMaterialCopy($relatedName, $row, $shaIndex)) {
                    continue;
                }
                if (trim((string) ($row['amine_image_guid'] ?? '')) !== '') {
                    $items[$index]['link_hint'] = true;
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @return list<array{material_guid: string, name: string, code: string, image_guid: string, stored_file_name: string}>
     */
    private static function materialSearchContext(string $query): array
    {
        try {
            $response = MaterialImageStorageService::browseMaterials([
                'page' => 1,
                'page_size' => 48,
                'search' => $query,
                'has_image' => '',
                'local_status' => 'all',
            ]);
        } catch (Throwable) {
            return [];
        }

        if (!($response['ok'] ?? false)) {
            return [];
        }

        $context = [];
        foreach ($response['items'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $materialGuid = trim((string) ($row['material_guid'] ?? ''));
            $imageGuid = trim((string) ($row['image_guid'] ?? ''));
            if ($materialGuid === '') {
                continue;
            }

            $storedFileName = trim((string) ($row['stored_file_name'] ?? ''));
            if ($storedFileName === '' && $imageGuid !== '') {
                $storedFileName = self::storedFileNameForImageGuid($imageGuid);
            }

            $context[] = [
                'material_guid' => $materialGuid,
                'name' => trim((string) ($row['name'] ?? '')),
                'code' => trim((string) ($row['material_code'] ?? '')),
                'image_guid' => $imageGuid,
                'stored_file_name' => $storedFileName,
            ];
        }

        return $context;
    }

    /**
     * @param list<array{material_guid: string, name: string, code: string, image_guid: string, stored_file_name: string}> $context
     */
    private static function matchesMaterialQuery(array $item, string $needle, array $context): bool
    {
        foreach ($context as $material) {
            $name = Text::lower((string) ($material['name'] ?? ''));
            $code = Text::lower((string) ($material['code'] ?? ''));
            if (!str_contains($name, $needle) && !str_contains($code, $needle)) {
                continue;
            }

            $sourceName = strtolower((string) ($item['file_name'] ?? ''));
            $storedFileName = strtolower((string) ($material['stored_file_name'] ?? ''));
            if ($storedFileName !== '' && $storedFileName === $sourceName) {
                return true;
            }

            $sourceSha = strtolower(trim((string) ($item['local_sha256'] ?? '')));
            if ($sourceSha !== '' && $storedFileName !== '') {
                $queue = self::queueByFileName();
                $relatedSha = strtolower(trim((string) ($queue[$storedFileName]['local_sha256'] ?? '')));
                if ($relatedSha !== '' && $relatedSha === $sourceSha) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function enrichLinkState(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $queueByFile = self::queueByFileName();
        $shaIndex = self::queueSha256Index();
        $lookupCandidates = [];
        foreach ($items as $item) {
            $fileName = (string) ($item['file_name'] ?? '');
            if ($fileName === '') {
                continue;
            }

            $fingerprint = null;
            $sha = strtolower(trim((string) ($item['local_sha256'] ?? '')));
            if ($sha !== '') {
                $size = (int) ($queueByFile[$fileName]['local_size_bytes'] ?? 0);
                $fingerprint = ['size_bytes' => $size, 'sha256' => $sha];
            } else {
                $localPath = (string) ($item['local_path'] ?? '');
                if ($localPath !== '' && is_file($localPath)) {
                    $fingerprint = MaterialImageSyncService::fileFingerprint($localPath);
                }
            }

            $lookupCandidates[] = ['file_name' => $fileName, 'fingerprint' => $fingerprint];
        }

        $amineLookups = MaterialImageSyncService::lookupFilesOnAmine($lookupCandidates);
        $materialByImageGuid = [];

        foreach ($items as $index => $item) {
            $fileName = (string) ($item['file_name'] ?? '');
            $items[$index]['is_linked_to_material'] = false;
            $items[$index]['linked_material_guid'] = '';
            $items[$index]['linked_material_name'] = '';
            $items[$index]['linked_material_code'] = '';
            $items[$index]['linked_material_count'] = 0;
            $items[$index]['linked_materials'] = [];

            if ($fileName === '') {
                continue;
            }

            $sha = strtolower(trim((string) ($item['local_sha256'] ?? '')));
            if ($sha === '') {
                $localPath = (string) ($item['local_path'] ?? '');
                if ($localPath !== '' && is_file($localPath)) {
                    $fp = MaterialImageSyncService::fileFingerprint($localPath);
                    $sha = strtolower((string) ($fp['sha256'] ?? ''));
                }
            }

            $linkedMaterials = [];
            foreach ($shaIndex[$sha] ?? [] as $row) {
                $relatedName = (string) ($row['file_name'] ?? '');
                if ($relatedName === '' || strcasecmp($relatedName, $fileName) === 0) {
                    continue;
                }
                if (!self::isMaterialCopyFile($relatedName, $row, $shaIndex, $materialByImageGuid)) {
                    continue;
                }

                $relatedGuid = trim((string) ($row['amine_image_guid'] ?? ''));
                if ($relatedGuid === '') {
                    continue;
                }

                $material = $materialByImageGuid[$relatedGuid] ?? self::materialLinkedToImageGuid($relatedGuid);
                $materialByImageGuid[$relatedGuid] = $material;
                if ($material === null) {
                    continue;
                }

                $materialGuid = (string) ($material['material_guid'] ?? '');
                if ($materialGuid === '' || isset($linkedMaterials[$materialGuid])) {
                    continue;
                }

                $linkedMaterials[$materialGuid] = $material;
            }

            $queueGuid = trim((string) ($item['amine_image_guid'] ?? ''));
            $lookup = $amineLookups[self::lookupKey($fileName)] ?? null;
            $lookupGuid = trim((string) ($lookup['id'] ?? ''));
            if ($lookupGuid !== '') {
                $items[$index]['amine_image_guid'] = $lookupGuid;
            }

            $sourceQueueRow = $queueByFile[$fileName] ?? [];
            if (!self::isProbablyMaterialCopy($fileName, is_array($sourceQueueRow) ? $sourceQueueRow : null, $shaIndex)) {
                foreach (array_values(array_unique(array_filter([$queueGuid, $lookupGuid]))) as $imageGuid) {
                    $material = $materialByImageGuid[$imageGuid] ?? self::materialLinkedToImageGuid($imageGuid);
                    $materialByImageGuid[$imageGuid] = $material;
                    if ($material === null) {
                        continue;
                    }

                    $materialGuid = (string) ($material['material_guid'] ?? '');
                    if ($materialGuid === '' || isset($linkedMaterials[$materialGuid])) {
                        continue;
                    }

                    $linkedMaterials[$materialGuid] = $material;
                }
            }

            if ($linkedMaterials === []) {
                continue;
            }

            $linkedList = array_values($linkedMaterials);
            $first = $linkedList[0];
            $items[$index]['is_linked_to_material'] = true;
            $items[$index]['link_hint'] = true;
            $items[$index]['linked_material_guid'] = (string) ($first['material_guid'] ?? '');
            $items[$index]['linked_material_name'] = (string) ($first['name'] ?? '');
            $items[$index]['linked_material_code'] = (string) ($first['code'] ?? '');
            $items[$index]['linked_material_count'] = count($linkedList);
            $items[$index]['linked_materials'] = $linkedList;
        }

        return $items;
    }

    /** @return array{material_guid: string, name: string, code: string}|null */
    private static function materialLinkedToImageGuid(string $imageGuid): ?array
    {
        try {
            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));
            if (!($response['ok'] ?? false)) {
                return null;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $materialGuid = trim((string) ($data['materialGuid'] ?? $data['MaterialGuid'] ?? ''));
            if ($materialGuid === '') {
                return null;
            }

            $material = self::fetchMaterial($materialGuid);
            if ($material === null) {
                return [
                    'material_guid' => $materialGuid,
                    'name' => '',
                    'code' => '',
                ];
            }

            return [
                'material_guid' => $materialGuid,
                'name' => (string) ($material['name'] ?? ''),
                'code' => (string) ($material['material_code'] ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function storedFileNameForImageGuid(string $imageGuid): string
    {
        try {
            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));
            if (!($response['ok'] ?? false)) {
                return '';
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];

            return trim((string) ($data['storedFileName'] ?? $data['StoredFileName'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param list<string> $materialGuids
     * @return array{
     *   ok: bool,
     *   message: string,
     *   linked: int,
     *   failed: int,
     *   items: list<array<string, mixed>>
     * }
     */
    public static function assign(string $sourceFileName, array $materialGuids, ?string $uploadedByUserId = null): array
    {
        MaterialImageSyncService::ensureTable();
        MaterialImageStorageService::ensureSettings();

        $sourceFileName = basename(str_replace('\\', '/', trim($sourceFileName)));
        if ($sourceFileName === '' || str_contains($sourceFileName, '..')) {
            return self::assignError('اختر صورة أساسية صالحة.');
        }

        $materialGuids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $materialGuids
        ))));
        if ($materialGuids === []) {
            return self::assignError('اختر مادة واحدة على الأقل.');
        }
        if (count($materialGuids) > 50) {
            return self::assignError('الحد الأقصى 50 مادة في كل عملية ربط.');
        }

        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return self::assignError('الأمين غير متصل.');
        }

        $sourcePath = MaterialImageStorageService::resolveLocalPath($sourceFileName, false);
        if ($sourcePath === null || !is_file($sourcePath)) {
            return self::assignError('الصورة الأساسية غير موجودة على الموقع.');
        }

        $amineSourceGuid = self::resolveAmineSourceGuid($sourceFileName, $sourcePath);
        if ($amineSourceGuid !== '') {
            $amineResult = self::assignViaAmine(
                $sourcePath,
                $sourceFileName,
                $amineSourceGuid,
                $materialGuids,
                $uploadedByUserId
            );
            if (($amineResult['linked'] ?? 0) > 0) {
                return $amineResult;
            }

            $uploadResult = self::assignViaUpload($sourcePath, $sourceFileName, $materialGuids, $uploadedByUserId);
            if (($uploadResult['linked'] ?? 0) > 0) {
                return $uploadResult;
            }

            return self::combineAssignFailures($amineResult, $uploadResult);
        }

        return self::assignViaUpload($sourcePath, $sourceFileName, $materialGuids, $uploadedByUserId);
    }

    private static function resolveAmineSourceGuid(string $fileName, string $sourcePath): string
    {
        $queueRow = self::queueRowByFileName($fileName);
        $queueGuid = trim((string) ($queueRow['amine_image_guid'] ?? ''));
        if ($queueGuid !== '' && self::imageGuidExistsOnAmine($queueGuid)) {
            return $queueGuid;
        }

        $fingerprint = MaterialImageSyncService::fileFingerprint($sourcePath);
        $lookup = MaterialImageSyncService::lookupFilesOnAmine([
            ['file_name' => $fileName, 'fingerprint' => $fingerprint],
        ]);
        $found = $lookup[self::lookupKey($fileName)] ?? null;
        $lookupGuid = trim((string) ($found['id'] ?? ''));
        if ($lookupGuid !== '' && self::imageGuidExistsOnAmine($lookupGuid)) {
            return $lookupGuid;
        }

        return '';
    }

    private static function imageGuidExistsOnAmine(string $imageGuid): bool
    {
        try {
            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));

            return (bool) ($response['ok'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $materialGuids
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>}
     */
    private static function assignViaAmine(
        string $sourcePath,
        string $sourceFileName,
        string $amineSourceGuid,
        array $materialGuids,
        ?string $uploadedByUserId
    ): array {
        try {
            $response = ApiClient::postJson(
                '/api/material-images/' . rawurlencode($amineSourceGuid) . '/assign-to-materials',
                ['materialGuids' => $materialGuids],
                120
            );
        } catch (Throwable $exception) {
            return self::assignError('فشل الاتصال بـ API الأمين: ' . $exception->getMessage());
        }

        if (!($response['ok'] ?? false)) {
            $message = (string) ($response['data']['message'] ?? $response['error'] ?? 'فشل توليد نسخ الصور على الأمين.');

            return self::assignError($message);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : (is_array($data['Items'] ?? null) ? $data['Items'] : []);
        if ($items === []) {
            return self::assignError('لم يُرجع API الأمين أي نتائج ربط للصورة «' . $sourceFileName . '».');
        }

        $linked = 0;
        $failed = 0;
        $results = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $materialGuid = trim((string) ($item['materialGuid'] ?? $item['MaterialGuid'] ?? ''));
            $materialName = trim((string) ($item['materialName'] ?? $item['MaterialName'] ?? ''));
            $materialCode = trim((string) ($item['materialCode'] ?? $item['MaterialCode'] ?? ''));
            $imageGuid = trim((string) ($item['imageGuid'] ?? $item['ImageGuid'] ?? ''));
            $storedFileName = trim((string) ($item['storedFileName'] ?? $item['StoredFileName'] ?? ''));
            if ($materialGuid === '' || $imageGuid === '' || $storedFileName === '') {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => $materialName,
                    'material_code' => $materialCode,
                    'ok' => false,
                    'message' => 'استجابة API ناقصة لإحدى المواد.',
                ];
                continue;
            }

            $copy = MaterialImageStorageService::copyLocalFromSource($sourcePath, $storedFileName);
            if (!($copy['ok'] ?? false)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => $materialName,
                    'material_code' => $materialCode,
                    'ok' => false,
                    'message' => (string) ($copy['message'] ?? 'فشل النسخ المحلي.'),
                ];
                continue;
            }

            $localPath = MaterialImageStorageService::settings()['images_dir']
                . DIRECTORY_SEPARATOR
                . (string) ($copy['file_name'] ?? $storedFileName);
            MaterialImageSyncService::recordAssignedCopy(
                (string) ($copy['file_name'] ?? $storedFileName),
                $localPath,
                $imageGuid,
                $uploadedByUserId,
                $sourceFileName
            );

            $linked++;
            $results[] = [
                'material_guid' => $materialGuid,
                'material_name' => $materialName,
                'material_code' => $materialCode,
                'image_guid' => $imageGuid,
                'file_name' => (string) ($copy['file_name'] ?? $storedFileName),
                'ok' => true,
                'message' => 'تم الربط.',
            ];
        }

        return [
            'ok' => $linked > 0,
            'message' => $linked > 0
                ? ('تم ربط ' . $linked . ' مادة من الصورة «' . $sourceFileName . '».')
                : self::formatAssignFailureMessage($results, $sourceFileName),
            'linked' => $linked,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    /**
     * @param list<string> $materialGuids
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>}
     */
    private static function assignViaUpload(
        string $sourcePath,
        string $sourceFileName,
        array $materialGuids,
        ?string $uploadedByUserId
    ): array {
        $linked = 0;
        $failed = 0;
        $results = [];
        $extension = strtolower(pathinfo($sourceFileName, PATHINFO_EXTENSION));

        foreach ($materialGuids as $materialGuid) {
            $material = self::fetchMaterial($materialGuid);
            if ($material === null) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'ok' => false,
                    'message' => 'المادة غير موجودة.',
                ];
                continue;
            }

            $targetFileName = self::buildTargetFileName($material, $extension !== '' ? '.' . $extension : '.jpg');
            $copy = MaterialImageStorageService::copyLocalFromSource($sourcePath, $targetFileName);
            if (!($copy['ok'] ?? false)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'ok' => false,
                    'message' => (string) ($copy['message'] ?? 'فشل النسخ المحلي.'),
                ];
                continue;
            }

            $fileName = (string) ($copy['file_name'] ?? $targetFileName);
            $localPath = MaterialImageStorageService::settings()['images_dir'] . DIRECTORY_SEPARATOR . $fileName;

            try {
                $response = ApiClient::postMultipart('/api/material-images', [
                    'MaterialGuid' => $materialGuid,
                ], [[
                    'name' => 'Files',
                    'path' => $localPath,
                    'mime' => MaterialImageStorageService::mimeForPath($localPath),
                    'filename' => $fileName,
                ]]);
            } catch (Throwable $exception) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
                continue;
            }

            if (!($response['ok'] ?? false)) {
                $failed++;
                $status = (int) ($response['status'] ?? 0);
                $apiMessage = (string) ($response['error'] ?? ($response['data']['message'] ?? 'فشل الرفع للأمين.'));
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'ok' => false,
                    'message' => $status > 0 ? ('[' . $status . '] ' . $apiMessage) : $apiMessage,
                ];
                continue;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $imageGuid = trim((string) ($data['id'] ?? $data['Id'] ?? ''));
            if ($imageGuid !== '') {
                MaterialImageSyncService::recordAssignedCopy(
                    $fileName,
                    $localPath,
                    $imageGuid,
                    $uploadedByUserId,
                    $sourceFileName
                );
            }

            $linked++;
            $results[] = [
                'material_guid' => $materialGuid,
                'material_name' => (string) ($material['name'] ?? ''),
                'material_code' => (string) ($material['material_code'] ?? ''),
                'image_guid' => $imageGuid,
                'file_name' => $fileName,
                'ok' => true,
                'message' => 'تم الرفع والربط.',
            ];
        }

        return [
            'ok' => $linked > 0,
            'message' => $linked > 0
                ? ('تم رفع وربط ' . $linked . ' مادة من الصورة «' . $sourceFileName . '».')
                : self::formatAssignFailureMessage($results, $sourceFileName),
            'linked' => $linked,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    /** @return array{name: string, material_code: string}|null */
    private static function fetchMaterial(string $materialGuid): ?array
    {
        try {
            $response = ApiClient::get('/api/materials/' . rawurlencode($materialGuid));
            if (!($response['ok'] ?? false)) {
                return null;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : null;
            if ($data === null) {
                return null;
            }

            return [
                'name' => trim((string) ($data['name'] ?? $data['Name'] ?? '')),
                'material_code' => trim((string) ($data['materialCode'] ?? $data['MaterialCode'] ?? '')),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{name: string, material_code: string} $material */
    private static function buildTargetFileName(array $material, string $extension): string
    {
        $code = preg_replace('/[^\w\-. \x{0600}-\x{06FF}]+/u', '_', (string) ($material['material_code'] ?? '')) ?? '';
        $code = trim($code);
        if ($code === '') {
            $code = 'mat_' . substr(sha1((string) ($material['name'] ?? 'material')), 0, 8);
        }

        return $code . $extension;
    }

    /** @return array<string, array<string, mixed>> */
    private static function queueByFileName(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT
                file_name,
                amine_image_guid::text AS amine_image_guid,
                sync_status::text AS sync_status,
                local_sha256,
                local_size_bytes,
                assigned_from_file_name
             FROM material_image_sync_queue"
        );

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) ($row['file_name'] ?? '')] = $row;
        }

        return $map;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function queueSha256Index(): array
    {
        $index = [];
        foreach (self::queueByFileName() as $fileName => $row) {
            $sha = strtolower(trim((string) ($row['local_sha256'] ?? '')));
            if ($sha === '' || $fileName === '') {
                continue;
            }
            $index[$sha][] = array_merge($row, ['file_name' => $fileName]);
        }

        return $index;
    }

    /** @return array<string, mixed>|null */
    private static function queueRowByFileName(string $fileName): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                file_name,
                amine_image_guid::text AS amine_image_guid,
                sync_status::text AS sync_status,
                local_sha256,
                local_size_bytes,
                assigned_from_file_name
             FROM material_image_sync_queue
             WHERE file_name = :file_name
             LIMIT 1'
        );
        $stmt->execute(['file_name' => $fileName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param list<array<string, mixed>> $items */
    private static function formatAssignFailureMessage(array $items, string $sourceFileName): string
    {
        foreach ($items as $item) {
            if (!is_array($item) || ($item['ok'] ?? false)) {
                continue;
            }
            $label = trim((string) (($item['material_code'] ?? '') . ' ' . ($item['material_name'] ?? '')));
            $detail = trim((string) ($item['message'] ?? ''));
            if ($detail !== '') {
                return 'لم يُربط أي مادة من «' . $sourceFileName . '». ' . ($label !== '' ? ($label . ': ') : '') . $detail;
            }
        }

        return 'لم يُربط أي مادة من «' . $sourceFileName . '». تحقق من صلاحيات حساب API (materials.update) واتصال الأمين.';
    }

    /** @param array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} $amineResult */
    /** @param array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} $uploadResult */
    /** @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} */
    private static function combineAssignFailures(array $amineResult, array $uploadResult): array
    {
        $items = array_merge($uploadResult['items'] ?? [], $amineResult['items'] ?? []);
        $message = self::formatAssignFailureMessage($items, '');
        if ($message === 'لم يُربط أي مادة من «». تحقق من صلاحيات حساب API (materials.update) واتصال الأمين.') {
            $message = trim((string) ($uploadResult['message'] ?? ''));
            if ($message === '' || str_starts_with($message, 'تم ')) {
                $message = (string) ($amineResult['message'] ?? 'لم يُربط أي مادة.');
            }
        }

        return [
            'ok' => false,
            'message' => $message,
            'linked' => 0,
            'failed' => max((int) ($amineResult['failed'] ?? 0), (int) ($uploadResult['failed'] ?? 0)),
            'items' => $items,
        ];
    }

    /** @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} */
    private static function assignError(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'linked' => 0,
            'failed' => 0,
            'items' => [],
        ];
    }

    private static function lookupKey(string $fileName): string
    {
        return strtolower($fileName);
    }

    /** @param array<string, mixed>|null $queueRow */
    /** @param array<string, list<array<string, mixed>>> $shaIndex */
    private static function isProbablyMaterialCopy(?string $fileName, ?array $queueRow, array $shaIndex): bool
    {
        $fileName = trim((string) $fileName);
        if ($fileName === '') {
            return false;
        }

        $queueRow ??= [];
        if (trim((string) ($queueRow['assigned_from_file_name'] ?? '')) !== '') {
            return true;
        }

        $sha = strtolower(trim((string) ($queueRow['local_sha256'] ?? '')));
        if ($sha === '') {
            return false;
        }

        $stem = self::fileStem($fileName);
        if ($stem === '') {
            return false;
        }

        foreach ($shaIndex[$sha] ?? [] as $row) {
            $siblingName = (string) ($row['file_name'] ?? '');
            if ($siblingName === '' || strcasecmp($siblingName, $fileName) === 0) {
                continue;
            }

            if (self::looksLikeSourceImageName($siblingName, $stem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $queueRow
     * @param array<string, list<array<string, mixed>>> $shaIndex
     * @param array<string, array{material_guid: string, name: string, code: string}|null> $materialByImageGuid
     */
    private static function isMaterialCopyFile(
        string $fileName,
        array $queueRow,
        array $shaIndex,
        array &$materialByImageGuid
    ): bool {
        if (self::isProbablyMaterialCopy($fileName, $queueRow, $shaIndex)) {
            return true;
        }

        $guid = trim((string) ($queueRow['amine_image_guid'] ?? ''));
        if ($guid === '') {
            return false;
        }

        $material = $materialByImageGuid[$guid] ?? self::materialLinkedToImageGuid($guid);
        $materialByImageGuid[$guid] = $material;
        if ($material === null) {
            return false;
        }

        return self::fileStemMatchesMaterialCode($fileName, (string) ($material['code'] ?? ''));
    }

    private static function fileStem(string $fileName): string
    {
        return trim((string) pathinfo($fileName, PATHINFO_FILENAME));
    }

    private static function fileStemMatchesMaterialCode(string $fileName, string $materialCode): bool
    {
        $stem = self::fileStem($fileName);
        $code = trim($materialCode);

        return $stem !== '' && $code !== '' && strcasecmp($stem, $code) === 0;
    }

    private static function looksLikeSourceImageName(string $fileName, string $copyStem): bool
    {
        $stem = self::fileStem($fileName);
        if ($stem === '' || strcasecmp($stem, $copyStem) === 0) {
            return false;
        }

        $copyStemLower = Text::lower($copyStem);
        $stemLower = Text::lower($stem);

        return strlen($stemLower) > strlen($copyStemLower) + 2
            || str_contains($stemLower, 'wa')
            || str_contains($stemLower, 'img')
            || str_contains($stemLower, '_')
            || str_contains($stemLower, '.');
    }
}
