<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\SimpleZipWriter;
use Portal\Support\Utf8Text;

final class MaterialImageZipService
{
    public const MAX_ORDER_IMAGES = 200;
    public const MAX_SPLIT_PACKAGES = 40;
    public const MAX_LOCAL_ZIP_IMAGES = 500;

    /**
     * @return array<string, list<string>>
     */
    public static function splitDimensionKeys(): array
    {
        return [
            'materialTypes' => ['materialTypes', 'materialType'],
            'ageCategories' => ['ageCategories', 'ageCategory'],
            'manufacturers' => ['manufacturers', 'manufacturer'],
            'sizeRanges' => ['sizeRanges', 'sizeRange'],
            'countryOfOrigins' => ['countryOfOrigins', 'countryOfOrigin'],
            'storeGuids' => ['storeGuids', 'storeGuid'],
            'groupGuids' => ['groupGuids', 'groupGuid'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function streamSplitMaterialZips(array $input): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('لا يمكن بدء التحميل بعد إرسال المخرجات.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $splitBy = trim((string) ($input['splitBy'] ?? ''));
        $dimensions = self::splitDimensionKeys();
        if (!isset($dimensions[$splitBy])) {
            throw new \RuntimeException('بُعد التقسيم غير مدعوم.');
        }

        $splitValues = self::extractFilterValues($input, $dimensions[$splitBy]);
        if ($splitValues === []) {
            throw new \RuntimeException('اختر قيمة واحدة على الأقل (تشيب) في فلتر التقسيم المختار.');
        }
        if (count($splitValues) > self::MAX_SPLIT_PACKAGES) {
            throw new \RuntimeException('الحد الأقصى للتقسيم ' . self::MAX_SPLIT_PACKAGES . ' ملف داخل الأرشيف.');
        }

        $baseInput = $input;
        unset($baseInput['splitBy'], $baseInput['archiveName']);
        foreach ($dimensions[$splitBy] as $key) {
            unset($baseInput[$key]);
        }

        $masterPath = tempnam(sys_get_temp_dir(), 'splitzip_');
        if ($masterPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للتقسيم.');
        }

        $childPaths = [];
        $archiveEntries = [];
        $usedNames = [];
        $added = 0;

        try {
            foreach ($splitValues as $value) {
                $childInput = $baseInput;
                $childInput[$splitBy] = $value;

                $childEntries = self::collectLocalMaterialImageEntries($childInput);
                if ($childEntries === []) {
                    continue;
                }

                $childPath = tempnam(sys_get_temp_dir(), 'childzip_');
                if ($childPath === false) {
                    continue;
                }
                $childPaths[] = $childPath;

                self::buildZipFromFileEntries($childPath, $childEntries);

                $childSize = filesize($childPath);
                if ($childSize === false || $childSize < 22) {
                    continue;
                }

                $entryName = self::uniqueEntryName(self::sanitizeFilename($value) . '.zip', $usedNames);
                $archiveEntries[] = ['path' => $childPath, 'name' => $entryName];
                $added++;
            }

            if ($added === 0) {
                throw new \RuntimeException('لا توجد صور محلية على الموقع لخيارات التقسيم المحددة.');
            }

            self::buildZipFromFileEntries($masterPath, $archiveEntries);
            self::streamLocalZipFile($masterPath, 'split-material-images');
        } finally {
            foreach ($childPaths as $childPath) {
                if (is_file($childPath)) {
                    @unlink($childPath);
                }
            }
            if (is_file($masterPath)) {
                @unlink($masterPath);
            }
        }
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public static function streamApiZip(string $apiPath, array $query, string $fallbackFilename): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('لا يمكن بدء التحميل بعد إرسال المخرجات.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $archiveName = trim((string) ($query['archiveName'] ?? ''));
        unset($query['archiveName']);
        $filename = self::sanitizeFilename($archiveName !== '' ? $archiveName : $fallbackFilename);

        $tempPath = tempnam(sys_get_temp_dir(), 'apizip_');
        if ($tempPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للتحميل.');
        }

        try {
            $result = ApiClient::downloadToFile($apiPath, $query, $tempPath, 900);
            if (!($result['ok'] ?? false)) {
                throw new \RuntimeException(self::readApiZipErrorMessage($tempPath, (int) ($result['status'] ?? 502), (string) ($result['error'] ?? '')));
            }

            $contentType = strtolower((string) ($result['contentType'] ?? ''));
            if (!str_contains($contentType, 'zip') && !str_contains($contentType, 'octet-stream')) {
                throw new \RuntimeException(self::readApiZipErrorMessage($tempPath, (int) ($result['status'] ?? 502), 'استجابة غير صالحة من API الأمين.'));
            }

            $size = filesize($tempPath);
            if ($size === false || $size < 22) {
                throw new \RuntimeException('ملف ZIP فارغ أو لا توجد صور مطابقة.');
            }

            self::streamLocalZipFile($tempPath, $filename);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private static function streamLocalZipFile(string $path, string $filename): void
    {
        $size = filesize($path);
        if ($size === false || $size < 22) {
            throw new \RuntimeException('ملف ZIP فارغ أو تالف.');
        }

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::sanitizeFilename($filename) . '.zip"');
        header('Content-Length: ' . (string) $size);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('تعذر قراءة ملف ZIP.');
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
        }
        fclose($handle);
    }

    private static function readApiZipErrorMessage(string $path, int $status, string $fallback): string
    {
        $body = is_file($path) ? (string) file_get_contents($path) : '';
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $message = trim((string) ($decoded['message'] ?? $decoded['title'] ?? ''));
                if ($message !== '') {
                    return self::translateApiZipError($message, $status);
                }
            }
        }

        return self::translateApiZipError($fallback, $status);
    }

    private static function translateApiZipError(string $message, int $status): string
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'no material rows')) {
            return 'لا توجد أصناف مواد في هذه الفاتورة.';
        }
        if (str_contains($normalized, 'no image files')) {
            return 'لا توجد صور محلية على الموقع مرتبطة بهذه الفاتورة أو المادة.';
        }

        if ($status === 404) {
            return 'لم يتم العثور على صور للتحميل.';
        }

        return trim($message) !== '' ? trim($message) : 'تعذر تحميل الصور من API الأمين.';
    }

    public static function findInvoiceGuid(?string $typeGuid, ?string $typeName, int $number): ?string
    {
        if ($number <= 0) {
            return null;
        }

        $params = [
            'page' => 1,
            'pageSize' => 5,
            'number' => $number,
        ];
        if ($typeGuid !== null && trim($typeGuid) !== '') {
            $params['typeGuid'] = trim($typeGuid);
        } elseif ($typeName !== null && trim($typeName) !== '') {
            $params['type'] = trim($typeName);
        }

        $result = AccountingApiService::listInvoices($params);
        foreach ($result['items'] as $item) {
            if ((int) ($item['number'] ?? 0) === $number) {
                $guid = trim((string) ($item['guid'] ?? ''));
                if ($guid !== '') {
                    return $guid;
                }
            }
        }

        if (count($result['items']) === 1) {
            $guid = trim((string) ($result['items'][0]['guid'] ?? ''));
            if ($guid !== '') {
                return $guid;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function streamLocalMaterialImagesZip(array $input, string $archiveName): void
    {
        self::prepareZipDownload();

        $entries = self::collectLocalMaterialImageEntries($input);
        if ($entries === []) {
            throw new \RuntimeException('لا توجد صور محلية على الموقع للفلاتر المختارة.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'loczip_');
        if ($zipPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للضغط.');
        }

        try {
            self::buildZipFromFileEntries($zipPath, $entries);
            self::streamLocalZipFile($zipPath, $archiveName);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    public static function streamLocalInvoiceImagesZip(string $billGuid, string $archiveName): void
    {
        self::prepareZipDownload();

        $billGuid = trim($billGuid);
        if ($billGuid === '') {
            throw new \RuntimeException('معرّف الفاتورة مطلوب.');
        }

        $invoice = AccountingApiService::getInvoice($billGuid);
        $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];

        $entries = [];
        $usedNames = [];
        $seenImageGuids = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $materialGuid = trim((string) ($item['materialGuid'] ?? $item['MaterialGuid'] ?? ''));
            if ($materialGuid === '') {
                continue;
            }

            $imageGuid = self::resolveMaterialImageGuid($materialGuid);
            if ($imageGuid === '' || isset($seenImageGuids[$imageGuid])) {
                continue;
            }

            $localPath = MaterialImageStorageService::resolvePathForGuid($imageGuid, false);
            if ($localPath === null || !is_file($localPath)) {
                continue;
            }

            $seenImageGuids[$imageGuid] = true;
            $code = trim((string) ($item['materialCode'] ?? $item['MaterialCode'] ?? ''));
            $name = trim((string) ($item['materialName'] ?? $item['MaterialName'] ?? ''));
            $baseName = $code !== '' ? $code : 'item-' . (count($entries) + 1);
            if ($name !== '') {
                $baseName .= '-' . self::sanitizeFilename($name);
            }
            $extension = pathinfo($localPath, PATHINFO_EXTENSION);
            if ($extension === '') {
                $extension = 'jpg';
            }
            $entries[] = [
                'path' => $localPath,
                'name' => self::uniqueEntryName($baseName . '.' . $extension, $usedNames),
            ];

            if (count($entries) >= self::MAX_LOCAL_ZIP_IMAGES) {
                break;
            }
        }

        if ($entries === []) {
            throw new \RuntimeException('لا توجد صور محلية على الموقع لأصناف هذه الفاتورة.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'invzip_');
        if ($zipPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للضغط.');
        }

        try {
            self::buildZipFromFileEntries($zipPath, $entries);
            self::streamLocalZipFile($zipPath, $archiveName);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    public static function streamLocalLinkedImagesZip(bool $linked, ?string $materialGuid = null): void
    {
        self::prepareZipDownload();

        $linkFilter = $linked ? 'linked' : 'unlinked';
        $materialSearch = $materialGuid !== null ? trim($materialGuid) : '';
        $entries = [];
        $usedNames = [];
        $seenPaths = [];
        $page = 1;

        while ($page <= 50 && count($entries) < self::MAX_LOCAL_ZIP_IMAGES) {
            $pageData = MaterialImageLinkService::listSourcesPage($page, 100, $linkFilter, $materialSearch);
            $items = is_array($pageData['items'] ?? null) ? $pageData['items'] : [];
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $localPath = trim((string) ($item['local_path'] ?? ''));
                if ($localPath === '' || !is_file($localPath)) {
                    $fileName = trim((string) ($item['file_name'] ?? ''));
                    if ($fileName !== '') {
                        $localPath = (string) (MaterialImageStorageService::resolveLocalPath($fileName, false) ?? '');
                    }
                }

                if ($localPath === '' || !is_file($localPath)) {
                    continue;
                }

                $pathKey = strtolower($localPath);
                if (isset($seenPaths[$pathKey])) {
                    continue;
                }
                $seenPaths[$pathKey] = true;

                $code = trim((string) ($item['linked_material_code'] ?? ''));
                $fileName = trim((string) ($item['file_name'] ?? ''));
                $baseName = $code !== '' ? $code : ($fileName !== '' ? pathinfo($fileName, PATHINFO_FILENAME) : 'image');
                $extension = pathinfo($localPath, PATHINFO_EXTENSION);
                if ($extension === '') {
                    $extension = 'jpg';
                }

                $entries[] = [
                    'path' => $localPath,
                    'name' => self::uniqueEntryName(self::sanitizeFilename($baseName) . '.' . $extension, $usedNames),
                ];

                if (count($entries) >= self::MAX_LOCAL_ZIP_IMAGES) {
                    break 2;
                }
            }

            if (!($pageData['has_more'] ?? false)) {
                break;
            }
            $page++;
        }

        if ($entries === []) {
            throw new \RuntimeException('لا توجد صور محلية على الموقع لهذا الاختيار.');
        }

        $archiveName = $linked ? 'material-images-linked' : 'material-images-unlinked';
        $zipPath = tempnam(sys_get_temp_dir(), 'lnkzip_');
        if ($zipPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للضغط.');
        }

        try {
            self::buildZipFromFileEntries($zipPath, $entries);
            self::streamLocalZipFile($zipPath, $archiveName);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{path: string, name: string}>
     */
    public static function collectLocalMaterialImageEntries(array $input): array
    {
        MaterialImageStorageService::ensureSettings();

        $query = self::buildMaterialFilterQuery($input);
        $query['hasImage'] = 'true';

        $entries = [];
        $usedNames = [];
        $seenImageGuids = [];
        $page = 1;
        $pageSize = 100;

        while ($page <= 50 && count($entries) < self::MAX_LOCAL_ZIP_IMAGES) {
            $query['page'] = $page;
            $query['pageSize'] = $pageSize;

            $response = ApiClient::get('/api/materials', $query);
            if (!($response['ok'] ?? false)) {
                if ($entries === []) {
                    throw new \RuntimeException('تعذر جلب قائمة المواد من API (رمز ' . (int) ($response['status'] ?? 0) . ').');
                }
                break;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $imageGuid = strtolower(trim((string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? '')));
                if ($imageGuid === '' || isset($seenImageGuids[$imageGuid])) {
                    continue;
                }

                $localPath = MaterialImageStorageService::resolvePathForGuid($imageGuid, false);
                if ($localPath === null || !is_file($localPath)) {
                    continue;
                }

                $seenImageGuids[$imageGuid] = true;
                $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
                $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
                $baseName = $code !== '' ? $code : 'img-' . (count($entries) + 1);
                if ($name !== '') {
                    $baseName .= '-' . self::sanitizeFilename($name);
                }
                $extension = pathinfo($localPath, PATHINFO_EXTENSION);
                if ($extension === '') {
                    $extension = 'jpg';
                }

                $entries[] = [
                    'path' => $localPath,
                    'name' => self::uniqueEntryName($baseName . '.' . $extension, $usedNames),
                ];

                if (count($entries) >= self::MAX_LOCAL_ZIP_IMAGES) {
                    break 2;
                }
            }

            $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
            if (($page * $pageSize) >= $totalCount) {
                break;
            }
            $page++;
        }

        return $entries;
    }

    private static function prepareZipDownload(): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('لا يمكن بدء التحميل بعد إرسال المخرجات.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    private static function resolveMaterialImageGuid(string $materialGuid): string
    {
        static $cache = [];

        $materialGuid = strtolower(trim($materialGuid));
        if ($materialGuid === '') {
            return '';
        }
        if (isset($cache[$materialGuid])) {
            return $cache[$materialGuid];
        }

        $imageGuid = '';
        try {
            $response = ApiClient::get('/api/materials/' . rawurlencode($materialGuid));
            if ($response['ok'] ?? false) {
                $data = is_array($response['data'] ?? null) ? $response['data'] : [];
                $imageGuid = strtolower(trim((string) ($data['productImageGuid'] ?? $data['ProductImageGuid'] ?? '')));
            }
        } catch (\Throwable) {
            $imageGuid = '';
        }

        $cache[$materialGuid] = $imageGuid;

        return $imageGuid;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function streamOrderImagesZip(array $order, array $items, string $archiveName): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('لا يمكن بدء التحميل بعد إرسال المخرجات.');
        }

        $activeItems = array_values(array_filter($items, static function (array $item): bool {
            return (string) ($item['item_status'] ?? $item['status'] ?? 'active') !== 'cancelled';
        }));

        if ($activeItems === []) {
            throw new \RuntimeException('لا توجد أصناف نشطة في هذا الطلب.');
        }
        if (count($activeItems) > self::MAX_ORDER_IMAGES) {
            throw new \RuntimeException('عدد صور الطلب كبير جداً (' . count($activeItems) . '). الحد الأقصى ' . self::MAX_ORDER_IMAGES . '.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'ordzip_');
        if ($zipPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للضغط.');
        }

        $usedNames = [];
        $added = 0;
        $tempFiles = [];
        $archiveEntries = [];

        try {
            foreach ($activeItems as $item) {
                $materialCode = trim((string) ($item['material_code'] ?? 'item'));
                $materialName = trim((string) ($item['material_name_ar'] ?? ''));
                $imageGuid = self::resolveImageGuid($item);
                if ($imageGuid === '') {
                    continue;
                }

                $tempImage = self::downloadImageToTemp($imageGuid);
                if ($tempImage === null) {
                    continue;
                }
                $tempFiles[] = $tempImage;

                $baseName = $materialCode !== '' ? $materialCode : 'item-' . ($added + 1);
                if ($materialName !== '') {
                    $baseName .= '-' . self::sanitizeFilename($materialName);
                }
                $extension = pathinfo($tempImage, PATHINFO_EXTENSION);
                if ($extension === '') {
                    $extension = 'jpg';
                }
                $entryName = self::uniqueEntryName($baseName . '.' . $extension, $usedNames);
                $archiveEntries[] = ['path' => $tempImage, 'name' => $entryName];
                $added++;
            }

            if ($added === 0) {
                throw new \RuntimeException('لم يتم العثور على صور محلية قابلة للتحميل لهذا الطلب.');
            }

            self::buildZipFromFileEntries($zipPath, $archiveEntries);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            self::streamLocalZipFile($zipPath, $archiveName);
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /** @param array<string, mixed> $item */
    private static function resolveImageGuid(array $item): string
    {
        $imageUrl = trim((string) ($item['image_url'] ?? ''));
        if ($imageUrl !== '' && preg_match('/[?&]id=([0-9a-fA-F-]{36})/', $imageUrl, $matches) === 1) {
            return strtolower($matches[1]);
        }

        $materialGuid = trim((string) ($item['material_guid'] ?? ''));
        if ($materialGuid === '') {
            return '';
        }

        try {
            $response = ApiClient::get('/api/materials/' . rawurlencode($materialGuid) . '/images');
            if (!($response['ok'] ?? false)) {
                return '';
            }
            $images = is_array($response['data']) ? $response['data'] : [];
            $first = $images[0] ?? null;
            if (!is_array($first)) {
                return '';
            }

            return strtolower(trim((string) ($first['id'] ?? $first['guid'] ?? '')));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function downloadImageToTemp(string $imageGuid): ?string
    {
        MaterialImageStorageService::ensureSettings();
        $localPath = MaterialImageStorageService::resolvePathForGuid($imageGuid, false);
        if ($localPath === null || !is_file($localPath)) {
            return null;
        }

        $tempCopy = tempnam(sys_get_temp_dir(), 'ordimg_');
        if ($tempCopy === false) {
            return null;
        }

        $extension = pathinfo($localPath, PATHINFO_EXTENSION);
        $target = $extension !== '' ? $tempCopy . '.' . $extension : $tempCopy . '.jpg';
        if (@copy($localPath, $target)) {
            @unlink($tempCopy);

            return $target;
        }

        @unlink($tempCopy);

        return null;
    }

  /**
     * @param array<string, bool> $usedNames
     */
    private static function uniqueEntryName(string $fileName, array &$usedNames): string
    {
        $candidate = self::sanitizeFilename($fileName);
        if ($candidate === '') {
            $candidate = 'image.jpg';
        }
        $base = pathinfo($candidate, PATHINFO_FILENAME);
        $extension = pathinfo($candidate, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . $extension : '';
        $index = 1;
        $final = $candidate;
        while (isset($usedNames[strtolower($final)])) {
            $final = $base . '_' . $index . $suffix;
            $index++;
        }
        $usedNames[strtolower($final)] = true;

        return $final;
    }

    public static function sanitizeFilename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'images';
        }
        $value = preg_replace('/[^\p{L}\p{N}\-_.]+/u', '-', $value) ?? 'images';
        $value = trim($value, '-_.');
        if ($value === '') {
            return 'images';
        }

        return Utf8Text::substr($value, 0, 80);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, scalar>
     */
    public static function buildMaterialFilterQuery(array $input): array
    {
        /** @var array<string, scalar> $query */
        $query = [];

        $search = trim((string) ($input['search'] ?? ''));
        if ($search !== '') {
            $query['search'] = $search;
        }

        $scalarKeys = [
            'minWarehouseQuantity' => ['minWarehouseQuantity'],
            'maxWarehouseQuantity' => ['maxWarehouseQuantity'],
        ];
        foreach ($scalarKeys as $apiKey => $sourceKeys) {
            foreach ($sourceKeys as $sourceKey) {
                if (!isset($input[$sourceKey])) {
                    continue;
                }
                $text = trim((string) $input[$sourceKey]);
                if ($text !== '') {
                    $query[$apiKey] = is_numeric($text) ? (float) $text : $text;
                }
                break;
            }
        }

        if (isset($input['isAvailable'])) {
            $availability = trim((string) $input['isAvailable']);
            if ($availability === '1' || strtolower($availability) === 'true') {
                $query['isAvailable'] = 'true';
            } elseif ($availability === '0' || strtolower($availability) === 'false') {
                $query['isAvailable'] = 'false';
            }
        }

        $multiMap = [
            'materialTypes' => ['materialTypes', 'materialType'],
            'ageCategories' => ['ageCategories', 'ageCategory'],
            'manufacturers' => ['manufacturers', 'manufacturer'],
            'sizeRanges' => ['sizeRanges', 'sizeRange'],
            'countryOfOrigins' => ['countryOfOrigins', 'countryOfOrigin'],
            'storeGuids' => ['storeGuids', 'storeGuid'],
            'groupGuids' => ['groupGuids', 'groupGuid'],
        ];

        foreach ($multiMap as $apiKey => $sourceKeys) {
            $values = [];
            foreach ($sourceKeys as $sourceKey) {
                if (!isset($input[$sourceKey])) {
                    continue;
                }
                $raw = $input[$sourceKey];
                if (is_array($raw)) {
                    foreach ($raw as $item) {
                        $text = trim((string) $item);
                        if ($text !== '') {
                            $values[] = $text;
                        }
                    }
                } else {
                    foreach (explode(',', (string) $raw) as $item) {
                        $text = trim($item);
                        if ($text !== '') {
                            $values[] = $text;
                        }
                    }
                }
            }
            $values = array_values(array_unique($values));
            if ($values !== []) {
                $query[$apiKey] = implode(',', $values);
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys
     * @return list<string>
     */
    private static function extractFilterValues(array $input, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (!isset($input[$key])) {
                continue;
            }
            $raw = $input[$key];
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $values[] = $text;
                    }
                }
            } else {
                foreach (explode(',', (string) $raw) as $item) {
                    $text = trim($item);
                    if ($text !== '') {
                        $values[] = $text;
                    }
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<array{path: string, name: string}> $entries
     */
    private static function buildZipFromFileEntries(string $outputPath, array $entries): void
    {
        if ($entries === []) {
            throw new \RuntimeException('لا توجد ملفات لإضافتها إلى ZIP.');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('تعذر إنشاء أرشيف ZIP.');
            }
            foreach ($entries as $entry) {
                $zip->addFile($entry['path'], $entry['name']);
            }
            if ($zip->close() !== true) {
                throw new \RuntimeException('تعذر إنهاء أرشيف ZIP.');
            }

            return;
        }

        $writer = new SimpleZipWriter();
        $writer->open($outputPath);
        foreach ($entries as $entry) {
            $writer->addFileFromPath($entry['path'], $entry['name']);
        }
        $writer->close();
    }
}
