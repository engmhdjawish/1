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
        $queueByFile = self::syncedQueueByFileName();

        $sources = [];
        foreach (MaterialImageStorageService::listLocalFiles() as $file) {
            $fileName = (string) ($file['file_name'] ?? '');
            if ($fileName === '') {
                continue;
            }

            $queue = $queueByFile[$fileName] ?? null;
            $sources[] = [
                'file_name' => $fileName,
                'local_path' => (string) ($file['local_path'] ?? ''),
                'preview_url' => (string) ($file['preview_thumb_url'] ?? $file['preview_url'] ?? ''),
                'amine_image_guid' => (string) ($queue['amine_image_guid'] ?? ''),
                'is_synced' => (string) ($queue['sync_status'] ?? '') === 'synced'
                    && trim((string) ($queue['amine_image_guid'] ?? '')) !== '',
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
        $all = self::enrichLinkState($all);
        $materialQuery = trim($materialQuery);
        if ($linkFilter === 'linked') {
            $all = array_values(array_filter($all, static fn (array $item): bool => !empty($item['is_linked_to_material'])));
        } elseif ($linkFilter === 'unlinked') {
            $all = array_values(array_filter($all, static fn (array $item): bool => empty($item['is_linked_to_material'])));
        }
        if ($materialQuery !== '') {
            $needle = Text::lower($materialQuery);
            $all = array_values(array_filter($all, static function (array $item) use ($needle): bool {
                $name = Text::lower((string) ($item['linked_material_name'] ?? ''));
                $code = Text::lower((string) ($item['linked_material_code'] ?? ''));

                return str_contains($name, $needle) || str_contains($code, $needle);
            }));
        }
        $page = max(1, $page);
        $pageSize = max(6, min(60, $pageSize));
        $totalCount = count($all);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($all, $offset, $pageSize);

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
    private static function enrichLinkState(array $items): array
    {
        foreach ($items as $index => $item) {
            $amineGuid = trim((string) ($item['amine_image_guid'] ?? ''));
            $items[$index]['is_linked_to_material'] = false;
            $items[$index]['linked_material_guid'] = '';
            $items[$index]['linked_material_name'] = '';
            $items[$index]['linked_material_code'] = '';
            if ($amineGuid === '') {
                continue;
            }

            try {
                $response = ApiClient::get('/api/material-images/' . rawurlencode($amineGuid));
                if (!($response['ok'] ?? false)) {
                    continue;
                }
                $data = is_array($response['data'] ?? null) ? $response['data'] : [];
                $materialGuid = trim((string) ($data['materialGuid'] ?? $data['MaterialGuid'] ?? ''));
                if ($materialGuid !== '') {
                    $items[$index]['is_linked_to_material'] = true;
                    $items[$index]['linked_material_guid'] = $materialGuid;
                    try {
                        $materialResponse = ApiClient::get('/api/materials/' . rawurlencode($materialGuid));
                        if ($materialResponse['ok'] ?? false) {
                            $materialData = is_array($materialResponse['data'] ?? null) ? $materialResponse['data'] : [];
                            $items[$index]['linked_material_name'] = trim((string) ($materialData['name'] ?? $materialData['Name'] ?? ''));
                            $items[$index]['linked_material_code'] = trim((string) ($materialData['materialCode'] ?? $materialData['MaterialCode'] ?? ''));
                        }
                    } catch (Throwable) {
                        // ignore material metadata fetch failure
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $items;
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

        $settings = MaterialImageStorageService::settings();
        $sourcePath = MaterialImageStorageService::resolveLocalPath($sourceFileName, false);
        if ($sourcePath === null || !is_file($sourcePath)) {
            return self::assignError('الصورة الأساسية غير موجودة على الموقع.');
        }

        $queueRow = self::queueRowByFileName($sourceFileName);
        $amineSourceGuid = trim((string) ($queueRow['amine_image_guid'] ?? ''));

        if ($amineSourceGuid !== '') {
            return self::assignViaAmine($sourcePath, $sourceFileName, $amineSourceGuid, $materialGuids, $uploadedByUserId);
        }

        return self::assignViaUpload($sourcePath, $sourceFileName, $materialGuids, $uploadedByUserId);
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
                $uploadedByUserId
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
                : 'لم يُربط أي مادة.',
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

            $targetFileName = self::buildTargetFileName($material, $extension !== '' ? $extension : '.jpg');
            $copy = MaterialImageStorageService::copyLocalFromSource($sourcePath, $targetFileName);
            if (!($copy['ok'] ?? false)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
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
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
                continue;
            }

            if (!($response['ok'] ?? false)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'ok' => false,
                    'message' => (string) ($response['error'] ?? ($response['data']['message'] ?? 'فشل الرفع للأمين.')),
                ];
                continue;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $imageGuid = trim((string) ($data['id'] ?? $data['Id'] ?? ''));
            if ($imageGuid !== '') {
                MaterialImageSyncService::recordAssignedCopy($fileName, $localPath, $imageGuid, $uploadedByUserId);
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
                : 'لم يُربط أي مادة.',
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
    private static function syncedQueueByFileName(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT
                file_name,
                amine_image_guid::text AS amine_image_guid,
                sync_status::text AS sync_status
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
                sync_status::text AS sync_status
             FROM material_image_sync_queue
             WHERE file_name = :file_name
             LIMIT 1'
        );
        $stmt->execute(['file_name' => $fileName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
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
}
