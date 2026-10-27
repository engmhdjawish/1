<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Config;

/**
 * Short, stable "build id" for the client code an installed PWA depends on
 * (service worker, page scripts, stylesheets, offline page).
 *
 * The id is a content hash: it changes when a shipped JS/CSS file changes and stays
 * identical across deploys that do not touch client code (file mtimes are ignored).
 * It is rendered into every page head (<meta name="portal-build">), served by
 * /api/app-version.php, and used as the service worker registration URL
 * (/sw.js?b=<id>) so a new build always installs a new worker.
 */
final class PwaBuild
{
    public const ID_LENGTH = 12;
    public const CACHE_TTL_SECONDS = 45;

    private const CACHE_FILE = 'pwa-build.json';
    private const APCU_KEY_PREFIX = 'portal_pwa_build:';
    private const OVERRIDE_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    /** Directories (relative to public/) scanned recursively for client code. */
    private const ASSET_DIRS = ['assets', 'css'];

    /** Single files (relative to public/) that the service worker ships or precaches. */
    private const ASSET_FILES = ['sw.js', 'offline.html'];

    /** Extensions that count as client code inside ASSET_DIRS. */
    private const ASSET_EXTENSIONS = ['js', 'mjs', 'css'];

    /** @var array<string, string> */
    private static array $memo = [];

    public static function id(): string
    {
        $override = self::overrideFromEnv();
        if ($override !== null) {
            return $override;
        }

        $publicDir = self::publicDir();
        if (isset(self::$memo[$publicDir])) {
            return self::$memo[$publicDir];
        }

        return self::$memo[$publicDir] = self::resolve($publicDir, self::cacheDir());
    }

    /**
     * Cached lookup: APCu when usable, otherwise a small JSON file in $cacheDir.
     * Falls back to recomputing when neither store can be read or written.
     */
    public static function resolve(
        string $publicDir,
        ?string $cacheDir = null,
        int $ttlSeconds = self::CACHE_TTL_SECONDS,
        ?int $now = null,
        bool $useApcu = true
    ): string {
        $now ??= time();
        $ttlSeconds = max(1, $ttlSeconds);
        $apcuKey = self::APCU_KEY_PREFIX . md5($publicDir);
        $apcu = $useApcu && self::apcuUsable();

        if ($apcu) {
            $hit = false;
            $cached = apcu_fetch($apcuKey, $hit);
            if ($hit && is_string($cached) && self::isComputedId($cached)) {
                return $cached;
            }
        }

        $file = $cacheDir !== null && $cacheDir !== '' ? rtrim($cacheDir, '/\\') . '/' . self::CACHE_FILE : null;
        if ($file !== null) {
            $fromFile = self::readCacheFile($file, $publicDir, $now);
            if ($fromFile !== null) {
                if ($apcu) {
                    @apcu_store($apcuKey, $fromFile, $ttlSeconds);
                }

                return $fromFile;
            }
        }

        $id = self::compute($publicDir);
        if ($apcu) {
            @apcu_store($apcuKey, $id, $ttlSeconds);
        }
        if ($file !== null) {
            self::writeCacheFile($file, $publicDir, $id, $now + $ttlSeconds);
        }

        return $id;
    }

    /**
     * Pure content hash of the shipped client assets (no caching, no env override).
     */
    public static function compute(string $publicDir): string
    {
        $ctx = hash_init('sha256');
        foreach (self::assetFiles($publicDir) as $relative => $absolute) {
            $raw = @file_get_contents($absolute);
            hash_update($ctx, $relative . "\0" . ($raw === false ? 'unreadable' : hash('sha256', $raw)) . "\n");
        }

        return substr(hash_final($ctx), 0, self::ID_LENGTH);
    }

    /**
     * @return array<string, string> relative path (forward slashes) => absolute path, sorted by relative path
     */
    public static function assetFiles(string $publicDir): array
    {
        $root = rtrim($publicDir, '/\\');
        $files = [];

        foreach (self::ASSET_FILES as $relative) {
            if (is_file($root . '/' . $relative)) {
                $files[$relative] = $root . '/' . $relative;
            }
        }

        foreach (self::ASSET_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
            );
            /** @var \SplFileInfo $info */
            foreach ($iterator as $info) {
                if (!$info->isFile()) {
                    continue;
                }
                $extension = strtolower($info->getExtension());
                if (!in_array($extension, self::ASSET_EXTENSIONS, true)) {
                    continue;
                }
                $absolute = str_replace('\\', '/', $info->getPathname());
                $relative = ltrim(substr($absolute, strlen(str_replace('\\', '/', $root))), '/');
                $files[$relative] = $absolute;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Accepts an operator-supplied build id (PORTAL_BUILD_ID) only when it is URL- and header-safe.
     */
    public static function sanitizeOverride(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match(self::OVERRIDE_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function isValidId(string $value): bool
    {
        return preg_match(self::OVERRIDE_PATTERN, $value) === 1;
    }

    public static function serviceWorkerUrl(?string $build = null): string
    {
        return '/sw.js?b=' . rawurlencode($build ?? self::id());
    }

    /** Forget the per-request memo (tests). */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }

    private static function overrideFromEnv(): ?string
    {
        return self::sanitizeOverride(Config::get('PORTAL_BUILD_ID'));
    }

    private static function publicDir(): string
    {
        return dirname(__DIR__, 2) . '/public';
    }

    private static function cacheDir(): string
    {
        return Config::storagePath() . '/cache';
    }

    private static function isComputedId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{' . self::ID_LENGTH . '}$/', $value) === 1;
    }

    private static function apcuUsable(): bool
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
            return false;
        }
        if (!filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        if (PHP_SAPI === 'cli' && !filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return true;
    }

    private static function readCacheFile(string $file, string $publicDir, int $now): ?string
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }
        $build = (string) ($payload['build'] ?? '');
        if (!self::isComputedId($build)) {
            return null;
        }
        if ((string) ($payload['public_dir'] ?? '') !== $publicDir) {
            return null;
        }
        if ((int) ($payload['expires_at'] ?? 0) <= $now) {
            return null;
        }

        return $build;
    }

    private static function writeCacheFile(string $file, string $publicDir, string $build, int $expiresAt): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'build' => $build,
            'public_dir' => $publicDir,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        // Write-then-rename keeps readers from ever seeing a half-written file.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);

            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
