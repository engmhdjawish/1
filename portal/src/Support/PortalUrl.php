<?php

declare(strict_types=1);

namespace Portal\Support;

final class PortalUrl
{
    public static function requestPath(): string
    {
        return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    }

    public static function currentPathWithQuery(): string
    {
        $path = self::requestPath();
        $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));

        return $query !== '' ? $path . '?' . $query : $path;
    }

    public static function safeRedirectPath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }
        if (str_starts_with($path, '//') || str_contains($path, '://')) {
            return null;
        }
        if (preg_match('#^/login\.php(?:\?|$)#', $path) === 1) {
            return null;
        }

        return $path;
    }

    public static function loginUrl(string $type = 'customer', ?string $redirect = null): string
    {
        $type = $type === 'customer' ? 'customer' : 'staff';
        $redirect ??= self::currentPathWithQuery();
        $safe = self::safeRedirectPath($redirect);
        $url = '/login.php?type=' . rawurlencode($type);
        if ($safe !== null) {
            $url .= '&redirect=' . rawurlencode($safe);
        }

        return $url;
    }

    public static function isDashboardPath(string $path): bool
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        if ($path === '/dashboard' || $path === '/dashboard/') {
            return true;
        }

        return str_starts_with($path, '/dashboard/');
    }

    public static function loginRedirectTarget(string $type, ?string $rawRedirect = null): string
    {
        $safe = self::safeRedirectPath($rawRedirect);
        if ($type === 'staff') {
            if ($safe !== null && self::isDashboardPath($safe)) {
                return $safe;
            }

            return '/dashboard/index.php';
        }

        if ($safe !== null) {
            return $safe;
        }

        return '/index.php';
    }
}
