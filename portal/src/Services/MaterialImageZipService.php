<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\Utf8Text;

final class MaterialImageZipService
{
    public const MAX_ORDER_IMAGES = 200;
    public const MAX_SPLIT_PACKAGES = 40;

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
        $baseQuery = self::buildMaterialFilterQuery($baseInput);

        $masterPath = tempnam(sys_get_temp_dir(), 'splitzip_');
        if ($masterPath === false) {
            throw new \RuntimeException('تعذر إنشاء ملف مؤقت للتقسيم.');
        }

        $childPaths = [];
        $zip = new \ZipArchive();
        if ($zip->open($masterPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($masterPath);
            throw new \RuntimeException('تعذر إنشاء أرشيف التقسيم.');
        }

        $usedNames = [];
        $added = 0;

        try {
            foreach ($splitValues as $value) {
                $childQuery = $baseQuery;
                $childQuery[$splitBy] = $value;

                $childPath = tempnam(sys_get_temp_dir(), 'childzip_');
                if ($childPath === false) {
                    continue;
                }
                $childPaths[] = $childPath;

                $result = ApiClient::downloadToFile('/api/material-images/download/materials', $childQuery, $childPath, 900);
                if (!($result['ok'] ?? false)) {
                    continue;
                }

                $childSize = filesize($childPath);
                if ($childSize === false || $childSize < 22) {
                    continue;
                }

                $entryName = self::uniqueEntryName(self::sanitizeFilename($value) . '.zip', $usedNames);
                $zip->addFile($childPath, $entryName);
                $added++;
            }

            $zip->close();

            if ($added === 0) {
                throw new \RuntimeException('لا توجد صور مطابقة لخيارات التقسيم المحددة.');
            }

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
            return 'لا توجد صور مرتبطة بأصناف هذه الفاتورة أو المادة.';
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

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new \RuntimeException('تعذر فتح أرشيف ZIP.');
        }

        $usedNames = [];
        $added = 0;
        $tempFiles = [];

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
                $zip->addFile($tempImage, $entryName);
                $added++;
            }

            $zip->close();

            if ($added === 0) {
                @unlink($zipPath);
                throw new \RuntimeException('لم يتم العثور على صور قابلة للتحميل لهذا الطلب.');
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . self::sanitizeFilename($archiveName) . '.zip"');
            header('Content-Length: ' . (string) filesize($zipPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Accel-Buffering: no');

            $handle = fopen($zipPath, 'rb');
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
        $localPath = self::resolveLocalImagePath($imageGuid);
        if ($localPath !== null) {
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
        }

        $download = ApiClient::getBinary('/api/material-images/' . rawurlencode($imageGuid) . '/file');
        if (!($download['ok'] ?? false) || !is_string($download['body'] ?? null) || $download['body'] === '') {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'ordimg_');
        if ($tempFile === false) {
            return null;
        }

        $contentType = strtolower((string) ($download['contentType'] ?? ''));
        $extension = 'jpg';
        if (str_contains($contentType, 'png')) {
            $extension = 'png';
        } elseif (str_contains($contentType, 'webp')) {
            $extension = 'webp';
        } elseif (str_contains($contentType, 'gif')) {
            $extension = 'gif';
        }

        $target = $tempFile . '.' . $extension;
        if (file_put_contents($target, $download['body']) === false) {
            @unlink($tempFile);

            return null;
        }
        @unlink($tempFile);

        return $target;
    }

    private static function resolveLocalImagePath(string $imageGuid): ?string
    {
        try {
            MaterialImageStorageService::ensureSettings();
            $settings = MaterialImageStorageService::settings();
            $imagesDir = rtrim((string) ($settings['images_dir'] ?? ''), '/\\');
            if ($imagesDir === '' || !is_dir($imagesDir)) {
                return null;
            }

            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));
            if (!($response['ok'] ?? false)) {
                return null;
            }
            $data = is_array($response['data']) ? $response['data'] : [];
            $fileName = trim((string) ($data['fileName'] ?? $data['name'] ?? ''));
            if ($fileName === '') {
                return null;
            }
            $candidate = $imagesDir . DIRECTORY_SEPARATOR . basename($fileName);

            return is_file($candidate) ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
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
}
