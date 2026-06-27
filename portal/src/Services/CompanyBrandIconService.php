<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;

final class CompanyBrandIconService
{
    /** @var list<int> */
    private const SIZES = [32, 180, 192, 512];

    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

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
        self::$lastError = null;
        $logoUrl = trim((string) $logoUrl);
        if ($logoUrl === '') {
            self::clearBrandIcons();

            return true;
        }

        $sourcePath = self::resolveSourcePath($logoUrl);
        if ($sourcePath === null || !is_file($sourcePath)) {
            self::$lastError = 'ملف الشعار غير موجود على القرص. أعد رفع الشعار من لوحة التحكم > مكتبة الوسائط.';

            return false;
        }

        $iconSourcePath = self::iconSourcePath($sourcePath);

        if (!is_readable($iconSourcePath)) {
            self::$lastError = 'لا يمكن قراءة ملف الشعار. تحقق من صلاحيات مجلد storage.';

            return false;
        }

        if (self::isSvgPath($iconSourcePath)) {
            self::$lastError ??= 'تعذر تحويل SVG إلى PNG. ثبّت ImageMagick على الخادم، أو ارفع الشعار بصيغة PNG/JPG.';

            return false;
        }

        $mime = self::detectMime($iconSourcePath);

        if (!function_exists('imagecreatetruecolor')) {
            self::$lastError = 'امتداد PHP GD غير مفعّل. فعّل extension=gd في php.ini ثم أعد المحاولة.';

            return false;
        }

        $brandingDir = self::brandingDir();
        if (!is_dir($brandingDir) && !mkdir($brandingDir, 0775, true) && !is_dir($brandingDir)) {
            self::$lastError = 'تعذر إنشاء مجلد التخزين: ' . $brandingDir;

            return false;
        }

        if (!is_writable($brandingDir)) {
            self::$lastError = 'مجلد branding غير قابل للكتابة: ' . $brandingDir
                . ' — نفّذ: icacls D:\\JawishPortal\\storage /grant "IIS AppPool\\JawishPortal:(OI)(CI)M" /T';

            return false;
        }

        $ok = true;
        foreach (self::SIZES as $size) {
            if (!self::generateSquarePng($iconSourcePath, self::iconAbsolutePath($size), $size)) {
                $ok = false;
            }
        }

        if (!$ok) {
            $detail = self::$lastError ?: ('تعذر تحويل الشعار إلى PNG (الصيغة: ' . $mime . ').');
            self::$lastError = $detail;
        }

        return $ok;
    }

    public static function regenerateFromLogoUrlSafe(?string $logoUrl): bool
    {
        try {
            return self::regenerateFromLogoUrl($logoUrl);
        } catch (\Throwable $exception) {
            self::$lastError = 'تعذر توليد أيقونات التطبيق: ' . $exception->getMessage();

            return false;
        }
    }

    /** @return array<string, mixed> */
    public static function diagnose(?string $logoUrl = null): array
    {
        $logoUrl = trim((string) ($logoUrl ?? ''));
        $sourcePath = $logoUrl !== '' ? self::resolveSourcePath($logoUrl) : null;

        return [
            'logo_url' => $logoUrl,
            'source_path' => $sourcePath,
            'source_exists' => $sourcePath !== null && is_file($sourcePath),
            'source_readable' => $sourcePath !== null && is_readable($sourcePath),
            'source_mime' => $sourcePath !== null && is_file($sourcePath) ? self::detectMime($sourcePath) : null,
            'storage_path' => Config::storagePath(),
            'branding_dir' => self::brandingDir(),
            'branding_writable' => is_dir(self::brandingDir()) ? is_writable(self::brandingDir()) : is_writable(dirname(self::brandingDir())),
            'gd_loaded' => extension_loaded('gd'),
            'imagick_loaded' => extension_loaded('imagick'),
            'svg_raster_error' => SvgRasterService::lastError(),
            'has_brand_icons' => self::hasBrandIcons(),
            'last_error' => self::$lastError,
        ];
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

    private static function iconSourcePath(string $sourcePath): string
    {
        if (!self::isSvgPath($sourcePath)) {
            return $sourcePath;
        }

        SiteMediaService::rasterizeSvgCompanionSafe($sourcePath);

        $raster = SvgRasterService::rasterCompanionPath($sourcePath);
        if (is_file($raster) && is_readable($raster) && filesize($raster) > 128) {
            return $raster;
        }

        if (SvgRasterService::toPngFile($sourcePath, $raster, 1024) && is_file($raster) && filesize($raster) > 128) {
            return $raster;
        }

        if (is_file($raster)) {
            @unlink($raster);
        }

        $detail = SvgRasterService::lastError();
        self::$lastError = is_string($detail) && trim($detail) !== ''
            ? $detail
            : 'تعذر تحويل SVG. ثبّت ImageMagick على الخادم، أو ارفع الشعار بصيغة PNG/JPG.';

        return $sourcePath;
    }

    private static function isSvgPath(string $sourcePath): bool
    {
        if (str_ends_with(strtolower($sourcePath), '.svg')) {
            return true;
        }

        $mime = self::normalizeMime(self::detectMime($sourcePath));

        return in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml'], true);
    }

    /** @return \GdImage|false */
    private static function loadImage(string $sourcePath)
    {
        $mime = self::normalizeMime(self::detectMime($sourcePath));

        if ($mime === 'image/svg+xml') {
            $gd = SvgRasterService::toGdImage($sourcePath, 1024);
            if ($gd === false && SvgRasterService::lastError() !== null) {
                self::$lastError = SvgRasterService::lastError();
            }

            return $gd;
        }

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => self::loadPngImage($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
    }

    /** @return \GdImage|false */
    private static function loadPngImage(string $sourcePath)
    {
        $image = @imagecreatefrompng($sourcePath);
        if ($image === false) {
            return false;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match ($mime) {
            'image/pjpeg', 'image/jpg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            default => $mime,
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
            'svg' => 'image/svg+xml',
            'xml' => 'image/svg+xml',
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

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
        imagealphablending($canvas, true);

        $padding = (int) round($size * 0.1);
        $maxBox = $size - ($padding * 2);
        $scale = min($maxBox / $srcW, $maxBox / $srcH);
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));
        $dstX = (int) round(($size - $dstW) / 2);
        $dstY = (int) round(($size - $dstH) / 2);

        imagealphablending($source, true);
        imagesavealpha($source, true);
        imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);

        imagesavealpha($canvas, true);
        $saved = imagepng($canvas, $targetPath);
        imagedestroy($canvas);

        return (bool) $saved;
    }
}
