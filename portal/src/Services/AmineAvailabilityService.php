<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;

/** حالة اتصال API الأمين — للتدهور التدريجي دون إيقاف الموقع. */
final class AmineAvailabilityService
{
    private const CACHE_TTL = 45;

    /** @return array{online: bool, status: string, message: string, user_message: string} */
    public static function snapshot(): array
    {
        $cached = self::readCache();
        if ($cached !== null) {
            return $cached;
        }

        $health = PortalSettingsService::apiHealth();
        $online = (bool) ($health['ok'] ?? false);
        $status = $online ? 'online' : self::statusFromHealth($health);
        $technical = trim((string) ($health['message'] ?? ''));
        $userMessage = $online ? '' : self::userMessageForStatus($status, $technical);

        $snapshot = [
            'online' => $online,
            'status' => $status,
            'message' => $technical,
            'user_message' => $userMessage,
        ];

        self::writeCache($snapshot);

        return $snapshot;
    }

    public static function isAvailable(): bool
    {
        return (self::snapshot()['online'] ?? false) === true;
    }

    public static function userMessage(): string
    {
        $message = trim((string) (self::snapshot()['user_message'] ?? ''));

        return $message !== '' ? $message : self::defaultUserMessage();
    }

    public static function defaultUserMessage(): string
    {
        return 'نواجه مشكلة مؤقتة في الاتصال بنظام المخزون، ونعمل على حلها خلال دقائق. '
            . 'يمكنك تصفّح آخر نسخة متاحة من المتجر ومراجعة سلتك، وقد تتأخر تحديثات الأسعار والكميات حتى يعود الاتصال.';
    }

    /** @param array{ok?: bool, status?: int, message?: string} $health */
    private static function statusFromHealth(array $health): string
    {
        $httpStatus = (int) ($health['status'] ?? 0);
        if ($httpStatus === 503) {
            return 'maintenance';
        }

        return 'offline';
    }

    private static function userMessageForStatus(string $status, string $technical): string
    {
        if ($status === 'maintenance') {
            if ($technical !== '' && !str_contains($technical, 'رمز')) {
                return $technical;
            }

            return 'النظام قيد الصيانة المؤقتة. نعمل على إعادة الخدمة خلال دقائق — شكراً لصبركم.';
        }

        return self::defaultUserMessage();
    }

    /** @return array{online: bool, status: string, message: string, user_message: string}|null */
    private static function readCache(): ?array
    {
        $path = self::cachePath();
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
        if ($expiresAt <= time()) {
            return null;
        }

        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /** @param array{online: bool, status: string, message: string, user_message: string} $snapshot */
    private static function writeCache(array $snapshot): void
    {
        $dir = dirname(self::cachePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = json_encode([
            'expires_at' => time() + self::CACHE_TTL,
            'data' => $snapshot,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        file_put_contents(self::cachePath(), $payload, LOCK_EX);
    }

    private static function cachePath(): string
    {
        return Config::storagePath() . '/cache/amine-health-status.json';
    }
}
