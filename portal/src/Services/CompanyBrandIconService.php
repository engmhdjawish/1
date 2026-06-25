<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;

final class CompanyBrandIconService
{
    /** @var list<int> */
    private const SIZES = [32, 180, 192, 512];

    public static function brandingDir(): string
    {
        $dir = rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'branding';

        return $dir;
    }

    public static function iconFileName(int $size): string
    {
        return 'brand-' . $size . '.png';
    }

    public static function iconAbsolutePath(int $size): string
    {
        return self::brandingDir() . DIRECTORY_SEPARATOR . self::iconFileName($size);
    }

    public static function iconPublicUrl(int $size): string
    {
        return '/icons/brand-icon.php?size=' . $size;
    }

    public static function hasBrandIcons(): bool
    {
        return is_file(self::iconAbsolutePath(192)) && is_file(self::iconAbsolutePath(512));
    }

    public static function regenerateFromLogoUrl(?string $logoUrl): bool
    {
        $logoUrl = trim((string) $logoUrl);
        if ($logoUrl === '') {
            self::clearBrandIcons();

            return true;
        }

        $sourcePath = self::resolveSourcePath($logoUrl);
        if ($sourcePath === null || !is_file($sourcePath)) {
            return false;
        }

        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        if (!is_dir(self::brandingDir()) && !mkdir(self::brandingDir(), 0775, true) && !is_dir(self::brandingDir())) {
            return false;
        }

        $ok = true;
        foreach (self::SIZES as $size) {
            if (!self::generateSquarePng($sourcePath, self::iconAbsolutePath($size), $size)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public static function clearBrandIcons(): void
    {
        foreach (self::SIZES as $size) {
            $path = self::iconAbsolutePath($size);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private static function resolveSourcePath(string $logoUrl): ?string
    {
        if (preg_match('~^/media/site\.php\?id=([^&]+)~i', $logoUrl, $matches) === 1) {
            $id = rawurldecode((string) ($matches[1] ?? ''));

            return SiteMediaService::absolutePathForId($id);
        }

        if (str_starts_with($logoUrl, 'http://') || str_starts_with($logoUrl, 'https://')) {
            return null;
        }

        if (str_starts_with($logoUrl, '/')) {
            $publicRoot = dirname(__DIR__, 2) . '/public';
            $candidate = $publicRoot . $logoUrl;
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }

    private static function loadImage(string $sourcePath): \GdImage|false
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

    private static function detectMime(string $path): string
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

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private static function generateSquarePng(string $sourcePath, string $targetPath, int $size): bool
    {
        $source = self::loadImage($sourcePath);
        if ($source === false) {
            return false;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($source);

            return false;
        }

        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            imagedestroy($source);

            return false;
        }

        $bg = imagecolorallocate($canvas, 246, 246, 248);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);

        $padding = (int) round($size * 0.1);
        $maxBox = $size - ($padding * 2);
        $scale = min($maxBox / $srcW, $maxBox / $srcH);
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));
        $dstX = (int) round(($size - $dstW) / 2);
        $dstY = (int) round(($size - $dstH) / 2);

        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);

        $saved = imagepng($canvas, $targetPath);
        imagedestroy($canvas);

        return (bool) $saved;
    }
}
