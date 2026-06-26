<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Config;

final class ResponseCache
{
    private const DEFAULT_TTL = 120;

    /** @return mixed|null */
    public static function get(string $key)
    {
        $path = self::pathForKey($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($path);

            return null;
        }

        return $payload['data'] ?? null;
    }

    /** @param mixed $data */
    public static function set(string $key, $data, int $ttlSeconds = self::DEFAULT_TTL): void
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = json_encode([
            'expires_at' => time() + $ttlSeconds,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }

        file_put_contents(self::pathForKey($key), $payload, LOCK_EX);
    }

    /** @return mixed */
    public static function remember(string $key, int $ttlSeconds, callable $resolver)
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $resolver();
        self::set($key, $value, $ttlSeconds);

        return $value;
    }

    public static function forget(string $key): void
    {
        $path = self::pathForKey($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function cacheDir(): string
    {
        return Config::storagePath() . '/cache';
    }

    private static function pathForKey(string $key): string
    {
        return self::cacheDir() . '/' . hash('sha256', $key) . '.json';
    }
}
