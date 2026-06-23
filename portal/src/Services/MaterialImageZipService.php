<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\Utf8Text;

final class MaterialImageZipService
{
    public const MAX_ORDER_IMAGES = 200;

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

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::sanitizeFilename($fallbackFilename) . '.zip"');

        $result = ApiClient::streamGet($apiPath, $query, 900);
        if (!($result['ok'] ?? false) && !str_contains((string) ($result['contentType'] ?? ''), 'application/zip')) {
            if (!headers_sent()) {
                http_response_code((int) ($result['status'] ?? 502));
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'message' => (string) ($result['error'] ?? 'تعذر تحميل الصور من API الأمين.'),
                ], JSON_UNESCAPED_UNICODE);
            }
        }
    }

    public static function findInvoiceGuid(?string $typeGuid, ?string $typeName, int $number): ?string
    {
        if ($number <= 0) {
            return null;
        }

        $params = [
            'page' => 1,
            'pageSize' => 30,
            'keyword' => (string) $number,
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
}
