<?php

declare(strict_types=1);

namespace Portal;

final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function storagePath(): string
    {
        $configured = self::get('PORTAL_STORAGE_PATH');
        if ($configured !== null && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        return dirname(__DIR__) . '/storage';
    }

    public static function appUrl(): string
    {
        return rtrim(self::get('PORTAL_APP_URL', 'http://127.0.0.1:8080') ?? 'http://127.0.0.1:8080', '/');
    }
}
