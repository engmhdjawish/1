<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;

final class WebPushService
{
    public static function isConfigured(): bool
    {
        return self::publicKey() !== '' && self::privateKey() !== '' && class_exists(\Minishlink\WebPush\WebPush::class);
    }

    public static function publicKey(): string
    {
        return trim((string) (Config::get('VAPID_PUBLIC_KEY') ?? ''));
    }

    public static function privateKey(): string
    {
        return trim((string) (Config::get('VAPID_PRIVATE_KEY') ?? ''));
    }

    public static function subject(): string
    {
        $subject = trim((string) (Config::get('VAPID_SUBJECT') ?? ''));
        if ($subject !== '') {
            return $subject;
        }

        $email = trim((string) (Config::get('PORTAL_CONTACT_EMAIL') ?? ''));
        if ($email !== '' && str_contains($email, '@')) {
            return 'mailto:' . $email;
        }

        return 'mailto:admin@jawish.local';
    }

    public static function sendForNotificationId(string $notificationId): void
    {
        if (!self::isConfigured()) {
            return;
        }

        $notification = self::fetchNotification($notificationId);
        if ($notification === null) {
            return;
        }

        $subscriptions = PushSubscriptionService::subscriptionsForNotification($notification);
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode([
            'id' => (string) ($notification['id'] ?? $notificationId),
            'title' => (string) ($notification['title_ar'] ?? 'إشعار جديد'),
            'body' => (string) ($notification['body_ar'] ?? ''),
            'url' => (string) ($notification['link_url'] ?? '/'),
            'icon' => '/icons/brand-icon.php?size=192',
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => self::subject(),
                    'publicKey' => self::publicKey(),
                    'privateKey' => self::privateKey(),
                ],
            ]);

            foreach ($subscriptions as $row) {
                $endpoint = trim((string) ($row['endpoint'] ?? ''));
                $p256dh = trim((string) ($row['p256dh'] ?? ''));
                $auth = trim((string) ($row['auth'] ?? ''));
                if ($endpoint === '' || $p256dh === '' || $auth === '') {
                    continue;
                }

                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $endpoint,
                    'keys' => [
                        'p256dh' => $p256dh,
                        'auth' => $auth,
                    ],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    $endpoint = $report->getEndpoint();
                    $reason = (string) $report->getReason();
                    if ($endpoint !== '' && (
                        str_contains($reason, '410')
                        || str_contains($reason, '404')
                        || stripos($reason, 'expired') !== false
                        || stripos($reason, 'unsubscribed') !== false
                    )) {
                        PushSubscriptionService::deleteByEndpoint($endpoint);
                    }
                }
            }
        } catch (\Throwable) {
            // Never block notification creation.
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetchNotification(string $notificationId): ?array
    {
        NotificationService::ensureTable();
        $notificationId = trim($notificationId);
        if ($notificationId === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id,
                    scope,
                    audience,
                    title_ar,
                    body_ar,
                    link_url,
                    recipient_web_customer_id::text AS recipient_web_customer_id,
                    recipient_web_user_id::text AS recipient_web_user_id
             FROM portal_notifications
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $notificationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
