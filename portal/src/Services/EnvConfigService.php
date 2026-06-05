<?php

declare(strict_types=1);

namespace Portal\Services;

final class EnvConfigService
{
    private const MANAGED_KEYS = [
        'PORTAL_DB_HOST',
        'PORTAL_DB_PORT',
        'PORTAL_DB_NAME',
        'PORTAL_DB_USER',
        'PORTAL_DB_PASSWORD',
        'AMINE_API_BASE_URL',
        'AMINE_API_USERNAME',
        'AMINE_API_PASSWORD',
    ];

    public static function envPath(): string
    {
        return dirname(__DIR__, 2) . '/.env';
    }

    /** @return array<string, string> */
    public static function integrationSettings(): array
    {
        $values = [];
        foreach (self::MANAGED_KEYS as $key) {
            $values[$key] = (string) (\Portal\Config::get($key, '') ?? '');
        }

        return $values;
    }

    /**
     * @param array<string, string|null> $updates
     * @return array{ok: bool, message: string}
     */
    public static function updateIntegrationSettings(array $updates): array
    {
        $path = self::envPath();
        if (!is_file($path) || !is_writable($path)) {
            return ['ok' => false, 'message' => 'ملف .env غير موجود أو غير قابل للكتابة على الخادم.'];
        }

        $current = self::integrationSettings();
        $next = $current;

        foreach (self::MANAGED_KEYS as $key) {
            if (!array_key_exists($key, $updates)) {
                continue;
            }
            $value = trim((string) ($updates[$key] ?? ''));
            if ($value === '' && str_ends_with($key, '_PASSWORD')) {
                continue;
            }
            if ($value === '' && !str_ends_with($key, '_PASSWORD')) {
                return ['ok' => false, 'message' => 'الحقل ' . $key . ' مطلوب.'];
            }
            $next[$key] = $value;
        }

        if ($next['AMINE_API_BASE_URL'] !== '') {
            $next['AMINE_API_BASE_URL'] = rtrim($next['AMINE_API_BASE_URL'], '/');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return ['ok' => false, 'message' => 'تعذر قراءة ملف .env.'];
        }

        $writtenKeys = [];
        $output = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                $output[] = rtrim($line, "\r");
                continue;
            }

            [$rawKey] = explode('=', $line, 2);
            $key = trim($rawKey);
            if (!in_array($key, self::MANAGED_KEYS, true)) {
                $output[] = rtrim($line, "\r");
                continue;
            }

            $output[] = $key . '=' . self::encodeValue($next[$key]);
            $writtenKeys[$key] = true;
        }

        foreach (self::MANAGED_KEYS as $key) {
            if (isset($writtenKeys[$key])) {
                continue;
            }
            $output[] = $key . '=' . self::encodeValue($next[$key]);
        }

        $tempPath = $path . '.tmp';
        $payload = implode("\n", $output) . "\n";
        if (file_put_contents($tempPath, $payload, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'تعذر كتابة ملف .env المؤقت.'];
        }
        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            return ['ok' => false, 'message' => 'تعذر استبدال ملف .env.'];
        }

        self::applyRuntime($next);

        if (($updates['AMINE_API_PASSWORD'] ?? '') !== '' || ($updates['AMINE_API_USERNAME'] ?? null) !== null || ($updates['AMINE_API_BASE_URL'] ?? null) !== null) {
            ApiClient::invalidateToken();
        }

        return ['ok' => true, 'message' => 'تم حفظ إعدادات الاتصال.'];
    }

    /** @param array<string, string> $values */
    public static function applyRuntime(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
        \Portal\Database::resetConnection();
    }

    private static function encodeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#"\']/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
