<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;
use Throwable;

final class SiteMediaService
{
    /** @var list<string> */
    public const CATEGORIES = ['banner', 'ad', 'logo', 'other'];

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        'banner' => 'بانر',
        'ad' => 'إعلان',
        'logo' => 'شعار',
        'other' => 'أخرى',
    ];

    private const MAX_BYTES = 5_242_880; // 5 MB

    /** @var list<string> */
    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/svg+xml',
    ];

    public static function publicUrl(string $id): string
    {
        return '/media/site.php?id=' . rawurlencode(trim($id));
    }

    /** @return list<array<string, mixed>> */
    public static function listAssets(?string $category = null): array
    {
        $category = self::normalizeCategory($category);
        $sql = 'SELECT
                    id::text AS id,
                    title_ar,
                    category::text AS category,
                    file_name,
                    mime_type,
                    file_size_bytes,
                    created_at::text AS created_at
                FROM site_media_assets';
        $params = [];
        if ($category !== null) {
            $sql .= ' WHERE category = :category';
            $params['category'] = $category;
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['url'] = self::publicUrl((string) ($row['id'] ?? ''));
            $row['category_label'] = self::CATEGORY_LABELS[(string) ($row['category'] ?? '')] ?? (string) ($row['category'] ?? '');
        }
        unset($row);

        return $rows;
    }

    public static function getById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                title_ar,
                category::text AS category,
                file_name,
                storage_path,
                mime_type,
                file_size_bytes,
                created_at::text AS created_at
             FROM site_media_assets
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row['url'] = self::publicUrl((string) $row['id']);

        return $row;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok: bool, message: string, asset?: array<string, mixed>}
     */
    public static function upload(array $file, string $category, ?string $titleAr, ?string $userId): array
    {
        $category = self::normalizeCategory($category) ?? 'banner';
        $titleAr = trim((string) $titleAr);
        $userId = $userId !== null && trim($userId) !== '' ? trim($userId) : null;

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorMessage($error)];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'ملف الرفع غير صالح.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'حجم الملف يجب أن يكون أقل من 5 ميجابايت.'];
        }

        $mime = self::detectMime($tmpPath, (string) ($file['type'] ?? ''));
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return ['ok' => false, 'message' => 'نوع الملف غير مدعوم. استخدم JPG أو PNG أو WebP أو GIF أو SVG.'];
        }

        $originalName = trim((string) ($file['name'] ?? 'image'));
        $extension = self::extensionForMime($mime, $originalName);
        $storageDir = self::storageDir();
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلد التخزين.'];
        }

        $id = self::generateUuid();
        $storedName = $id . '.' . $extension;
        $absolutePath = $storageDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpPath, $absolutePath)) {
            return ['ok' => false, 'message' => 'تعذر حفظ الملف على الخادم.'];
        }

        $relativePath = 'site-media/' . $storedName;
        $stmt = Database::pdo()->prepare(
            'INSERT INTO site_media_assets (
                id, title_ar, category, file_name, storage_path, mime_type, file_size_bytes, uploaded_by_web_user_id
             ) VALUES (
                :id, :title_ar, :category, :file_name, :storage_path, :mime_type, :file_size_bytes, :uploaded_by_web_user_id
             )'
        );
        $stmt->execute([
            'id' => $id,
            'title_ar' => $titleAr !== '' ? $titleAr : null,
            'category' => $category,
            'file_name' => $originalName !== '' ? $originalName : $storedName,
            'storage_path' => $relativePath,
            'mime_type' => $mime,
            'file_size_bytes' => $size,
            'uploaded_by_web_user_id' => $userId,
        ]);

        $asset = self::getById($id);

        return [
            'ok' => true,
            'message' => 'تم رفع الصورة.',
            'asset' => $asset ?? ['id' => $id, 'url' => self::publicUrl($id)],
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(string $id): array
    {
        $asset = self::getById($id);
        if ($asset === null) {
            return ['ok' => false, 'message' => 'الصورة غير موجودة.'];
        }

        $absolutePath = self::absolutePath((string) ($asset['storage_path'] ?? ''));
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $stmt = Database::pdo()->prepare('DELETE FROM site_media_assets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'message' => 'تعذر حذف السجل.'];
        }

        return ['ok' => true, 'message' => 'تم حذف الصورة.'];
    }

    public static function absolutePathForId(string $id): ?string
    {
        $asset = self::getById($id);
        if ($asset === null) {
            return null;
        }

        return self::absolutePath((string) ($asset['storage_path'] ?? ''));
    }

    private static function absolutePath(string $storagePath): ?string
    {
        $storagePath = trim(str_replace('\\', '/', $storagePath), '/');
        if ($storagePath === '' || str_contains($storagePath, '..')) {
            return null;
        }

        $base = rtrim(Config::storagePath(), '/\\');
        $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        $realBase = realpath($base);
        $realFull = realpath($full);
        if ($realBase === false || $realFull === false || !str_starts_with($realFull, $realBase)) {
            return null;
        }

        return $realFull;
    }

    private static function storageDir(): string
    {
        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'site-media';
    }

    private static function normalizeCategory(?string $category): ?string
    {
        $category = trim(strtolower((string) $category));
        if ($category === '') {
            return null;
        }

        return in_array($category, self::CATEGORIES, true) ? $category : null;
    }

    private static function detectMime(string $path, string $fallback): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return strtolower($detected);
                }
            }
        }

        return strtolower(trim($fallback));
    }

    private static function extensionForMime(string $mime, string $originalName): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return 'bin';
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من المسموح.',
            UPLOAD_ERR_PARTIAL => 'اكتمل رفع الملف جزئياً فقط.',
            UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
            default => 'تعذر رفع الملف.',
        };
    }

    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
