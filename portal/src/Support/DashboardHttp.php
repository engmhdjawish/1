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
        self::emitJson(array_merge(['ok' => $ok, 'message' => $message], $payload), $ok ? 200 : 400);
    }

    /** @param array<string, mixed> $payload */
    public static function emitJson(array $payload, int $status = 200): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            $encoded = json_encode(['ok' => false, 'message' => 'تعذر ترميز الاستجابة.'], JSON_UNESCAPED_UNICODE);
        }

        echo $encoded;
        exit;
    }

    public static function isNavigationRequest(): bool
    {
        return isset($_SERVER['HTTP_X_DASHBOARD_NAV']);
    }
}
