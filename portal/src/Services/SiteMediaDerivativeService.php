<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * On-demand resized / WebP derivatives for site media (ads, banners, logos).
 */
final class SiteMediaDerivativeService
{
    /** @return array{path: string, mime: string}|null */
    public static function resolve(string $sourcePath, string $sourceMime, int $maxWidth, string $format): ?array
    {
        $sourcePath = trim($sourcePath);
        if ($sourcePath === '' || !is_readable($sourcePath)) {
            return null;
        }

        $maxWidth = max(0, $maxWidth);
        $format = strtolower(trim($format));
        $wantsWebp = $format === 'webp';
        $wantsResize = $maxWidth > 0;

        if (!$wantsWebp && !$wantsResize) {
            return null;
        }

        if ($wantsWebp && !function_exists('imagewebp')) {
            if (!$wantsResize) {
                return null;
            }
            $wantsWebp = false;
        }

        $sourceMime = strtolower(trim($sourceMime));
        if ($wantsWebp && !in_array($sourceMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            if (!$wantsResize) {
                return null;
            }
            $wantsWebp = false;
        }

        $cacheDir = self::cacheDir();
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $mtime = (string) (@filemtime($sourcePath) ?: 0);
        $size = (string) (@filesize($sourcePath) ?: 0);
        $cacheKey = sha1($sourcePath . '|' . $mtime . '|' . $size . '|' . $maxWidth . '|' . ($wantsWebp ? 'webp' : 'orig'));
        $targetExt = $wantsWebp ? 'webp' : pathinfo($sourcePath, PATHINFO_EXTENSION);
        $targetPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.' . $targetExt;

        if (is_file($targetPath) && is_readable($targetPath) && filesize($targetPath) > 0) {
            return [
                'path' => $targetPath,
                'mime' => $wantsWebp ? 'image/webp' : $sourceMime,
            ];
        }

        $image = self::loadImage($sourcePath, $sourceMime);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return null;
        }

        if ($wantsResize && $width > $maxWidth) {
            $nextHeight = (int) max(1, round($height * ($maxWidth / $width)));
            $resized = imagescale($image, $maxWidth, $nextHeight, IMG_BILINEAR_FIXED);
            imagedestroy($image);
            if ($resized === false) {
                return null;
            }
            $image = $resized;
        }

        $saved = $wantsWebp
            ? imagewebp($image, $targetPath, 82)
            : self::saveOriginalFormat($image, $targetPath, $sourceMime);
        imagedestroy($image);

        if (!$saved || !is_file($targetPath) || filesize($targetPath) <= 0) {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            return null;
        }

        return [
            'path' => $targetPath,
            'mime' => $wantsWebp ? 'image/webp' : $sourceMime,
        ];
    }

    public static function displayUrl(string $publicUrl, int $maxWidth = 0, bool $preferWebp = true): string
    {
        $publicUrl = trim($publicUrl);
        if ($publicUrl === '' || !preg_match('~^/media/site\.php\?~i', $publicUrl)) {
            return $publicUrl;
        }

        $query = (string) (parse_url($publicUrl, PHP_URL_QUERY) ?: '');
        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }

        $existingFormat = strtolower((string) ($params['format'] ?? ''));
        $changed = false;

        if ($maxWidth > 0) {
            $params['w'] = (string) $maxWidth;
            $changed = true;
        }

        if ($preferWebp && !in_array($existingFormat, ['png', 'raster', 'webp'], true)) {
            $params['format'] = 'webp';
            $changed = true;
        }

        if (!$changed) {
            return $publicUrl;
        }

        return '/media/site.php?' . http_build_query($params);
    }

    private static function cacheDir(): string
    {
        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'site-media-derivatives';
    }

    /** @return \GdImage|false */
    private static function loadImage(string $sourcePath, string $sourceMime)
    {
        return match ($sourceMime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
    }

    /** @param \GdImage $image */
    private static function saveOriginalFormat($image, string $targetPath, string $sourceMime): bool
    {
        return match ($sourceMime) {
            'image/jpeg', 'image/jpg' => imagejpeg($image, $targetPath, 85),
            'image/png' => imagepng($image, $targetPath, 6),
            'image/gif' => imagegif($image, $targetPath),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $targetPath, 82) : false,
            default => false,
        };
    }
}
