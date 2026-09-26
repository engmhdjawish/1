<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;
use Throwable;

final class MaterialImageLinkService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listSources(): array
    {
        $page = self::fetchLinkFilesPage(1, 100, 'unlinked', '');

        return $page['items'] ?? [];
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
        return self::fetchLinkFilesPage($page, $pageSize, $linkFilter, trim($materialQuery));
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
    private static function fetchLinkFilesPage(
        int $page,
        int $pageSize,
        string $linkFilter,
        string $materialQuery
    ): array {
        $page = max(1, $page);
        $pageSize = max(6, min(60, $pageSize));
        $empty = [
            'items' => [],
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => 0,
            'has_more' => false,
        ];

        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return $empty;
        }

        $query = [
            'page' => $page,
            'pageSize' => $pageSize,
        ];
        if ($linkFilter === 'linked') {
            $query['linked'] = 'true';
        } elseif ($linkFilter === 'unlinked') {
            $query['linked'] = 'false';
        }
        if ($materialQuery !== '') {
            $query['materialSearch'] = $materialQuery;
        }

        try {
            $response = ApiClient::get('/api/material-images/link-files', $query);
        } catch (Throwable $exception) {
            return $empty;
        }

        if (!($response['ok'] ?? false)) {
            return $empty;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = self::mapLinkFileRow($row);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
        $responsePage = max(1, (int) ($data['page'] ?? $data['Page'] ?? $page));
        $responsePageSize = max(1, (int) ($data['pageSize'] ?? $data['PageSize'] ?? $pageSize));

        return [
            'items' => $items,
            'page' => $responsePage,
            'page_size' => $responsePageSize,
            'total_count' => $totalCount,
            'has_more' => ($responsePage * $responsePageSize) < $totalCount,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function mapLinkFileRow(array $row): ?array
    {
        $fileName = trim((string) ($row['fileName'] ?? $row['FileName'] ?? ''));
        if ($fileName === '') {
            return null;
        }

        $imageGuid = self::extractImageGuidFromRow($row);
        if ($imageGuid === '' && $fileName !== '') {
            $imageGuid = self::lookupAmineImageGuidByFileName($fileName);
        }
        $materialGuid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? ''));
        $materialName = trim((string) ($row['materialName'] ?? $row['MaterialName'] ?? ''));
        $materialCode = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
        $isLinked = (bool) ($row['isLinkedToMaterial'] ?? $row['IsLinkedToMaterial'] ?? ($materialGuid !== ''));

        $sitePreview = MaterialImageStorageService::resolveSitePreviewUrls($imageGuid, $fileName);
        $previewUrl = (string) ($sitePreview['preview_url'] ?? '');
        $previewFullUrl = (string) ($sitePreview['preview_full_url'] ?? '');
        $hasLocal = (bool) ($sitePreview['has_local'] ?? false);
        $localPath = (string) ($sitePreview['local_path'] ?? '');

        return [
            'file_name' => $fileName,
            'local_path' => $hasLocal ? (string) $localPath : '',
            'preview_url' => $previewUrl,
            'preview_full_url' => $previewFullUrl,
            'amine_image_guid' => $imageGuid,
            'is_synced' => $imageGuid !== '',
            'is_linked_to_material' => $isLinked,
            'link_hint' => $isLinked,
            'linked_material_guid' => $materialGuid,
            'linked_material_name' => $materialName,
            'linked_material_code' => $materialCode,
            'linked_material_count' => $isLinked ? 1 : 0,
            'linked_materials' => $isLinked
                ? [[
                    'material_guid' => $materialGuid,
                    'name' => $materialName,
                    'code' => $materialCode,
                ]]
                : [],
        ];
    }

    /**
     * @return array{ok: bool, message: string, items: list<array<string, mixed>>}
     */
    public static function searchMaterials(string $query, int $pageSize = 40): array
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            return ['ok' => true, 'message' => '', 'items' => []];
        }

        $pageSize = max(20, min(60, $pageSize));

        try {
            $result = MaterialImageStorageService::browseMaterials([
                'page' => 1,
                'page_size' => $pageSize,
                'search' => $query,
                'has_image' => '',
                'local_status' => 'all',
            ]);

            if (!($result['ok'] ?? false)) {
                return ['ok' => false, 'message' => (string) ($result['message'] ?? 'تعذر البحث.'), 'items' => []];
            }

            return ['ok' => true, 'message' => '', 'items' => $result['items'] ?? []];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'items' => []];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function unlinkImage(string $imageGuid, ?string $materialGuid = null): array
    {
        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return ['ok' => false, 'message' => 'معرف الصورة مطلوب.'];
        }

        $body = ['imageGuid' => $imageGuid];
        $materialGuid = trim((string) $materialGuid);
        if ($materialGuid !== '') {
            $body['materialGuid'] = $materialGuid;
        }

        try {
            $response = ApiClient::postJson('/api/material-images/unlink', $body);
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $ok = (bool) ($response['ok'] ?? false) || (int) ($response['status'] ?? 0) === 204;

        return [
            'ok' => $ok,
            'message' => $ok ? 'تم فك ربط الصورة بالمادة.' : (string) ($response['error'] ?? ($response['data']['message'] ?? 'فشل فك الربط.')),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteImage(string $imageGuid, string $fileName = '', ?string $materialGuid = null): array
    {
        $imageGuid = trim($imageGuid);
        $fileName = basename(str_replace('\\', '/', trim($fileName)));

        $imageGuid = self::resolveAmineImageGuidForDelete($imageGuid, $fileName);
        if ($imageGuid === '') {
            return ['ok' => false, 'message' => 'تعذر تحديد معرف الصورة (GUID) في bm000.'];
        }

        $amineDelete = self::deleteOnAmine($imageGuid, $fileName);
        if (!($amineDelete['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($amineDelete['message'] ?? 'فشل حذف الصورة من الأمين (bm000).'),
            ];
        }

        MaterialImageSyncService::purgeImageRecords($imageGuid, $fileName);

        return [
            'ok' => true,
            'message' => 'تم حذف الصورة من bm000 والسيرفر والموقع، وفك ارتباطها بالمادة، وإزالة سجلات المزامنة.',
        ];
    }

    /**
     * @return array{ok: bool, message: string, deleted: int, failed: int, items: list<array<string, mixed>>}
     */
    public static function deleteAllUnlinked(int $maxImages = 200): array
    {
        $maxImages = max(1, min(500, $maxImages));
        $deleted = 0;
        $failed = 0;
        $items = [];

        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'الأمين غير متصل.',
                'deleted' => 0,
                'failed' => 0,
                'items' => [],
            ];
        }

        while ($deleted + $failed < $maxImages) {
            $page = self::listSourcesPage(1, min(30, $maxImages - $deleted - $failed), 'unlinked', '');
            $rows = $page['items'] ?? [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($deleted + $failed >= $maxImages) {
                    break 2;
                }

                $imageGuid = trim((string) ($row['amine_image_guid'] ?? ''));
                $fileName = trim((string) ($row['file_name'] ?? ''));
                if ($imageGuid === '') {
                    $failed++;
                    $items[] = [
                        'file_name' => $fileName,
                        'ok' => false,
                        'message' => 'لا يوجد GUID للصورة.',
                    ];
                    continue;
                }

                $result = self::deleteImage($imageGuid, $fileName);
                if ($result['ok'] ?? false) {
                    $deleted++;
                    $items[] = [
                        'image_guid' => $imageGuid,
                        'file_name' => $fileName,
                        'ok' => true,
                    ];
                } else {
                    $failed++;
                    $items[] = [
                        'image_guid' => $imageGuid,
                        'file_name' => $fileName,
                        'ok' => false,
                        'message' => (string) ($result['message'] ?? 'فشل الحذف.'),
                    ];
                }
            }

            if (count($rows) < 30) {
                break;
            }
        }

        if ($deleted === 0 && $failed === 0) {
            return [
                'ok' => true,
                'message' => 'لا توجد صور غير مرتبطة للحذف.',
                'deleted' => 0,
                'failed' => 0,
                'items' => [],
            ];
        }

        return [
            'ok' => $deleted > 0,
            'message' => $deleted > 0
                ? ('تم حذف ' . $deleted . ' صورة غير مرتبطة.' . ($failed > 0 ? (' فشل ' . $failed . '.') : ''))
                : 'لم يُحذف أي صورة غير مرتبطة.',
            'deleted' => $deleted,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   done: bool,
     *   deleted: int,
     *   remaining: int,
     *   message: string,
     *   file_name?: string,
     *   image_guid?: string
     * }
     */
    public static function deleteNextUnlinked(): array
    {
        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return [
                'ok' => false,
                'done' => true,
                'deleted' => 0,
                'remaining' => 0,
                'message' => 'الأمين غير متصل.',
            ];
        }

        $page = self::listSourcesPage(1, 1, 'unlinked', '');
        $rows = is_array($page['items'] ?? null) ? $page['items'] : [];
        $remainingBefore = max(0, (int) ($page['total_count'] ?? 0));

        if ($rows === []) {
            return [
                'ok' => true,
                'done' => true,
                'deleted' => 0,
                'remaining' => 0,
                'message' => 'لا توجد صور غير مرتبطة للحذف.',
            ];
        }

        $row = $rows[0];

        return self::deleteUnlinkedRow($row, $remainingBefore);
    }

    /**
     * @return array{
     *   ok: bool,
     *   done: bool,
     *   deleted: int,
     *   remaining: int,
     *   message: string,
     *   file_name?: string,
     *   image_guid?: string
     * }
     */
    public static function deleteUnlinkedItem(string $imageGuid, string $fileName): array
    {
        if (!(PortalSettingsService::apiHealth()['ok'] ?? false)) {
            return [
                'ok' => false,
                'done' => true,
                'deleted' => 0,
                'remaining' => 0,
                'message' => 'الأمين غير متصل.',
            ];
        }

        return self::deleteUnlinkedRow([
            'amine_image_guid' => $imageGuid,
            'file_name' => $fileName,
        ], 1);
    }

    /** @param array<string, mixed> $row */
    private static function deleteUnlinkedRow(array $row, int $remainingBefore): array
    {
        $imageGuid = trim((string) ($row['amine_image_guid'] ?? ''));
        $fileName = trim((string) ($row['file_name'] ?? ''));
        if ($imageGuid === '') {
            return [
                'ok' => false,
                'done' => $remainingBefore <= 1,
                'deleted' => 0,
                'remaining' => max(0, $remainingBefore - 1),
                'message' => 'لا يوجد GUID للصورة «' . $fileName . '».',
                'file_name' => $fileName,
            ];
        }

        $result = self::deleteImage($imageGuid, $fileName);
        $remaining = max(0, $remainingBefore - 1);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'done' => $remaining === 0,
            'deleted' => ($result['ok'] ?? false) ? 1 : 0,
            'remaining' => $remaining,
            'message' => (string) ($result['message'] ?? ''),
            'file_name' => $fileName,
            'image_guid' => $imageGuid,
        ];
    }

    /**
     * @return array{ok: bool, message: string, image_guid?: string}
     */
    private static function deleteOnAmine(string $imageGuid, string $fileName): array
    {
        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return ['ok' => false, 'message' => 'معرف الصورة (GUID) مطلوب.'];
        }

        $result = self::attemptAmineDeleteByGuid($imageGuid);
        if (($result['ok'] ?? false) || self::isAmineDeleteAlreadyApplied($result, $imageGuid, $fileName)) {
            return ['ok' => true, 'message' => '', 'image_guid' => $imageGuid];
        }

        return [
            'ok' => false,
            'message' => (string) ($result['message'] ?? 'فشل حذف الصورة من bm000 بالمعرف.'),
        ];
    }

    /**
     * @param array{ok?: bool, status?: int, message?: string} $result
     */
    private static function isAmineDeleteAlreadyApplied(array $result, string $imageGuid, string $fileName): bool
    {
        if ((int) ($result['status'] ?? 0) !== 404) {
            return false;
        }

        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return false;
        }

        if (self::imageGuidExistsOnAmine($imageGuid)) {
            return false;
        }

        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($fileName !== '' && !str_contains($fileName, '..')) {
            $stillByFile = self::lookupAmineImageGuidByFileName($fileName);
            if ($stillByFile !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ok: bool, message: string, status?: int}
     */
    private static function attemptAmineDeleteByGuid(string $imageGuid): array
    {
        try {
            $response = ApiClient::delete('/api/material-images/' . rawurlencode($imageGuid));
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'status' => 0];
        }

        $status = (int) ($response['status'] ?? 0);
        if (($response['ok'] ?? false) || $status === 204) {
            return ['ok' => true, 'message' => '', 'status' => $status];
        }

        $message = (string) ($response['error'] ?? ($response['data']['message'] ?? 'فشل طلب الحذف.'));
        if ($status > 0) {
            $message = '[' . $status . '] ' . $message;
        }

        return ['ok' => false, 'message' => $message, 'status' => $status];
    }

    /** @param array<string, mixed> $row */
    private static function extractImageGuidFromRow(array $row): string
    {
        $raw = $row['imageGuid']
            ?? $row['ImageGuid']
            ?? $row['id']
            ?? $row['Id']
            ?? $row['guid']
            ?? $row['Guid']
            ?? '';
        $imageGuid = strtolower(trim((string) $raw));
        if ($imageGuid === '' || $imageGuid === '00000000-0000-0000-0000-000000000000') {
            return '';
        }

        return $imageGuid;
    }

    private static function lookupAmineImageGuidByFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($fileName === '' || str_contains($fileName, '..')) {
            return '';
        }

        try {
            $response = ApiClient::get('/api/material-images/lookup', ['fileName' => $fileName]);
        } catch (Throwable) {
            return '';
        }

        if (!($response['ok'] ?? false)) {
            return '';
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return self::normalizeImageGuid((string) ($data['id'] ?? $data['Id'] ?? ''));
    }

    private static function resolveAuthoritativeAmineSourceGuid(string $sourceFileName, ?string $knownGuid): string
    {
        $fromLookup = self::lookupAmineImageGuidByFileName($sourceFileName);
        if ($fromLookup !== '') {
            return $fromLookup;
        }

        $knownGuid = self::normalizeImageGuid((string) $knownGuid);
        if ($knownGuid !== '' && self::imageGuidExistsOnAmine($knownGuid)) {
            return $knownGuid;
        }

        $local = MaterialImageStorageService::resolveLocalSourcePath($knownGuid, $sourceFileName)
            ?? MaterialImageStorageService::resolveLocalPath($sourceFileName, false);
        if ($local !== null && is_file($local)) {
            $resolved = self::resolveAmineSourceGuid($sourceFileName, $local);
            if ($resolved !== '' && self::imageGuidExistsOnAmine($resolved)) {
                return $resolved;
            }
        }

        $withoutLocal = self::resolveAmineSourceGuidWithoutLocal($sourceFileName);
        if ($withoutLocal !== '' && self::imageGuidExistsOnAmine($withoutLocal)) {
            return $withoutLocal;
        }

        return $knownGuid !== '' ? $knownGuid : $withoutLocal;
    }

    private static function normalizeImageGuid(string $imageGuid): string
    {
        $imageGuid = strtolower(trim($imageGuid));
        if ($imageGuid === '' || $imageGuid === '00000000-0000-0000-0000-000000000000') {
            return '';
        }

        return $imageGuid;
    }

    private static function resolveAmineImageGuidForDelete(string $imageGuid, string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($fileName !== '' && !str_contains($fileName, '..')) {
            $fromFile = self::lookupAmineImageGuidByFileName($fileName);
            if ($fromFile !== '') {
                return $fromFile;
            }
        }

        $imageGuid = self::normalizeImageGuid($imageGuid);
        if ($imageGuid !== '' && self::imageGuidExistsOnAmine($imageGuid)) {
            return $imageGuid;
        }

        return $imageGuid;
    }

    /**
     * @param list<string> $materialGuids
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>}
     */
    public static function reassign(
        string $sourceFileName,
        string $imageGuid,
        ?string $currentMaterialGuid,
        array $materialGuids,
        ?string $uploadedByUserId = null,
        array $processedPathsByMaterial = [],
        bool $requireProcessedImages = false
    ): array {
        $unlink = self::unlinkImage($imageGuid, $currentMaterialGuid !== null && $currentMaterialGuid !== '' ? $currentMaterialGuid : null);
        if (!($unlink['ok'] ?? false) && $currentMaterialGuid !== null && $currentMaterialGuid !== '') {
            return self::assignError((string) ($unlink['message'] ?? 'فشل فك الربط قبل الاستبدال.'));
        }

        return self::assign(
            $sourceFileName,
            $materialGuids,
            $uploadedByUserId,
            $imageGuid,
            $processedPathsByMaterial,
            $requireProcessedImages
        );
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
    public static function assign(
        string $sourceFileName,
        array $materialGuids,
        ?string $uploadedByUserId = null,
        ?string $knownAmineSourceGuid = null,
        array $processedPathsByMaterial = [],
        bool $requireProcessedImages = false
    ): array {
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

        $previousPictureGuids = self::snapshotMaterialPictureGuids($materialGuids);

        $amineSourceGuid = self::resolveAuthoritativeAmineSourceGuid($sourceFileName, $knownAmineSourceGuid);

        $tempSourcePath = null;
        [$sourcePath, $tempSourcePath] = self::resolveAssignSourcePaths($sourceFileName, $amineSourceGuid);
        $hasLocal = $sourcePath !== null && is_file($sourcePath);
        $amineGuidVerified = $amineSourceGuid !== '' && self::imageGuidExistsOnAmine($amineSourceGuid);

        if (!$hasLocal && $amineSourceGuid === '') {
            $reconciled = self::reconcileAssignSuccessFromAmine(
                $materialGuids,
                $previousPictureGuids,
                $sourceFileName,
                '',
                $uploadedByUserId
            );
            if ($reconciled !== null) {
                return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
            }

            return self::assignError('الصورة غير موجودة على الأمين أو الموقع.');
        }

        try {
            if ($processedPathsByMaterial !== []) {
                return self::finalizeAssignResult(
                    self::assignViaUpload(
                        $hasLocal ? (string) $sourcePath : '',
                        $sourceFileName,
                        $materialGuids,
                        $uploadedByUserId,
                        $processedPathsByMaterial
                    ),
                    $sourceFileName,
                    $amineSourceGuid
                );
            }

            if ($requireProcessedImages) {
                return self::assignError('تعذر تجهيز الصورة مع تفاصيل المادة.');
            }

            $effectiveSourcePath = $hasLocal ? (string) $sourcePath : '';

            if ($hasLocal && $sourcePath !== null && !$amineGuidVerified) {
                $uploadResult = self::assignViaUpload((string) $sourcePath, $sourceFileName, $materialGuids, $uploadedByUserId);
                if (($uploadResult['linked'] ?? 0) > 0) {
                    return self::finalizeAssignResult($uploadResult, $sourceFileName, $amineSourceGuid);
                }

                $reconciled = self::reconcileAssignSuccessFromAmine(
                    $materialGuids,
                    $previousPictureGuids,
                    $sourceFileName,
                    (string) $sourcePath,
                    $uploadedByUserId
                );
                if ($reconciled !== null) {
                    return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
                }
            }

            if ($amineSourceGuid !== '') {
                $amineResult = self::assignViaAmine(
                    $effectiveSourcePath,
                    $sourceFileName,
                    $amineSourceGuid,
                    $materialGuids,
                    $uploadedByUserId
                );
                if (self::assignResultShouldFinalize($amineResult)) {
                    return self::finalizeAssignResult($amineResult, $sourceFileName, $amineSourceGuid);
                }

                if (self::isSourceNotFoundAssignError($amineResult)) {
                    $resolvedGuid = self::lookupAmineImageGuidByFileName($sourceFileName);
                    if ($resolvedGuid === '') {
                        $resolvedGuid = $hasLocal
                            ? self::resolveAmineSourceGuid($sourceFileName, (string) $sourcePath)
                            : self::resolveAmineSourceGuidWithoutLocal($sourceFileName);
                    }
                    if ($resolvedGuid !== '' && strcasecmp($resolvedGuid, $amineSourceGuid) !== 0) {
                        $amineSourceGuid = $resolvedGuid;
                        if ($sourcePath === null || !is_file((string) $sourcePath)) {
                            [$sourcePath, $moreTemp] = self::resolveAssignSourcePaths($sourceFileName, $amineSourceGuid);
                            if ($moreTemp !== null) {
                                $tempSourcePath = $moreTemp;
                            }
                            $hasLocal = $sourcePath !== null && is_file((string) $sourcePath);
                            $effectiveSourcePath = $hasLocal ? (string) $sourcePath : '';
                        }
                        $amineResult = self::assignViaAmine(
                            $effectiveSourcePath,
                            $sourceFileName,
                            $amineSourceGuid,
                            $materialGuids,
                            $uploadedByUserId
                        );
                        if (self::assignResultShouldFinalize($amineResult)) {
                            return self::finalizeAssignResult($amineResult, $sourceFileName, $amineSourceGuid);
                        }
                    }
                }

                $uploadSource = ($hasLocal && $sourcePath !== null) ? (string) $sourcePath : null;
                if ($uploadSource === null || !is_file($uploadSource)) {
                    [$uploadSource, $moreTemp] = self::resolveAssignSourcePaths($sourceFileName, $amineSourceGuid);
                    if ($moreTemp !== null) {
                        $tempSourcePath = $moreTemp;
                    }
                }
                if ($uploadSource !== null && is_file($uploadSource)) {
                    $uploadResult = self::assignViaUpload(
                        $uploadSource,
                        $sourceFileName,
                        $materialGuids,
                        $uploadedByUserId
                    );
                    if (($uploadResult['linked'] ?? 0) > 0) {
                        return self::finalizeAssignResult($uploadResult, $sourceFileName, $amineSourceGuid);
                    }

                    $reconciled = self::reconcileAssignSuccessFromAmine(
                        $materialGuids,
                        $previousPictureGuids,
                        $sourceFileName,
                        is_string($uploadSource) ? $uploadSource : $effectiveSourcePath,
                        $uploadedByUserId
                    );
                    if ($reconciled !== null) {
                        return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
                    }

                    return self::assignFailureOrReconcile(
                        self::combineAssignFailures($amineResult, $uploadResult),
                        $materialGuids,
                        $previousPictureGuids,
                        $sourceFileName,
                        $amineSourceGuid,
                        is_string($uploadSource) ? $uploadSource : $effectiveSourcePath,
                        $uploadedByUserId
                    );
                }

                $reconciled = self::reconcileAssignSuccessFromAmine(
                    $materialGuids,
                    $previousPictureGuids,
                    $sourceFileName,
                    $effectiveSourcePath,
                    $uploadedByUserId
                );
                if ($reconciled !== null) {
                    return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
                }

                return self::assignFailureOrReconcile(
                    $amineResult,
                    $materialGuids,
                    $previousPictureGuids,
                    $sourceFileName,
                    $amineSourceGuid,
                    $effectiveSourcePath,
                    $uploadedByUserId
                );
            }

            if ($hasLocal && $sourcePath !== null) {
                $uploadResult = self::assignViaUpload((string) $sourcePath, $sourceFileName, $materialGuids, $uploadedByUserId);
                if (($uploadResult['linked'] ?? 0) > 0) {
                    return self::finalizeAssignResult($uploadResult, $sourceFileName, $amineSourceGuid);
                }

                $reconciled = self::reconcileAssignSuccessFromAmine(
                    $materialGuids,
                    $previousPictureGuids,
                    $sourceFileName,
                    (string) $sourcePath,
                    $uploadedByUserId
                );
                if ($reconciled !== null) {
                    return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
                }

                return self::assignFailureOrReconcile(
                    $uploadResult,
                    $materialGuids,
                    $previousPictureGuids,
                    $sourceFileName,
                    $amineSourceGuid,
                    (string) $sourcePath,
                    $uploadedByUserId
                );
            }

            $reconciled = self::reconcileAssignSuccessFromAmine(
                $materialGuids,
                $previousPictureGuids,
                $sourceFileName,
                $effectiveSourcePath,
                $uploadedByUserId
            );
            if ($reconciled !== null) {
                return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
            }

            return self::assignError('تعذر تأكيد الربط. حدّث القائمة وتحقق من المادة على الأمين.');
        } finally {
            if ($tempSourcePath !== null && self::isTempProcessingSource($tempSourcePath)) {
                MaterialImageStorageService::deleteTempProcessedFile($tempSourcePath);
            }
        }
    }

    /**
     * @param array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} $result
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>, staging_kept?: bool}
     */
    private static function finalizeAssignResult(array $result, string $sourceFileName, string $amineSourceGuid): array
    {
        $linked = (int) ($result['linked'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $canCleanupStaging = $linked > 0 && $failed === 0;

        if ($canCleanupStaging) {
            foreach ($result['items'] ?? [] as $item) {
                if (!is_array($item) || !($item['ok'] ?? false)) {
                    continue;
                }
                if (empty($item['local_synced'])) {
                    $canCleanupStaging = false;
                    break;
                }
            }
        }

        if ($canCleanupStaging) {
            self::cleanupStagingSourceAfterAssign($sourceFileName, $amineSourceGuid);
            $result['staging_kept'] = false;

            return $result;
        }

        if ($linked > 0 || $failed > 0) {
            $result['staging_kept'] = true;
            $keepNote = 'أُبقي الملف المرحلي على الموقع والأمين لأنه لم تكتمل كل النسخ المحلية.';
            $message = trim((string) ($result['message'] ?? ''));
            if ($message === '' || !str_contains($message, 'المرحلي')) {
                $result['message'] = trim($message . ' ' . $keepNote);
            }
        }

        return $result;
    }

    private static function cleanupStagingSourceAfterAssign(string $sourceFileName, string $amineSourceGuid): void
    {
        $sourceFileName = basename(str_replace('\\', '/', trim($sourceFileName)));
        $amineSourceGuid = trim($amineSourceGuid);

        if ($amineSourceGuid !== '') {
            self::deleteOnAmine($amineSourceGuid, $sourceFileName);
        }

        MaterialImageSyncService::purgeImageRecords($amineSourceGuid, $sourceFileName);
        if ($sourceFileName !== '') {
            MaterialImageStorageService::deleteLocalFile($sourceFileName);
        }
    }

    private static function resolveAmineSourceGuidWithoutLocal(string $fileName): string
    {
        $queueRow = self::queueRowByFileName($fileName);
        $queueGuid = trim((string) ($queueRow['amine_image_guid'] ?? ''));
        if ($queueGuid !== '' && self::imageGuidExistsOnAmine($queueGuid)) {
            return $queueGuid;
        }

        $lookup = MaterialImageSyncService::lookupFilesOnAmine([
            ['file_name' => $fileName, 'fingerprint' => null],
        ]);
        $found = $lookup[self::lookupKey($fileName)] ?? null;
        $lookupGuid = trim((string) ($found['id'] ?? ''));

        return $lookupGuid !== '' && self::imageGuidExistsOnAmine($lookupGuid) ? $lookupGuid : '';
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
        $previousPictureGuids = self::snapshotMaterialPictureGuids($materialGuids);

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
            return self::assignError(self::extractAmineApiError($response, 'فشل توليد نسخ الصور على الأمين.'));
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
                    'local_synced' => false,
                    'message' => 'استجابة API ناقصة لإحدى المواد.',
                ];
                continue;
            }

            $localCopy = self::ensureLocalAssignedCopy(
                $sourcePath,
                $storedFileName,
                $imageGuid,
                $uploadedByUserId,
                $sourceFileName
            );

            if (!($localCopy['ok'] ?? false)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => $materialName,
                    'material_code' => $materialCode,
                    'image_guid' => $imageGuid,
                    'file_name' => $storedFileName,
                    'ok' => false,
                    'local_synced' => false,
                    'amine_linked' => true,
                    'message' => (string) ($localCopy['message'] ?? 'تم الربط على الأمين لكن تعذر حفظ النسخة على الموقع.'),
                ];
                continue;
            }

            self::purgeReplacedMaterialImage(
                (string) ($previousPictureGuids[$materialGuid] ?? ''),
                $imageGuid
            );

            $linked++;
            $results[] = [
                'material_guid' => $materialGuid,
                'material_name' => $materialName,
                'material_code' => $materialCode,
                'image_guid' => $imageGuid,
                'file_name' => (string) ($localCopy['file_name'] ?? $storedFileName),
                'ok' => true,
                'local_synced' => true,
                'message' => 'تم الربط وحفظ النسخة على الموقع.',
            ];
        }

        $ok = $linked > 0 && $failed === 0;
        if ($linked > 0 && $failed > 0) {
            $message = 'رُبطت ' . $linked . ' مادة محلياً وفشلت ' . $failed
                . ' (على الأمين قد تكون رُبطت لكن بلا نسخة موقع). الملف المرحلي باقٍ.';
        } elseif ($linked > 0) {
            $message = 'تم ربط ' . $linked . ' مادة من الصورة «' . $sourceFileName . '» مع حفظ النسخ على الموقع.';
        } else {
            $message = self::formatAssignFailureMessage($results, $sourceFileName);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'linked' => $linked,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    /**
     * Copy/download the per-material Amine image onto the portal disk and register it.
     * Always materializes a {guid}.{ext} copy so the missing-image index and image.php stay reliable.
     *
     * @return array{ok: bool, message: string, file_name?: string, local_path?: string}
     */
    private static function ensureLocalAssignedCopy(
        string $sourcePath,
        string $storedFileName,
        string $imageGuid,
        ?string $uploadedByUserId,
        string $assignedFromFileName
    ): array {
        $storedFileName = basename(str_replace('\\', '/', trim($storedFileName)));
        $imageGuid = strtolower(trim($imageGuid));
        if ($storedFileName === '' || str_contains($storedFileName, '..') || $imageGuid === '') {
            return ['ok' => false, 'message' => 'اسم ملف أو معرف صورة غير صالح لحفظ النسخة المحلية.'];
        }

        $settings = MaterialImageStorageService::settings();
        $localPath = null;
        $fileName = $storedFileName;

        $existing = MaterialImageStorageService::resolveLocalPath($storedFileName, false);
        if ($existing !== null && is_file($existing)) {
            $localPath = $existing;
        } elseif ($sourcePath !== '' && is_file($sourcePath)) {
            $copy = MaterialImageStorageService::copyLocalFromSource($sourcePath, $storedFileName);
            if ($copy['ok'] ?? false) {
                $fileName = (string) ($copy['file_name'] ?? $storedFileName);
                $candidate = $settings['images_dir'] . DIRECTORY_SEPARATOR . $fileName;
                if (is_file($candidate)) {
                    $localPath = $candidate;
                }
            }
        }

        if ($localPath === null) {
            $targetPath = $settings['images_dir'] . DIRECTORY_SEPARATOR . $storedFileName;
            $tmpPath = $settings['images_dir'] . DIRECTORY_SEPARATOR . ('.assign-' . $imageGuid . '.tmp');
            if (!is_dir($settings['images_dir']) && !@mkdir($settings['images_dir'], 0775, true) && !is_dir($settings['images_dir'])) {
                return ['ok' => false, 'message' => 'تعذر إنشاء مجلد صور الموقع لحفظ النسخة المحلية.'];
            }

            try {
                $download = ApiClient::downloadToFile(
                    '/api/material-images/' . rawurlencode($imageGuid) . '/file',
                    [],
                    $tmpPath,
                    120
                );
            } catch (Throwable $exception) {
                @unlink($tmpPath);

                return ['ok' => false, 'message' => 'تعذر تنزيل نسخة الأمين للموقع: ' . $exception->getMessage()];
            }

            if (!($download['ok'] ?? false) || !is_file($tmpPath) || (int) (filesize($tmpPath) ?: 0) <= 0) {
                @unlink($tmpPath);
                $detail = trim((string) ($download['error'] ?? ''));

                return [
                    'ok' => false,
                    'message' => 'تم الربط على الأمين لكن تعذر حفظ النسخة على الموقع'
                        . ($detail !== '' ? (': ' . $detail) : '.'),
                ];
            }

            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            if (!@rename($tmpPath, $targetPath) && !@copy($tmpPath, $targetPath)) {
                @unlink($tmpPath);

                return ['ok' => false, 'message' => 'تعذر كتابة ملف الصورة على مجلد الموقع.'];
            }
            @unlink($tmpPath);

            $thumbPath = $settings['thumbnails_dir'] . DIRECTORY_SEPARATOR . $storedFileName;
            if (is_dir($settings['thumbnails_dir']) || @mkdir($settings['thumbnails_dir'], 0775, true) || is_dir($settings['thumbnails_dir'])) {
                $copyThumb = MaterialImageStorageService::copyLocalFromSource($targetPath, $storedFileName);
                unset($copyThumb);
            }

            $localPath = $targetPath;
            $fileName = $storedFileName;
        }

        $preferredExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($preferredExt === '') {
            $preferredExt = 'jpg';
        }
        $guidPath = MaterialImageStorageService::ensureGuidNamedLocalCopy($imageGuid, $localPath, $preferredExt);
        if ($guidPath === null || !is_readable($guidPath)) {
            return [
                'ok' => false,
                'message' => 'تم حفظ الملف باسم الأمين لكن تعذر تثبيت نسخة GUID على الموقع — لم يُحذف الملف المرحلي.',
            ];
        }

        // Keep Amine stored-name mapping as well (dashboard lists / Amine sync).
        try {
            MaterialImageSyncService::recordAssignedCopy(
                $fileName,
                $localPath,
                $imageGuid,
                $uploadedByUserId,
                $assignedFromFileName
            );
        } catch (Throwable) {
            // GUID copy + queue row from ensureGuidNamedLocalCopy is enough for serving.
        }

        $guidFileName = basename($guidPath);

        return [
            'ok' => true,
            'message' => '',
            'file_name' => $guidFileName !== '' ? $guidFileName : $fileName,
            'local_path' => $guidPath,
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
        ?string $uploadedByUserId,
        array $processedPathsByMaterial = []
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
                    'local_synced' => false,
                    'message' => 'المادة غير موجودة.',
                ];
                continue;
            }

            $previousPictureGuid = self::materialPictureGuid($material);

            $targetFileName = self::buildTargetFileName($material, $extension !== '' ? '.' . $extension : '.jpg');
            $guidKey = strtolower($materialGuid);
            $effectiveSource = $processedPathsByMaterial[$guidKey]
                ?? $processedPathsByMaterial[$materialGuid]
                ?? $sourcePath;
            if ($effectiveSource === '' || !is_file($effectiveSource)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'ok' => false,
                    'local_synced' => false,
                    'message' => 'ملف الصورة المعالجة غير متوفر.',
                ];
                continue;
            }

            $copy = MaterialImageStorageService::copyLocalFromSource($effectiveSource, $targetFileName);
            if (!($copy['ok'] ?? false)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'ok' => false,
                    'local_synced' => false,
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
                    'local_synced' => false,
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
                    'local_synced' => false,
                    'message' => $status > 0 ? ('[' . $status . '] ' . $apiMessage) : $apiMessage,
                ];
                continue;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $imageGuid = self::extractUploadedImageGuid($response, $materialGuid);
            $storedFileName = trim((string) ($data['storedFileName'] ?? $data['StoredFileName'] ?? $data['fileName'] ?? $data['FileName'] ?? ''));
            if ($storedFileName !== '' && strcasecmp($storedFileName, $fileName) !== 0) {
                $resync = MaterialImageStorageService::copyLocalFromSource($localPath, $storedFileName);
                if ($resync['ok'] ?? false) {
                    MaterialImageStorageService::deleteLocalFile($fileName);
                    $fileName = (string) ($resync['file_name'] ?? $storedFileName);
                    $localPath = MaterialImageStorageService::settings()['images_dir'] . DIRECTORY_SEPARATOR . $fileName;
                } else {
                    $fileName = $storedFileName;
                }
            }

            if ($imageGuid === '') {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'ok' => false,
                    'local_synced' => false,
                    'message' => 'رفع الأمين نجح لكن لم يُؤكَّد ربط الصورة بالمادة.',
                ];
                continue;
            }

            if (!is_file($localPath)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'image_guid' => $imageGuid,
                    'file_name' => $fileName,
                    'ok' => false,
                    'local_synced' => false,
                    'amine_linked' => true,
                    'message' => 'تم الربط على الأمين لكن النسخة المحلية غير موجودة على الموقع.',
                ];
                continue;
            }

            $preferredExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($preferredExt === '') {
                $preferredExt = 'jpg';
            }
            $guidPath = MaterialImageStorageService::ensureGuidNamedLocalCopy($imageGuid, $localPath, $preferredExt);
            if ($guidPath === null || !is_readable($guidPath)) {
                $failed++;
                $results[] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'image_guid' => $imageGuid,
                    'file_name' => $fileName,
                    'ok' => false,
                    'local_synced' => false,
                    'amine_linked' => true,
                    'message' => 'تم الربط على الأمين لكن تعذر تثبيت نسخة GUID على الموقع — الملف المرحلي باقٍ.',
                ];
                continue;
            }

            try {
                MaterialImageSyncService::recordAssignedCopy(
                    $fileName,
                    $localPath,
                    $imageGuid,
                    $uploadedByUserId,
                    $sourceFileName
                );
            } catch (Throwable) {
            }

            self::purgeReplacedMaterialImage($previousPictureGuid, $imageGuid);

            $linked++;
            $results[] = [
                'material_guid' => $materialGuid,
                'material_name' => (string) ($material['name'] ?? ''),
                'material_code' => (string) ($material['material_code'] ?? ''),
                'image_guid' => $imageGuid,
                'file_name' => basename($guidPath),
                'ok' => true,
                'local_synced' => true,
                'message' => 'تم الرفع والربط مع حفظ النسخة على الموقع.',
            ];
        }

        $ok = $linked > 0 && $failed === 0;
        if ($linked > 0 && $failed > 0) {
            $message = 'رُبطت ' . $linked . ' مادة وفشلت ' . $failed
                . '. الملف المرحلي باقٍ حتى تكتمل كل النسخ المحلية.';
        } elseif ($linked > 0) {
            $message = 'تم رفع وربط ' . $linked . ' مادة من الصورة «' . $sourceFileName . '» مع حفظ النسخ على الموقع.';
        } else {
            $message = self::formatAssignFailureMessage($results, $sourceFileName);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'linked' => $linked,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    /** @param array<string, mixed> $material */
    private static function materialPictureGuid(array $material): string
    {
        $guid = trim((string) (
            $material['productImageGuid']
            ?? $material['ProductImageGuid']
            ?? $material['pictureGuid']
            ?? $material['PictureGuid']
            ?? $material['imageGuid']
            ?? $material['ImageGuid']
            ?? ''
        ));
        if ($guid === '' || strcasecmp($guid, '00000000-0000-0000-0000-000000000000') === 0) {
            return '';
        }

        return $guid;
    }

    private static function purgeReplacedMaterialImage(string $oldImageGuid, string $newImageGuid): void
    {
        $oldImageGuid = trim($oldImageGuid);
        $newImageGuid = trim($newImageGuid);
        if ($oldImageGuid === '' || $oldImageGuid === $newImageGuid) {
            return;
        }

        MaterialImageSyncService::purgeImageRecords($oldImageGuid);
    }

    /**
     * @param list<string> $materialGuids
     * @return array<string, string> materialGuid => old pictureGuid
     */
    private static function snapshotMaterialPictureGuids(array $materialGuids): array
    {
        $snapshot = [];
        foreach ($materialGuids as $materialGuid) {
            $materialGuid = trim($materialGuid);
            if ($materialGuid === '') {
                continue;
            }

            $material = self::fetchMaterial($materialGuid);
            if ($material === null) {
                continue;
            }

            $pictureGuid = self::materialPictureGuid($material);
            if ($pictureGuid !== '') {
                $snapshot[$materialGuid] = $pictureGuid;
            }
        }

        return $snapshot;
    }

    /** @return array<string, mixed>|null */
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

        $code = trim((string) ($data['materialCode'] ?? $data['MaterialCode'] ?? ''));
        $name = trim((string) ($data['name'] ?? $data['Name'] ?? ''));
        $latinName = trim((string) ($data['latinName'] ?? $data['LatinName'] ?? ''));
        $barcode = trim((string) ($data['barcode'] ?? $data['Barcode'] ?? ''));
        $manufacturer = trim((string) (
                $data['manufacturer']
                ?? $data['Manufacturer']
                ?? $data['company']
                ?? $data['Company']
                ?? ''
            ));

            return array_merge($data, [
                'name' => $name,
                'Name' => $name,
                'material_code' => $code,
                'materialCode' => $code,
                'MaterialCode' => $code,
                'latinName' => $latinName,
                'LatinName' => $latinName,
                'barcode' => $barcode,
                'Barcode' => $barcode,
                'code' => $code,
                'company' => $manufacturer,
                'Company' => $manufacturer,
                'manufacturer' => $manufacturer,
                'Manufacturer' => $manufacturer,
                'unity' => trim((string) ($data['primaryUnit'] ?? $data['PrimaryUnit'] ?? $data['unity'] ?? $data['Unity'] ?? '')),
                'Unity' => trim((string) ($data['primaryUnit'] ?? $data['PrimaryUnit'] ?? $data['unity'] ?? $data['Unity'] ?? '')),
                'unit2' => trim((string) ($data['packageUnit'] ?? $data['PackageUnit'] ?? $data['unit2'] ?? $data['Unit2'] ?? '')),
                'Unit2' => trim((string) ($data['packageUnit'] ?? $data['PackageUnit'] ?? $data['unit2'] ?? $data['Unit2'] ?? '')),
                'unit2Fact' => $data['packageConversionFactor'] ?? $data['PackageConversionFactor'] ?? $data['unit2Fact'] ?? $data['Unit2Fact'] ?? null,
                'Unit2Fact' => $data['packageConversionFactor'] ?? $data['PackageConversionFactor'] ?? $data['unit2Fact'] ?? $data['Unit2Fact'] ?? null,
                'productImageGuid' => $data['productImageGuid'] ?? $data['ProductImageGuid'] ?? null,
                'ProductImageGuid' => $data['productImageGuid'] ?? $data['ProductImageGuid'] ?? null,
                'pictureGuid' => $data['productImageGuid'] ?? $data['ProductImageGuid'] ?? $data['pictureGuid'] ?? $data['PictureGuid'] ?? null,
                'PictureGuid' => $data['productImageGuid'] ?? $data['ProductImageGuid'] ?? $data['pictureGuid'] ?? $data['PictureGuid'] ?? null,
            ]);
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

    /**
     * @param list<string> $materialGuids
     * @param array<string, mixed> $line1ByMaterial
     * @param array<string, mixed> $line2ByMaterial
     * @return array<string, string>
     */
    public static function buildProcessedImagesFromDetails(
        string $sourceFileName,
        ?string $amineSourceGuid,
        array $materialGuids,
        array $line1ByMaterial,
        array $line2ByMaterial
    ): array {
        if (!MaterialImageStorageService::canProcessImageDetails()) {
            return [];
        }

        $tempSource = self::resolveSourcePathForProcessing($sourceFileName, $amineSourceGuid);
        if ($tempSource === null) {
            return [];
        }

        $map = [];
        foreach ($materialGuids as $materialGuid) {
            $materialGuid = trim((string) $materialGuid);
            if ($materialGuid === '') {
                continue;
            }

            $material = self::fetchMaterial($materialGuid);
            $line1Override = self::detailLineForMaterial($line1ByMaterial, $materialGuid);
            $line2Override = self::detailLineForMaterial($line2ByMaterial, $materialGuid);
            $line1 = self::buildProductBannerLine($material, $line1Override);
            $line2 = self::buildPackagingBannerLine($material, $line2Override);
            if ($line1 === '' && $line2 === '') {
                continue;
            }

            $processed = MaterialImageStorageService::renderImageWithDetailsBanner($tempSource, $line1, $line2);
            if ($processed !== null) {
                $map[strtolower($materialGuid)] = $processed;
            }
        }

        if (self::isTempProcessingSource($tempSource)) {
            MaterialImageStorageService::deleteTempProcessedFile($tempSource);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $lines
     */
    private static function detailLineForMaterial(array $lines, string $materialGuid): string
    {
        if (isset($lines[$materialGuid])) {
            return trim((string) $lines[$materialGuid]);
        }

        foreach ($lines as $key => $value) {
            if (strcasecmp((string) $key, $materialGuid) === 0) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private static function isTempProcessingSource(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_contains($path, '/_processed/src_');
    }

    private static function resolveSourcePathForProcessing(string $sourceFileName, ?string $amineSourceGuid): ?string
    {
        $local = MaterialImageStorageService::resolveLocalPath($sourceFileName, false);
        if ($local !== null && is_file($local)) {
            return $local;
        }

        $amineSourceGuid = trim((string) $amineSourceGuid);
        if ($amineSourceGuid === '') {
            return null;
        }

        try {
            $download = ApiClient::getBinary('/api/material-images/' . rawurlencode($amineSourceGuid) . '/file');
        } catch (Throwable) {
            return null;
        }

        if (!($download['ok'] ?? false)) {
            return null;
        }

        $settings = MaterialImageStorageService::settings();
        $directory = $settings['images_dir'] . DIRECTORY_SEPARATOR . '_processed';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $dest = $directory . DIRECTORY_SEPARATOR . ('src_' . bin2hex(random_bytes(6)) . '.jpg');
        if (@file_put_contents($dest, $download['body'] ?? '') === false) {
            return null;
        }

        return $dest;
    }

    /**
     * @param array<string, mixed>|null $files
     * @return array<string, string>
     */
    public static function collectProcessedUploads(?array $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $tmpNames = $files['tmp_name'] ?? null;
        if (!is_array($tmpNames)) {
            return [];
        }

        $map = [];
        foreach ($tmpNames as $materialGuid => $tmpPath) {
            $materialGuid = trim((string) $materialGuid);
            if ($materialGuid === '' || !is_string($tmpPath) || $tmpPath === '') {
                continue;
            }

            $error = (int) ($files['error'][$materialGuid] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $originalName = (string) ($files['name'][$materialGuid] ?? 'linked.jpg');
            $saved = MaterialImageStorageService::saveProcessedUpload($tmpPath, $originalName);
            if ($saved !== null) {
                $map[strtolower($materialGuid)] = $saved;
            }
        }

        return $map;
    }

    /** @return array{ok: bool, message: string, items: list<array<string, mixed>>} */
    public static function detailsProcessingError(): array
    {
        if (!MaterialImageStorageService::canProcessImageDetails()) {
            return self::assignError(MaterialImageStorageService::detailsBannerRequirements()['message']);
        }

        return self::assignError('تعذر تجهيز الصورة مع البانر السفلي. تحقق من الصورة والنصوص.');
    }

    /** @param array<string, mixed>|null $material */
    private static function resolveMaterialBannerCode(?array $material): string
    {
        if (!is_array($material)) {
            return '';
        }

        return trim((string) (
            $material['material_code']
            ?? $material['materialCode']
            ?? $material['MaterialCode']
            ?? ''
        ));
    }

    /** @param array<string, mixed>|null $material */
    private static function resolveMaterialBannerName(?array $material): string
    {
        if (!is_array($material)) {
            return '';
        }

        return trim((string) ($material['name'] ?? $material['Name'] ?? ''));
    }

    /** @param array<string, mixed>|null $material */
    private static function buildProductBannerLine(?array $material, string $override = ''): string
    {
        $override = trim($override);
        if ($override !== '') {
            return MaterialImageStorageService::normalizeProductBannerLine($override);
        }

        if (!is_array($material)) {
            return '';
        }

        $code = self::resolveMaterialBannerCode($material);
        $name = self::resolveMaterialBannerName($material);

        if ($code !== '' && $name !== '') {
            return $name . ' - ' . $code;
        }

        return $code !== '' ? $code : $name;
    }

    /** @param array<string, mixed>|null $material */
    private static function buildPackagingBannerLine(?array $material, string $override = ''): string
    {
        $override = trim($override);
        if ($override !== '') {
            if (preg_match('/^التعبئة\s*:/u', $override) === 1) {
                return $override;
            }

            return 'التعبئة : ' . $override;
        }

        if (!is_array($material)) {
            return '';
        }

        $packQty = ShareCartService::packaging($material);
        if ($packQty <= 0) {
            return '';
        }

        $qty = rtrim(rtrim(number_format($packQty, 2, '.', ''), '0'), '.');
        $unit = ShareCartService::primaryUnitLabel($material);

        return 'التعبئة : ' . $qty . ' ' . $unit;
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
        $uploadLinked = (int) ($uploadResult['linked'] ?? 0);
        $amineLinked = (int) ($amineResult['linked'] ?? 0);
        if ($uploadLinked > 0) {
            return $uploadResult;
        }
        if ($amineLinked > 0) {
            return $amineResult;
        }

        $items = array_merge($uploadResult['items'] ?? [], $amineResult['items'] ?? []);
        if (self::isSourceNotFoundAssignError($amineResult) && $items !== []) {
            $uploadMessage = self::formatAssignFailureMessage($uploadResult['items'] ?? [], '');
            if ($uploadMessage !== 'لم يُربط أي مادة من «». تحقق من صلاحيات حساب API (materials.update) واتصال الأمين.') {
                return [
                    'ok' => false,
                    'message' => $uploadMessage,
                    'linked' => 0,
                    'failed' => max((int) ($amineResult['failed'] ?? 0), (int) ($uploadResult['failed'] ?? 0)),
                    'items' => $items,
                ];
            }
        }

        $message = self::formatAssignFailureMessage($items, '');
        if ($message === 'لم يُربط أي مادة من «». تحقق من صلاحيات حساب API (materials.update) واتصال الأمين.') {
            $message = trim((string) ($uploadResult['message'] ?? ''));
            if ($message === '' || str_starts_with($message, 'تم ')) {
                $message = self::localizeAmineAssignMessage((string) ($amineResult['message'] ?? 'لم يُربط أي مادة.'));
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

    /** @return array{0: string|null, 1: string|null} */
    private static function resolveAssignSourcePaths(string $sourceFileName, string $amineSourceGuid): array
    {
        $amineSourceGuid = trim($amineSourceGuid);
        $local = MaterialImageStorageService::resolveLocalSourcePath($amineSourceGuid, $sourceFileName);
        if ($local !== null) {
            return [$local, null];
        }

        $local = MaterialImageStorageService::resolveLocalPath($sourceFileName, false);
        if ($local !== null && is_file($local)) {
            return [$local, null];
        }

        if ($amineSourceGuid === '') {
            return [null, null];
        }

        $temp = self::resolveSourcePathForProcessing($sourceFileName, $amineSourceGuid);
        if ($temp === null || !is_file($temp)) {
            return [null, null];
        }

        return [$temp, self::isTempProcessingSource($temp) ? $temp : null];
    }

    /** @param array<string, mixed> $response */
    private static function extractUploadedImageGuid(array $response, string $materialGuid): string
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $imageGuid = trim((string) ($data['id'] ?? $data['Id'] ?? $data['guid'] ?? $data['Guid'] ?? ''));
        if ($imageGuid !== '' && strcasecmp($imageGuid, '00000000-0000-0000-0000-000000000000') !== 0) {
            return $imageGuid;
        }

        $material = self::fetchMaterial($materialGuid);

        return $material !== null ? self::materialPictureGuid($material) : '';
    }

    /**
     * @param list<string> $materialGuids
     * @param array<string, string> $previousPictureGuids
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>}|null
     */
    /**
     * @param list<string> $materialGuids
     * @param array<string, string> $previousPictureGuids
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>}|null
     */
    private static function reconcileAssignSuccessFromAmine(
        array $materialGuids,
        array $previousPictureGuids,
        string $sourceFileName,
        string $sourcePath = '',
        ?string $uploadedByUserId = null
    ): ?array {
        /** @var array<string, array<string, mixed>> $itemsByMaterial */
        $itemsByMaterial = [];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($attempt > 0) {
                usleep(350000);
            }

            $pending = 0;

            foreach ($materialGuids as $materialGuid) {
                $materialGuid = trim($materialGuid);
                if ($materialGuid === '' || isset($itemsByMaterial[$materialGuid])) {
                    continue;
                }

                $material = self::fetchMaterial($materialGuid);
                if ($material === null) {
                    $pending++;
                    continue;
                }

                $currentPictureGuid = self::materialPictureGuid($material);
                $previousPictureGuid = (string) ($previousPictureGuids[$materialGuid] ?? '');
                if ($currentPictureGuid === '' || strcasecmp($currentPictureGuid, $previousPictureGuid) === 0) {
                    $pending++;
                    continue;
                }

                $storedFileName = self::resolveStoredFileNameForImageGuid($currentPictureGuid);
                if ($storedFileName === '') {
                    $ext = strtolower(pathinfo($sourceFileName, PATHINFO_EXTENSION));
                    $storedFileName = self::buildTargetFileName(
                        $material,
                        $ext !== '' ? '.' . $ext : '.jpg'
                    );
                }

                $localCopy = self::ensureLocalAssignedCopy(
                    $sourcePath,
                    $storedFileName,
                    $currentPictureGuid,
                    $uploadedByUserId,
                    $sourceFileName
                );

                if (!($localCopy['ok'] ?? false)) {
                    $itemsByMaterial[$materialGuid] = [
                        'material_guid' => $materialGuid,
                        'material_name' => (string) ($material['name'] ?? ''),
                        'material_code' => (string) ($material['material_code'] ?? ''),
                        'image_guid' => $currentPictureGuid,
                        'file_name' => $storedFileName,
                        'ok' => false,
                        'local_synced' => false,
                        'amine_linked' => true,
                        'message' => (string) ($localCopy['message'] ?? 'تم الربط على الأمين لكن تعذر حفظ النسخة على الموقع.'),
                    ];
                    continue;
                }

                self::purgeReplacedMaterialImage($previousPictureGuid, $currentPictureGuid);

                $itemsByMaterial[$materialGuid] = [
                    'material_guid' => $materialGuid,
                    'material_name' => (string) ($material['name'] ?? ''),
                    'material_code' => (string) ($material['material_code'] ?? ''),
                    'image_guid' => $currentPictureGuid,
                    'file_name' => (string) ($localCopy['file_name'] ?? $storedFileName),
                    'ok' => true,
                    'local_synced' => true,
                    'message' => 'تم التأكد من الربط وحفظ النسخة على الموقع.',
                ];
            }

            if ($pending === 0) {
                break;
            }
        }

        if ($itemsByMaterial === []) {
            return null;
        }

        $items = array_values($itemsByMaterial);
        $linked = 0;
        $failedLocal = 0;
        foreach ($items as $item) {
            if ($item['ok'] ?? false) {
                $linked++;
            } else {
                $failedLocal++;
            }
        }

        $unresolved = max(0, count($materialGuids) - count($items));
        $failed = $failedLocal + $unresolved;
        $ok = $linked > 0 && $failed === 0;

        if ($linked > 0 && $failed > 0) {
            $message = 'رُبطت ' . $linked . ' مادة محلياً وفشلت أو لم تُؤكَّد ' . $failed
                . '. الملف المرحلي باقٍ.';
        } elseif ($linked > 0) {
            $message = 'تم ربط ' . $linked . ' مادة من الصورة «' . $sourceFileName . '» مع حفظ النسخ على الموقع.';
        } else {
            $message = self::formatAssignFailureMessage($items, $sourceFileName);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'linked' => $linked,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    private static function resolveStoredFileNameForImageGuid(string $imageGuid): string
    {
        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return '';
        }

        try {
            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));
            if (!($response['ok'] ?? false)) {
                return '';
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $name = trim((string) (
                $data['storedFileName']
                ?? $data['StoredFileName']
                ?? $data['fileName']
                ?? $data['FileName']
                ?? $data['name']
                ?? $data['Name']
                ?? ''
            ));

            return $name !== '' ? basename(str_replace('\\', '/', $name)) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} $failure
     * @param list<string> $materialGuids
     * @param array<string, string> $previousPictureGuids
     * @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>}
     */
    private static function assignFailureOrReconcile(
        array $failure,
        array $materialGuids,
        array $previousPictureGuids,
        string $sourceFileName,
        string $amineSourceGuid,
        string $sourcePath = '',
        ?string $uploadedByUserId = null
    ): array {
        $reconciled = self::reconcileAssignSuccessFromAmine(
            $materialGuids,
            $previousPictureGuids,
            $sourceFileName,
            $sourcePath,
            $uploadedByUserId
        );
        if ($reconciled !== null) {
            return self::finalizeAssignResult($reconciled, $sourceFileName, $amineSourceGuid);
        }

        return $failure;
    }

    /** @param array<string, mixed> $response */
    private static function extractAmineApiError(array $response, string $fallback): string
    {
        $status = (int) ($response['status'] ?? 0);
        $message = '';
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $message = trim((string) ($data['message'] ?? $data['Message'] ?? ''));
            if ($message === '') {
                $title = trim((string) ($data['title'] ?? ''));
                $detail = trim((string) ($data['detail'] ?? ''));
                $message = $detail !== ''
                    ? ($title !== '' ? ($title . ': ' . $detail) : $detail)
                    : $title;
            }
        }
        if ($message === '') {
            $message = trim((string) ($response['error'] ?? ''));
        }
        if ($message === '') {
            $message = $fallback;
        }
        if ($status > 0 && !str_starts_with($message, '[')) {
            $message = '[' . $status . '] ' . $message;
        }

        return self::localizeAmineAssignMessage($message);
    }

    private static function localizeAmineAssignMessage(string $message): string
    {
        $normalized = strtolower(trim($message));

        return match (true) {
            str_contains($normalized, 'source image was not found') => 'صورة المصدر غير موجودة على الأمين. جرّب إعادة تحميل القائمة.',
            str_contains($normalized, 'source image file was not found on disk') => 'ملف الصورة غير موجود على قرص الأمين — سيتم محاولة الرفع من نسخة الموقع.',
            str_contains($normalized, 'no valid materials were found') => 'لم تُعثر على مواد صالحة للربط.',
            str_contains($normalized, 'material was not found during linking') => 'تعذر ربط المادة أثناء التنفيذ على الأمين.',
            str_contains($normalized, 'forbidden') || str_contains($normalized, '403') => 'لا توجد صلاحية materials.update على حساب API.',
            default => $message,
        };
    }

    /** @param array{ok?: bool, message?: string, linked?: int, failed?: int, items?: list<array<string, mixed>>} $result */
    private static function assignResultShouldFinalize(array $result): bool
    {
        if ((int) ($result['linked'] ?? 0) > 0) {
            return true;
        }

        foreach ($result['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['amine_linked']) || !empty($item['image_guid'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array{ok?: bool, message?: string, linked?: int, failed?: int, items?: list<array<string, mixed>>} $result */
    private static function isSourceNotFoundAssignError(array $result): bool
    {
        $message = strtolower(trim((string) ($result['message'] ?? '')));

        return str_contains($message, 'source image was not found')
            || str_contains($message, 'صورة المصدر غير موجودة');
    }

    /** @return array{ok: bool, message: string, linked: int, failed: int, items: list<array<string, mixed>>} */
    public static function assignError(string $message): array
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
