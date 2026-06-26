<?php

declare(strict_types=1);

namespace Portal\Support;

final class HttpsGate
{
    public static function redirectIfNeeded(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!self::shouldForceHttps() || self::isHttpsRequest()) {
            return;
        }

        $host = self::canonicalHost();
        if ($host === '' || self::isLocalHost($host)) {
            return;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }

    private static function shouldForceHttps(): bool
    {
        $flag = strtolower(trim((string) (getenv('PORTAL_FORCE_HTTPS') ?: '')));
        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $appUrl = strtolower(trim((string) (getenv('PORTAL_APP_URL') ?: '')));

        return str_starts_with($appUrl, 'https://');
    }

    private static function isHttpsRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        if (in_array($forwardedSsl, ['on', '1', 'true'], true)) {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    private static function canonicalHost(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return (string) preg_replace('/:\d+$/', '', $host);
    }

    private static function isLocalHost(string $host): bool
    {
        $normalized = strtolower($host);

        return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true);
    }
}
