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
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool}
     */
    public static function uploadSingle(array $file): array
    {
        $settings = self::settings();
        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلدات التخزين.'];
        }

        return self::uploadOne($file, $settings['images_dir'], $settings['thumbnails_dir']);
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

            if ($result['replaced']) {
                $replaced[] = $result['file_name'];
            } else {
                $uploaded[] = $result['file_name'];
            }
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

    public static function publicUrl(string $fileName, bool $thumb = true): string
    {
        return '/media/material.php?file=' . rawurlencode(self::sanitizeFileName($fileName))
            . ($thumb ? '&thumb=1' : '&thumb=0');
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

        foreach (self::fileNamesFromAmineApi($imageGuid) as $fileName) {
            $path = self::resolveLocalPath($fileName, $thumb);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /** @return array{local_count: int, thumbnail_count: int} */
    public static function stats(): array
    {
        $files = self::listLocalFiles();
        $withThumb = 0;
        foreach ($files as $file) {
            if (!empty($file['has_thumbnail'])) {
                $withThumb++;
            }
        }

        return [
            'local_count' => count($files),
            'thumbnail_count' => $withThumb,
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

        $fileName = self::sanitizeFileName((string) ($file['name'] ?? ''));
        if ($fileName === '' || !self::isAllowedFileName($fileName)) {
            return ['ok' => false, 'message' => 'اسم الملف أو الامتداد غير مدعوم.'];
        }

        $targetPath = self::safeJoin($imagesDir, $fileName);
        $thumbPath = self::safeJoin($thumbnailsDir, $fileName);
        if ($targetPath === null || $thumbPath === null) {
            return ['ok' => false, 'message' => 'مسار الملف غير آمن.'];
        }

        $replaced = is_file($targetPath);
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            return ['ok' => false, 'message' => 'تعذر حفظ الملف.'];
        }

        if (!self::generateThumbnail($targetPath, $thumbPath)) {
            @copy($targetPath, $thumbPath);
        }

        return ['ok' => true, 'message' => 'تم', 'file_name' => $fileName, 'replaced' => $replaced];
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
}
