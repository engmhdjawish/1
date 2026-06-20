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

        $sources = [];
        foreach (MaterialImageStorageService::listLocalFiles() as $file) {
            $fileName = (string) ($file['file_name'] ?? '');
            if ($fileName === '') {
                continue;
            }

            $queue = $queueByFile[$fileName] ?? null;
            $amineGuid = trim((string) ($queue['amine_image_guid'] ?? ''));
            $sources[] = [
                'file_name' => $fileName,
                'local_path' => (string) ($file['local_path'] ?? ''),
                'local_sha256' => strtolower(trim((string) ($queue['local_sha256'] ?? ''))),
                'preview_url' => (string) ($file['preview_thumb_url'] ?? $file['preview_url'] ?? ''),
                'amine_image_guid' => $amineGuid,
                'is_synced' => (string) ($queue['sync_status'] ?? '') === 'synced'
                    && ($amineGuid !== '' || trim((string) ($queue['local_sha256'] ?? '')) !== ''),
                'is_linked_to_material' => false,
                'link_hint' => false,
                'linked_material_guid' => '',
                'linked_material_name' => '',
                'linked_material_code' => '',
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
        $materialQuery = trim($materialQuery);
        $needsFullLinkScan = $linkFilter !== 'all' || $materialQuery !== '';
        if ($needsFullLinkScan) {
            $all = self::applyAmineLinkInfo($all);
        }

        if ($linkFilter === 'linked') {
            $all = array_values(array_filter($all, static fn (array $item): bool => !empty($item['is_linked_to_material'])));
        } elseif ($linkFilter === 'unlinked') {
            $all = array_values(array_filter($all, static fn (array $item): bool => empty($item['is_linked_to_material'])));
        }

        if ($materialQuery !== '') {
            $needle = Text::lower($materialQuery);
            $all = array_values(array_filter(
                $all,
                static fn (array $item): bool => self::matchesMaterialQuery($item, $needle)
            ));
        }

        $page = max(1, $page);
        $pageSize = max(6, min(60, $pageSize));
        $totalCount = count($all);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($all, $offset, $pageSize);
        if (!$needsFullLinkScan) {
            $items = self::applyAmineLinkInfo($items);
        }

        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => $totalCount,
            'has_more' => ($offset + count($items)) < $totalCount,
        ];
    }

    /**
     * Resolve bm000 + PictureGUID links from Amine API (one material per image file).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function applyAmineLinkInfo(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $queueByFile = self::queueByFileName();
        foreach (array_chunk($items, MaterialImageSyncService::BATCH_LOOKUP_SIZE, true) as $chunk) {
            $candidates = [];
            foreach ($chunk as $item) {
                $fileName = (string) ($item['file_name'] ?? '');
                if ($fileName === '') {
                    continue;
                }

                $fingerprint = null;
                $sha = strtolower(trim((string) ($item['local_sha256'] ?? '')));
                if ($sha !== '') {
                    $fingerprint = [
                        'size_bytes' => (int) ($queueByFile[$fileName]['local_size_bytes'] ?? 0),
                        'sha256' => $sha,
                    ];
                } else {
                    $localPath = (string) ($item['local_path'] ?? '');
                    if ($localPath !== '' && is_file($localPath)) {
                        $fingerprint = MaterialImageSyncService::fileFingerprint($localPath);
                    }
                }

                $candidates[] = ['file_name' => $fileName, 'fingerprint' => $fingerprint];
            }

            $lookups = MaterialImageSyncService::lookupFilesOnAmine($candidates);
            foreach ($chunk as $index => $item) {
                $fileName = (string) ($item['file_name'] ?? '');
                $lookup = $lookups[self::lookupKey($fileName)] ?? null;
                $imageGuid = trim((string) ($lookup['id'] ?? ''));
                if ($imageGuid === '') {
                    $imageGuid = trim((string) ($item['amine_image_guid'] ?? ''));
                }

                $materialGuid = trim((string) ($lookup['materialGuid'] ?? ''));
                $materialName = trim((string) ($lookup['materialName'] ?? ''));
                $materialCode = trim((string) ($lookup['materialCode'] ?? ''));

                if ($materialGuid === '' && $imageGuid !== '') {
                    $fallback = self::materialLinkedToImageGuid($imageGuid);
                    if ($fallback !== null) {
                        $materialGuid = (string) ($fallback['material_guid'] ?? '');
                        $materialName = (string) ($fallback['name'] ?? '');
                        $materialCode = (string) ($fallback['code'] ?? '');
                    }
                }

                $items[$index]['amine_image_guid'] = $imageGuid;
                $items[$index]['is_linked_to_material'] = $materialGuid !== '';
                $items[$index]['link_hint'] = $materialGuid !== '';
                $items[$index]['linked_material_guid'] = $materialGuid;
                $items[$index]['linked_material_name'] = $materialName;
                $items[$index]['linked_material_code'] = $materialCode;
                $items[$index]['linked_material_count'] = $materialGuid !== '' ? 1 : 0;
                $items[$index]['linked_materials'] = $materialGuid !== ''
                    ? [[
                        'material_guid' => $materialGuid,
                        'name' => $materialName,
                        'code' => $materialCode,
                    ]]
                    : [];
            }
        }

        return $items;
    }

    private static function matchesMaterialQuery(array $item, string $needle): bool
    {
        $name = Text::lower((string) ($item['linked_material_name'] ?? ''));
        $code = Text::lower((string) ($item['linked_material_code'] ?? ''));

        return str_contains($name, $needle) || str_contains($code, $needle);
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
                'message' => 'تم الرفع والربط (bm000 + PictureGUID).',
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
}
