<?php

declare(strict_types=1);

namespace Portal\Support;

final class DashboardHttp
{
    public static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return $requestedWith === 'xmlhttprequest'
            || isset($_SERVER['HTTP_X_DASHBOARD_AJAX']);
    }

    /** @param array<string, mixed> $payload */
    public static function json(bool $ok, string $message, array $payload = []): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            array_merge(['ok' => $ok, 'message' => $message], $payload),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    public static function isNavigationRequest(): bool
    {
        return isset($_SERVER['HTTP_X_DASHBOARD_NAV']);
    }
}
