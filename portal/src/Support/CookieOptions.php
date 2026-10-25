<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Config;

/**
 * Shared cookie attributes so www and apex (and other subdomains) keep one login.
 */
final class CookieOptions
{
    /**
     * @return array{path: string, secure: bool, httponly: bool, samesite: string, domain?: string}
     */
    public static function base(bool $httpOnly = true): array
    {
        $params = [
            'path' => '/',
            'secure' => HttpsGate::isHttpsRequest(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ];
        $domain = self::domain();
        if ($domain !== '') {
            $params['domain'] = $domain;
        }

        return $params;
    }

    public static function domain(): string
    {
        $explicit = trim((string) (Config::get('PORTAL_COOKIE_DOMAIN', '') ?? ''));
        if ($explicit !== '') {
            if ($explicit === 'none' || $explicit === '-') {
                return '';
            }

            return str_starts_with($explicit, '.') ? $explicit : '.' . ltrim($explicit, '.');
        }

        $host = strtolower((string) (parse_url(Config::appUrl(), PHP_URL_HOST) ?: ''));
        if ($host === '' || self::isIpOrLocal($host)) {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if ($host === '' || !str_contains($host, '.')) {
            return '';
        }

        return '.' . $host;
    }

    private static function isIpOrLocal(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
