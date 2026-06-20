<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use Throwable;

final class MaterialImageStorageService
{
    private const MAX_BYTES = 10_485_760; // 10 MB
    private const THUMB_MAX = 300;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @var list<string> */
    private const LISTABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private static bool $settingsReady = false;

    /** @var array<string, list<string>> */
    private static array $fileNameByGuid = [];

    public static function ensureSettings(): void
    {
        if (self::$settingsReady) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO company_settings (key, value_ar)
             VALUES (:key, :value_ar)
             ON CONFLICT (key) DO NOTHING'
        );
        foreach (['material_images_dir', 'material_thumbnails_dir'] as $key) {
            $stmt->execute(['key' => $key, 'value_ar' => '']);
        }

        self::$settingsReady = true;
    }

    /** @return array{images_dir: string, thumbnails_dir: string} */
    public static function settings(): array
    {
        self::ensureSettings();
        $map = PortalSettingsService::companySettings();

        return [
            'images_dir' => self::resolveDirectory((string) ($map['material_images_dir'] ?? ''), 'material-images'),
            'thumbnails_dir' => self::resolveDirectory((string) ($map['material_thumbnails_dir'] ?? ''), 'material-images/thumbnails'),
        ];
    }

    /** @param array{material_images_dir?: string, material_thumbnails_dir?: string} $values */
    public static function saveSettings(array $values, ?string $updatedByUserId): void
    {
        $current = PortalSettingsService::companySettings();
        PortalSettingsService::saveCompanySettings([
            'company_name' => (string) ($current['company_name'] ?? ''),
            'company_phone' => (string) ($current['company_phone'] ?? ''),
            'company_mobile' => (string) ($current['company_mobile'] ?? ''),
            'company_whatsapp' => (string) ($current['company_whatsapp'] ?? ''),
            'company_email' => (string) ($current['company_email'] ?? ''),
            'company_address' => (string) ($current['company_address'] ?? ''),
            'company_logo' => (string) ($current['company_logo'] ?? ''),
            'about_us_title_ar' => (string) ($current['about_us_title_ar'] ?? ''),
            'about_us_ar' => (string) ($current['about_us_ar'] ?? ''),
            'material_images_dir' => trim((string) ($values['material_images_dir'] ?? '')),
            'material_thumbnails_dir' => trim((string) ($values['material_thumbnails_dir'] ?? '')),
        ], $updatedByUserId);
    }

    /**
     * @param array<string, mixed> $file single $_FILES entry
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool, local_path?: string}
     */
    public static function uploadSingle(array $file, ?string $uploadedByUserId = null): array
    {
        $settings = self::settings();
        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلدات التخزين.'];
        }

        $result = self::uploadOne($file, $settings['images_dir'], $settings['thumbnails_dir']);
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        $fileName = (string) ($result['file_name'] ?? '');
        $localPath = self::safeJoin($settings['images_dir'], $fileName) ?? '';
        $thumbPath = self::safeJoin($settings['thumbnails_dir'], $fileName);

        try {
            MaterialImageSyncService::enqueue($fileName, $localPath, $thumbPath, $uploadedByUserId);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'تم حفظ الصورة محلياً لكن تعذر إضافتها لطابور المزامنة: ' . $exception->getMessage(),
                'file_name' => $fileName,
                'replaced' => (bool) ($result['replaced'] ?? false),
                'local_path' => $localPath,
            ];
        }

        return [
            'ok' => true,
            'message' => ($result['renamed'] ?? false)
                ? ('تم حفظ الصورة باسم «' . $fileName . '» (تعارض الاسم) وإضافتها لطابور مزامنة الأمين.')
                : 'تم حفظ الصورة على الموقع وإضافتها لطابور مزامنة الأمين.',
            'file_name' => $fileName,
            'replaced' => false,
            'renamed' => (bool) ($result['renamed'] ?? false),
            'requested_name' => (string) ($result['requested_name'] ?? $fileName),
            'local_path' => $localPath,
        ];
    }

    /**
     * @param list<array<string, mixed>> $files from $_FILES['files']
     * @return array{ok: bool, message: string, uploaded: list<string>, replaced: list<string>, failed: list<string>}
     */
    public static function uploadMany(array $files): array
    {
        $settings = self::settings();
        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return [
                'ok' => false,
                'message' => 'تعذر إنشاء مجلدات تخزين صور المواد. راجع المسارات في الإعدادات.',
                'uploaded' => [],
                'replaced' => [],
                'failed' => [],
            ];
        }

        $uploaded = [];
        $replaced = [];
        $failed = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $result = self::uploadOne($file, $settings['images_dir'], $settings['thumbnails_dir']);
            if (!$result['ok']) {
                $failed[] = ((string) ($file['name'] ?? 'file')) . ': ' . $result['message'];
                continue;
            }

            $uploaded[] = (string) ($result['file_name'] ?? '');
        }

        if ($uploaded === [] && $replaced === [] && $failed !== []) {
            return [
                'ok' => false,
                'message' => 'لم يُرفع أي ملف.',
                'uploaded' => [],
                'replaced' => [],
                'failed' => $failed,
            ];
        }

        $parts = [];
        if ($uploaded !== []) {
            $parts[] = count($uploaded) . ' ملف جديد';
        }
        if ($replaced !== []) {
            $parts[] = count($replaced) . ' ملف مستبدل';
        }
        if ($failed !== []) {
            $parts[] = count($failed) . ' فشل';
        }

        return [
            'ok' => true,
            'message' => 'تم الرفع: ' . implode('، ', $parts) . '.',
            'uploaded' => $uploaded,
            'replaced' => $replaced,
            'failed' => $failed,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listLocalFiles(): array
    {
        $settings = self::settings();
        $dir = $settings['images_dir'];
        if (!is_dir($dir)) {
            return [];
        }

        $rows = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            if (!self::isListableFileName($entry)) {
                continue;
            }

            $thumbPath = self::findFileInDirectory($settings['thumbnails_dir'], $entry);
            $fullPreviewPath = self::findFileInDirectory($settings['images_dir'], $entry);
            $rows[] = [
                'file_name' => $entry,
                'local_path' => $path,
                'size_bytes' => filesize($path) ?: 0,
                'modified_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
                'has_thumbnail' => $thumbPath !== null,
                'is_previewable' => $fullPreviewPath !== null,
                'preview_url' => self::publicUrl($entry, false),
                'preview_thumb_url' => self::publicUrl($entry, true),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['modified_at'], (string) $a['modified_at']));

        return $rows;
    }

    /**
     * @return array{ok: bool, message: string, file_name?: string}
     */
    public static function saveProcessedUpload(string $tmpPath, string $originalName = 'linked.jpg'): ?string
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return null;
        }

        $mime = self::detectMime($tmpPath);
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $settings = self::settings();
        $directory = $settings['images_dir'] . DIRECTORY_SEPARATOR . '_processed';
        if (!self::ensureDirectory($directory)) {
            return null;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $dest = $directory . DIRECTORY_SEPARATOR . ('proc_' . bin2hex(random_bytes(8)) . '.' . $extension);
        if (is_uploaded_file($tmpPath)) {
            if (!@move_uploaded_file($tmpPath, $dest)) {
                return null;
            }
        } elseif (!@copy($tmpPath, $dest)) {
            return null;
        }

        return $dest;
    }

    public static function renderImageWithDetailsBanner(string $sourcePath, string $line1, string $line2): ?string
    {
        if (!is_file($sourcePath) || !function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return null;
        }

        $font = self::resolveDetailsFontPath();
        if ($font === null) {
            return null;
        }

        $line1 = trim($line1);
        $line2 = trim($line2);
        if ($line1 === '' && $line2 === '') {
            return null;
        }

        $image = self::loadGdImage($sourcePath);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return null;
        }

        $fontSize = (int) max(14, min(36, (int) floor($width / 25)));
        $lineHeight = (int) round($fontSize * 1.5);
        $maxWidth = $width - 40;
        $lines1 = self::wrapTtfTextLines($font, (float) $fontSize, $line1, $maxWidth);
        $lines2 = self::wrapTtfTextLines($font, (float) $fontSize, $line2, $maxWidth);
        $allLines = $lines1;
        if ($lines1 !== [] && $lines2 !== []) {
            $allLines[] = '';
        }
        $allLines = array_merge($allLines, $lines2);

        $textLineCount = count(array_filter($allLines, static fn (string $line): bool => $line !== ''));
        $bannerHeight = $textLineCount > 0 ? ($textLineCount * $lineHeight) + 60 : 0;
        if ($bannerHeight <= 0) {
            imagedestroy($image);

            return null;
        }

        $canvas = imagecreatetruecolor($width, $height + $bannerHeight);
        if ($canvas === false) {
            imagedestroy($image);

            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);
        imagefilledrectangle($canvas, 0, $height, $width, $height + $bannerHeight, $white);

        $y = $height + 20 + $fontSize;
        foreach ($allLines as $line) {
            if ($line === '') {
                $y += 10;
                continue;
            }
            $box = imagettfbbox($fontSize, 0, $font, $line) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $textWidth = abs($box[2] - $box[0]);
            $x = max(20, $width - 20 - $textWidth);
            imagettftext($canvas, $fontSize, 0, $x, $y, $black, $font, $line);
            $y += $lineHeight;
        }

        $settings = self::settings();
        $directory = $settings['images_dir'] . DIRECTORY_SEPARATOR . '_processed';
        if (!self::ensureDirectory($directory)) {
            imagedestroy($canvas);

            return null;
        }

        $dest = $directory . DIRECTORY_SEPARATOR . ('detail_' . bin2hex(random_bytes(8)) . '.jpg');
        $saved = imagejpeg($canvas, $dest, 90);
        imagedestroy($canvas);

        return $saved ? $dest : null;
    }

    public static function canRenderDetailsBanner(): bool
    {
        return function_exists('imagettftext')
            && function_exists('imagecreatetruecolor')
            && self::resolveDetailsFontPath() !== null;
    }

    /** @return list<string> */
    private static function wrapTtfTextLines(string $font, float $fontSize, string $text, int $maxWidth): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $box = imagettfbbox($fontSize, 0, $font, $candidate) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $width = abs($box[2] - $box[0]);
            if ($width <= $maxWidth || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private static function resolveDetailsFontPath(): ?string
    {
        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            'C:\\Windows\\Fonts\\tahoma.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\trado.ttf',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return \GdImage|false */
    private static function loadGdImage(string $sourcePath)
    {
        $mime = self::detectMime($sourcePath);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
    }

    public static function deleteTempProcessedFile(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || !str_contains($path, '/_processed/')) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{ok: bool, message: string, file_name?: string}
     */
    public static function copyLocalFromSource(string $sourcePath, string $targetFileName): array
    {
        if (!is_file($sourcePath)) {
            return ['ok' => false, 'message' => 'الصورة المصدر غير موجودة على الموقع.'];
        }

        $settings = self::settings();
        $targetFileName = self::sanitizeFileName($targetFileName);
        if ($targetFileName === '' || !self::isAllowedFileName($targetFileName)) {
            return ['ok' => false, 'message' => 'اسم الملف المستهدف غير صالح.'];
        }

        $targetPath = self::safeJoin($settings['images_dir'], $targetFileName);
        $thumbPath = self::safeJoin($settings['thumbnails_dir'], $targetFileName);
        if ($targetPath === null || $thumbPath === null) {
            return ['ok' => false, 'message' => 'مسار الملف غير آمن.'];
        }

        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلدات التخزين.'];
        }

        if (!@copy($sourcePath, $targetPath)) {
            return ['ok' => false, 'message' => 'تعذر نسخ الصورة محلياً.'];
        }

        if (!self::generateThumbnail($targetPath, $thumbPath)) {
            @copy($targetPath, $thumbPath);
        }

        return ['ok' => true, 'message' => 'تم', 'file_name' => $targetFileName];
    }

    public static function deleteLocalFile(string $fileName): void
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($fileName === '' || str_contains($fileName, '..')) {
            return;
        }

        foreach ([false, true] as $thumb) {
            $path = self::resolveLocalPath($fileName, $thumb);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function publicUrl(string $fileName, bool $thumb = true): string
    {
        return '/media/material.php?file=' . rawurlencode(self::lookupFileName($fileName))
            . ($thumb ? '&thumb=1' : '&thumb=0');
    }

    public static function mimeForPath(string $path): string
    {
        return self::detectMime($path);
    }

    public static function resolveLocalPath(string $fileName, bool $thumb = false): ?string
    {
        $settings = self::settings();
        $directory = $thumb ? $settings['thumbnails_dir'] : $settings['images_dir'];

        foreach (self::fileNameCandidates($fileName) as $candidate) {
            $path = self::findFileInDirectory($directory, $candidate);
            if ($path !== null) {
                return $path;
            }
        }

        if ($thumb) {
            return self::resolveLocalPath($fileName, false);
        }

        return null;
    }

    public static function resolvePathForGuid(string $imageGuid, bool $thumb = false): ?string
    {
        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return null;
        }

        $fromQueue = MaterialImageSyncService::resolveLocalPathByAmineGuid($imageGuid, $thumb);
        if ($fromQueue !== null) {
            return $fromQueue;
        }

        foreach (self::fileNamesFromAmineApi($imageGuid) as $fileName) {
            $path = self::resolveLocalPath($fileName, $thumb);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int|null,
     *   has_more: bool
     * }
     */
    public static function browseMaterials(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(48, (int) ($filters['page_size'] ?? 24)));
        $localStatus = (string) ($filters['local_status'] ?? 'all');
        if (!in_array($localStatus, ['all', 'on_site', 'missing'], true)) {
            $localStatus = 'all';
        }

        $apiQuery = self::buildMaterialsApiQuery($filters);
        if ($localStatus === 'all') {
            $apiQuery['page'] = $page;
            $apiQuery['pageSize'] = $pageSize;

            try {
                $response = ApiClient::get('/api/materials', $apiQuery);
            } catch (Throwable $exception) {
                return self::browseError('تعذر الاتصال بـ API المواد: ' . $exception->getMessage());
            }

            if (!($response['ok'] ?? false)) {
                return self::browseError('تعذر جلب المواد من API (رمز ' . (int) ($response['status'] ?? 0) . ').');
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
            $items = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = self::mapBrowseRow($row);
            }

            return [
                'ok' => true,
                'message' => '',
                'items' => $items,
                'page' => $page,
                'page_size' => $pageSize,
                'total_count' => $totalCount,
                'has_more' => ($page * $pageSize) < $totalCount,
            ];
        }

        return self::browseMaterialsWithLocalFilter($apiQuery, $localStatus, $page, $pageSize);
    }

    /** @return array{local_count: int, thumbnail_count: int} */
    public static function stats(): array
    {
        $settings = self::settings();

        return [
            'local_count' => self::countListableFilesInDirectory($settings['images_dir']),
            'thumbnail_count' => self::countListableFilesInDirectory($settings['thumbnails_dir']),
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool}
     */
    private static function uploadOne(array $file, string $imagesDir, string $thumbnailsDir): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorMessage($error)];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'ملف غير صالح.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'الحجم يجب أن يكون أقل من 10 ميجابايت.'];
        }

        $requestedName = self::sanitizeFileName((string) ($file['name'] ?? ''));
        if ($requestedName === '' || !self::isAllowedFileName($requestedName)) {
            return ['ok' => false, 'message' => 'اسم الملف أو الامتداد غير مدعوم.'];
        }

        $fileName = self::availableFileName($imagesDir, $requestedName);
        $renamed = strcasecmp($fileName, $requestedName) !== 0;

        $targetPath = self::safeJoin($imagesDir, $fileName);
        $thumbPath = self::safeJoin($thumbnailsDir, $fileName);
        if ($targetPath === null || $thumbPath === null) {
            return ['ok' => false, 'message' => 'مسار الملف غير آمن.'];
        }

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            return ['ok' => false, 'message' => 'تعذر حفظ الملف.'];
        }

        if (!self::generateThumbnail($targetPath, $thumbPath)) {
            @copy($targetPath, $thumbPath);
        }

        return [
            'ok' => true,
            'message' => 'تم',
            'file_name' => $fileName,
            'replaced' => false,
            'renamed' => $renamed,
            'requested_name' => $requestedName,
        ];
    }

    public static function renameLocalCopy(string $fromFileName, string $toFileName): bool
    {
        $fromFileName = self::sanitizeFileName($fromFileName);
        $toFileName = self::sanitizeFileName($toFileName);
        if ($fromFileName === '' || $toFileName === '' || strcasecmp($fromFileName, $toFileName) === 0) {
            return true;
        }

        $settings = self::settings();
        $ok = true;
        foreach ([
            [$settings['images_dir'], false],
            [$settings['thumbnails_dir'], true],
        ] as [$directory, $thumb]) {
            $fromPath = self::safeJoin($directory, $fromFileName);
            $toPath = self::safeJoin($directory, $toFileName);
            if ($fromPath === null || $toPath === null || !is_file($fromPath)) {
                continue;
            }
            if (is_file($toPath)) {
                continue;
            }
            if (!@rename($fromPath, $toPath)) {
                $ok = @copy($fromPath, $toPath) && @unlink($fromPath);
            }
        }

        return $ok;
    }

    private static function availableFileName(string $directory, string $fileName): string
    {
        $fileName = self::sanitizeFileName($fileName);
        if ($fileName === '') {
            return $fileName;
        }

        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $candidate = $fileName;
        $counter = 1;

        while (true) {
            $path = self::safeJoin($directory, $candidate);
            if ($path === null || !is_file($path)) {
                return $candidate;
            }

            $suffix = '_' . $counter;
            $candidate = $extension !== ''
                ? $base . $suffix . '.' . $extension
                : $base . $suffix;
            $counter++;
        }
    }

    /** @return list<string> */
    private static function fileNamesFromAmineApi(string $imageGuid): array
    {
        if (isset(self::$fileNameByGuid[$imageGuid])) {
            return self::$fileNameByGuid[$imageGuid];
        }

        try {
            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));
            if (!($response['ok'] ?? false)) {
                return [];
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $candidates = self::fileNameCandidates(
                (string) ($data['storedFileName'] ?? ''),
                (string) ($data['fileName'] ?? ''),
                (string) ($data['imagePath'] ?? ''),
                (string) ($data['thumbnailName'] ?? '')
            );
            if ($candidates === []) {
                return [];
            }

            self::$fileNameByGuid[$imageGuid] = $candidates;

            return $candidates;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private static function fileNameCandidates(string ...$values): array
    {
        $candidates = [];
        foreach ($values as $value) {
            $lookup = self::lookupFileName($value);
            if ($lookup !== '') {
                $candidates[] = $lookup;
            }
            $sanitized = self::sanitizeFileName($value);
            if ($sanitized !== '' && $sanitized !== $lookup) {
                $candidates[] = $sanitized;
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function lookupFileName(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));
        $value = basename($value);
        if ($value === '' || str_contains($value, '..')) {
            return '';
        }

        return $value;
    }

    private static function findFileInDirectory(string $directory, string $fileName): ?string
    {
        $fileName = self::lookupFileName($fileName);
        if ($fileName === '' || !is_dir($directory)) {
            return null;
        }

        $path = self::safeJoin($directory, $fileName);
        if ($path !== null && is_file($path)) {
            return $path;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strcasecmp($entry, $fileName) === 0) {
                $match = self::safeJoin($directory, $entry);
                if ($match !== null && is_file($match)) {
                    return $match;
                }
            }
        }

        return null;
    }

    private static function generateThumbnail(string $sourcePath, string $targetPath): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $mime = self::detectMime($sourcePath);
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return false;
        }

        $ratio = min(self::THUMB_MAX / $width, self::THUMB_MAX / $height, 1.0);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($thumb === false) {
            imagedestroy($image);
            return false;
        }

        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($thumb, $targetPath, 85),
            'image/png' => imagepng($thumb, $targetPath),
            'image/gif' => imagegif($thumb, $targetPath),
            'image/webp' => function_exists('imagewebp') ? imagewebp($thumb, $targetPath, 85) : false,
            default => false,
        };
        imagedestroy($thumb);

        return (bool) $saved;
    }

    private static function sanitizeFileName(string $fileName): string
    {
        $fileName = str_replace('\\', '/', trim($fileName));
        $fileName = basename($fileName);
        $fileName = preg_replace('/[^\w\-. \x{0600}-\x{06FF}]+/u', '_', $fileName) ?? '';

        return trim($fileName);
    }

    private static function isAllowedFileName(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private static function isListableFileName(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::LISTABLE_EXTENSIONS, true);
    }

    private static function thumbnailPath(string $fileName, string $thumbnailsDir): string
    {
        return $thumbnailsDir . DIRECTORY_SEPARATOR . self::sanitizeFileName($fileName);
    }

    private static function resolveDirectory(string $configured, string $defaultRelative): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
        }

        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $defaultRelative);
    }

    private static function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return mkdir($directory, 0775, true) || is_dir($directory);
    }

    private static function safeJoin(string $directory, string $fileName): ?string
    {
        $directory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
        $fileName = self::lookupFileName($fileName);
        if ($directory === '' || $fileName === '') {
            return null;
        }

        $full = $directory . DIRECTORY_SEPARATOR . $fileName;
        $realDir = realpath($directory);
        if ($realDir === false) {
            $realDir = $directory;
        }

        $realFull = realpath($full);
        if ($realFull === false) {
            $realFull = $full;
        }

        if (!str_starts_with(str_replace('\\', '/', $realFull), str_replace('\\', '/', $realDir))) {
            return null;
        }

        return $full;
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من المسموح.',
            UPLOAD_ERR_PARTIAL => 'رفع جزئي فقط.',
            UPLOAD_ERR_NO_FILE => 'لم يُرفع ملف.',
            default => 'فشل رفع الملف.',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|string|bool|null>
     */
    private static function buildMaterialsApiQuery(array $filters): array
    {
        $query = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query['search'] = $search;
        }

        foreach ([
            'material_types' => 'materialTypes',
            'age_categories' => 'ageCategories',
            'manufacturers' => 'manufacturers',
            'size_ranges' => 'sizeRanges',
            'country_origins' => 'countryOfOrigins',
            'store_guids' => 'storeGuids',
            'group_guids' => 'groupGuids',
        ] as $inputKey => $apiKey) {
            $values = self::normalizeFilterValues($filters[$inputKey] ?? null);
            if ($values !== []) {
                $query[$apiKey] = implode(',', $values);
            }
        }

        $hasImage = $filters['has_image'] ?? true;
        if ($hasImage === true || $hasImage === '1' || $hasImage === 1 || $hasImage === 'true') {
            $query['hasImage'] = 'true';
        } elseif ($hasImage === false || $hasImage === '0' || $hasImage === 0 || $hasImage === 'false') {
            $query['hasImage'] = 'false';
        }

        $isAvailable = $filters['is_available'] ?? null;
        if ($isAvailable === true || $isAvailable === '1' || $isAvailable === 1 || $isAvailable === 'true') {
            $query['isAvailable'] = 'true';
        } elseif ($isAvailable === false || $isAvailable === '0' || $isAvailable === 0 || $isAvailable === 'false') {
            $query['isAvailable'] = 'false';
        }

        return $query;
    }

    /**
     * @param array<string, int|string|bool|null> $apiQuery
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int|null,
     *   has_more: bool
     * }
     */
    private static function browseMaterialsWithLocalFilter(
        array $apiQuery,
        string $localStatus,
        int $page,
        int $pageSize
    ): array {
        $skip = ($page - 1) * $pageSize;
        $collected = [];
        $matchedIndex = 0;
        $apiPage = 1;
        $apiPageSize = 50;
        $hasMoreApi = true;
        $hasMore = false;

        while ($hasMoreApi && count($collected) < $pageSize && $apiPage <= 80) {
            $apiQuery['page'] = $apiPage;
            $apiQuery['pageSize'] = $apiPageSize;

            try {
                $response = ApiClient::get('/api/materials', $apiQuery);
            } catch (Throwable $exception) {
                return self::browseError('تعذر الاتصال بـ API المواد: ' . $exception->getMessage());
            }

            if (!($response['ok'] ?? false)) {
                return self::browseError('تعذر جلب المواد من API (رمز ' . (int) ($response['status'] ?? 0) . ').');
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
            $hasMoreApi = ($apiPage * $apiPageSize) < $totalCount;

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $mapped = self::mapBrowseRow($row);
                if (!self::matchesLocalStatus($mapped, $localStatus)) {
                    continue;
                }

                if ($matchedIndex >= $skip && count($collected) < $pageSize) {
                    $collected[] = $mapped;
                }
                $matchedIndex++;

                if (count($collected) === $pageSize) {
                    $hasMore = true;
                    break 2;
                }
            }

            $apiPage++;
        }

        return [
            'ok' => true,
            'message' => '',
            'items' => $collected,
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => null,
            'has_more' => $hasMore,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function mapBrowseRow(array $row): array
    {
        $materialGuid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? ''));
        $imageGuid = trim((string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? ''));
        $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
        $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
        $storedFileName = '';

        $localPath = null;
        if ($imageGuid !== '') {
            $localPath = self::resolvePathForGuid($imageGuid, false);
            $candidates = self::$fileNameByGuid[$imageGuid] ?? [];
            if ($candidates !== []) {
                $storedFileName = (string) $candidates[0];
            }
        }

        return [
            'material_guid' => $materialGuid,
            'image_guid' => $imageGuid,
            'name' => $name,
            'material_code' => $code,
            'material_type' => trim((string) ($row['materialType'] ?? $row['MaterialType'] ?? '')),
            'manufacturer' => trim((string) ($row['manufacturer'] ?? $row['Manufacturer'] ?? '')),
            'age_category' => trim((string) ($row['ageCategory'] ?? $row['AgeCategory'] ?? '')),
            'has_local' => $localPath !== null,
            'stored_file_name' => $storedFileName,
            'preview_url' => $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '',
            'local_preview_url' => $storedFileName !== '' ? self::publicUrl($storedFileName, true) : '',
        ];
    }

    /** @param array<string, mixed> $row */
    private static function matchesLocalStatus(array $row, string $localStatus): bool
    {
        return match ($localStatus) {
            'on_site' => !empty($row['has_local']),
            'missing' => !empty($row['image_guid']) && empty($row['has_local']),
            default => true,
        };
    }

    /** @return list<string> */
    private static function normalizeFilterValues(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[,|\n]+/u', $value) ?: [];
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            return [];
        }

        $normalized = [];
        foreach ($parts as $part) {
            $item = trim((string) $part);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function countListableFilesInDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && self::isListableFileName($entry)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int|null,
     *   has_more: bool
     * }
     */
    private static function browseError(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'items' => [],
            'page' => 1,
            'page_size' => 24,
            'total_count' => 0,
            'has_more' => false,
        ];
    }
}
