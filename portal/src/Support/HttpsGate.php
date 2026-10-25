<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Config;

/**
 * Forces HTTPS and a single public host (www vs apex) from PORTAL_APP_URL.
 * Without this, login cookies, localStorage, and the installed PWA diverge.
 */
final class HttpsGate
{
    public static function redirectIfNeeded(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        $target = self::redirectTarget();
        if ($target === null) {
            return;
        }

        header('Location: ' . $target, true, 301);
        exit;
    }

    /** Absolute URL to redirect to, or null when the request is already canonical. */
    public static function redirectTarget(
        ?string $requestHost = null,
        ?string $requestUri = null,
        ?bool $isHttps = null,
        ?string $appUrl = null
    ): ?string {
        $requestHost = strtolower(self::stripPort(trim((string) ($requestHost ?? ($_SERVER['HTTP_HOST'] ?? '')))));
        $requestUri = (string) ($requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        if ($requestUri === '' || !str_starts_with($requestUri, '/')) {
            $requestUri = '/' . ltrim($requestUri, '/');
        }
        $isHttps = $isHttps ?? self::isHttpsRequest();
        $appUrl = trim((string) ($appUrl ?? Config::appUrl()));

        if ($requestHost === '' || self::isLocalHost($requestHost)) {
            return null;
        }

        $configuredHost = self::hostFromAppUrl($appUrl);
        $appIsHttps = str_starts_with(strtolower($appUrl), 'https://');
        $forceHttps = self::shouldForceHttps($appUrl);

        $hostNeedsFix = $configuredHost !== ''
            && !self::isLocalHost($configuredHost)
            && strcasecmp($requestHost, $configuredHost) !== 0;

        $httpsNeedsFix = ($forceHttps || $appIsHttps) && !$isHttps;

        if (!$hostNeedsFix && !$httpsNeedsFix) {
            return null;
        }

        $scheme = ($forceHttps || $appIsHttps || $isHttps) ? 'https' : 'http';
        $host = $hostNeedsFix ? $configuredHost : $requestHost;

        return $scheme . '://' . $host . $requestUri;
    }

    public static function configuredHost(): string
    {
        return self::hostFromAppUrl(Config::appUrl());
    }

    private static function shouldForceHttps(?string $appUrl = null): bool
    {
        $flag = strtolower(trim((string) (getenv('PORTAL_FORCE_HTTPS') ?: Config::get('PORTAL_FORCE_HTTPS', '') ?? '')));
        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $appUrl = strtolower(trim((string) ($appUrl ?? (getenv('PORTAL_APP_URL') ?: Config::get('PORTAL_APP_URL', '') ?? ''))));

        return str_starts_with($appUrl, 'https://');
    }

    public static function isHttpsRequest(): bool
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

    private static function hostFromAppUrl(string $appUrl): string
    {
        $host = strtolower((string) (parse_url(trim($appUrl), PHP_URL_HOST) ?: ''));

        return self::stripPort($host);
    }

    private static function stripPort(string $host): string
    {
        return (string) preg_replace('/:\d+$/', '', $host);
    }

    private static function isLocalHost(string $host): bool
    {
        $normalized = strtolower($host);

        return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true)
            || filter_var($normalized, FILTER_VALIDATE_IP) !== false;
    }
}
