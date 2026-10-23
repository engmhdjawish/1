<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;

/**
 * Link-preview images (og:image) at 1200×630 — the ratio WhatsApp, Telegram and Facebook
 * crop to. Without this the square logo gets blown up and cropped in chat previews.
 */
final class ShareCardService
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    private const BRAND_RGB = [216, 25, 33];
    private const QUALITY = 82;

    public static function cacheDir(): string
    {
        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'share-cards';
    }

    /** Cache-busting stamp for the default card URL; changes when the logo or share image changes. */
    public static function siteStamp(?array $company = null): string
    {
        $company ??= PortalSettingsService::companySettings();
        $iconPath = CompanyBrandIconService::cachedIconPath(512);
        $iconMtime = $iconPath !== null ? (int) @filemtime($iconPath) : 0;

        return substr(sha1(implode('|', [
            (string) ($company['share_image'] ?? ''),
            (string) ($company['company_logo'] ?? ''),
            (string) $iconMtime,
            'v1',
        ])), 0, 12);
    }

    public static function siteCardUrl(?array $company = null): string
    {
        return '/share-image.php?v=' . self::siteStamp($company);
    }

    public static function productCardUrl(string $imageGuid): string
    {
        $imageGuid = strtolower(trim($imageGuid));

        return '/share-image.php?img=' . rawurlencode($imageGuid);
    }

    public static function isImageGuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower(trim($value))) === 1;
    }

    /** Path to a cached JPEG for the site card, generating it when missing. */
    public static function siteCardPath(?array $company = null): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $company ??= PortalSettingsService::companySettings();
        $target = self::cacheDir() . DIRECTORY_SEPARATOR . 'site-' . self::siteStamp($company) . '.jpg';
        if (self::isFresh($target)) {
            return $target;
        }

        $canvas = null;
        $custom = self::resolveSiteMediaPath((string) ($company['share_image'] ?? ''));
        if ($custom !== null) {
            $canvas = self::coverCard($custom);
        }
        $canvas ??= self::logoCard($company);
        if ($canvas === null) {
            return null;
        }

        return self::write($canvas, $target) ? $target : null;
    }

    /** Product photo on a white 1200×630 card with the brand strip; null when the photo is not on disk. */
    public static function productCardPath(string $imageGuid): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !self::isImageGuid($imageGuid)) {
            return null;
        }
        $imageGuid = strtolower(trim($imageGuid));
        $target = self::cacheDir() . DIRECTORY_SEPARATOR . 'img-' . $imageGuid . '.jpg';
        if (self::isFresh($target)) {
            return $target;
        }

        $source = MaterialImageStorageService::resolvePathForGuid($imageGuid, false, true)
            ?? MaterialImageStorageService::ensureStoreThumbnail($imageGuid);
        if ($source === null || !is_readable($source)) {
            return null;
        }
        $canvas = self::containCard($source);
        if ($canvas === null) {
            return null;
        }

        return self::write($canvas, $target) ? $target : null;
    }

    public static function clearCache(): void
    {
        foreach (glob(self::cacheDir() . DIRECTORY_SEPARATOR . 'site-*.jpg') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function isFresh(string $path): bool
    {
        return is_file($path) && is_readable($path) && (int) filesize($path) > 1024;
    }

    private static function resolveSiteMediaPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~^/media/site\.php\?id=([^&]+)~i', $url, $m) === 1) {
            $path = SiteMediaService::absolutePathForId(rawurldecode((string) $m[1]));

            return $path !== null && is_readable($path) && !str_ends_with(strtolower($path), '.svg') ? $path : null;
        }
        if (str_starts_with($url, '/') && !str_contains($url, '..')) {
            $candidate = dirname(__DIR__, 2) . '/public' . (string) parse_url($url, PHP_URL_PATH);
            if (is_file($candidate) && !str_ends_with(strtolower($candidate), '.svg')) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return \GdImage|null */
    private static function blankCard()
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            return null;
        }
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));

        return $img;
    }

    /** @param \GdImage $img */
    private static function brandStrip($img): void
    {
        [$r, $g, $b] = self::BRAND_RGB;
        imagefilledrectangle($img, 0, self::HEIGHT - 18, self::WIDTH, self::HEIGHT, (int) imagecolorallocate($img, $r, $g, $b));
    }

    /** @return \GdImage|null */
    private static function logoCard(?array $company = null)
    {
        $img = self::blankCard();
        if ($img === null) {
            return null;
        }
        $binary = CompanyBrandIconService::resolveIconBinary(512, PortalSettingsService::companyLogoUrl($company));
        if ($binary === null) {
            $static = dirname(__DIR__, 2) . '/public/icons/icon-512.png';
            $binary = is_file($static) ? (@file_get_contents($static) ?: null) : null;
        }
        $logo = $binary !== null ? @imagecreatefromstring($binary) : false;
        if ($logo !== false) {
            $box = 360;
            $x = (int) ((self::WIDTH - $box) / 2);
            $y = (int) ((self::HEIGHT - 18 - $box) / 2);
            imagecopyresampled($img, $logo, $x, $y, 0, 0, $box, $box, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        }
        self::brandStrip($img);

        return $img;
    }

    /** @return \GdImage|null */
    private static function coverCard(string $source)
    {
        $src = MaterialImageStorageService::loadGdImagePublic($source);
        if ($src === false) {
            return null;
        }
        $img = self::blankCard();
        if ($img === null) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max(self::WIDTH / $sw, self::HEIGHT / $sh);
        $cw = (int) round(self::WIDTH / $scale);
        $ch = (int) round(self::HEIGHT / $scale);
        $sx = (int) max(0, ($sw - $cw) / 2);
        $sy = (int) max(0, ($sh - $ch) / 2);
        imagecopyresampled($img, $src, 0, 0, $sx, $sy, self::WIDTH, self::HEIGHT, $cw, $ch);
        imagedestroy($src);

        return $img;
    }

    /** @return \GdImage|null */
    private static function containCard(string $source)
    {
        $src = MaterialImageStorageService::loadGdImagePublic($source);
        if ($src === false) {
            return null;
        }
        $img = self::blankCard();
        if ($img === null) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $maxW = self::WIDTH - 80;
        $maxH = self::HEIGHT - 18 - 40;
        $scale = min($maxW / $sw, $maxH / $sh);
        $dw = (int) round($sw * $scale);
        $dh = (int) round($sh * $scale);
        $dx = (int) ((self::WIDTH - $dw) / 2);
        $dy = (int) ((self::HEIGHT - 18 - $dh) / 2);
        imagecopyresampled($img, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);
        self::brandStrip($img);

        return $img;
    }

    /** @param \GdImage $img */
    private static function write($img, string $target): bool
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($img);

            return false;
        }
        imageinterlace($img, true);
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = imagejpeg($img, $tmp, self::QUALITY);
        imagedestroy($img);
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
