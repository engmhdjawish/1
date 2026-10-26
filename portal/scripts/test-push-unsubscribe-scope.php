<?php

declare(strict_types=1);

/**
 * Push unsubscribe must drop the endpoint regardless of reader scope.
 * Usage: php scripts/test-push-unsubscribe-scope.php
 */

$source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/PushSubscriptionService.php');
$notifications = (string) file_get_contents(dirname(__DIR__) . '/public/assets/notifications.js');
$sw = (string) file_get_contents(dirname(__DIR__) . '/public/sw.js');

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "OK: {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL: {$label}\n";
};

$assert(
    'removeForCurrentReader deletes by endpoint only',
    str_contains($source, 'self::deleteByEndpoint($endpoint)')
    && !str_contains($source, 'AND reader_type = :reader_type')
);

$assert(
    'guest can re-bind staff/customer endpoint after logout',
    !str_contains($source, "\$currentType === NotificationService::READER_GUEST")
);

$assert(
    'bell no longer offers إيقاف إشعارات الجهاز',
    !str_contains($notifications, "إيقاف إشعارات الجهاز")
    && str_contains($notifications, 'تفعيل إشعارات الجهاز')
);

$assert(
    'consent dismiss is session-scoped',
    str_contains($notifications, 'CONSENT_SESSION_DISMISS_KEY')
    && str_contains($notifications, 'sessionStorage')
);

$assert(
    'logout click unsubscribes push first',
    str_contains($notifications, 'bindLogoutPushCleanup')
    && str_contains($notifications, 'unsubscribeFromPush')
);

$assert(
    'session-end clears previous auth push',
    str_contains($notifications, 'clearPushAfterSessionEnd')
    && str_contains($notifications, 'LAST_AUTH_READER_KEY')
);

$assert(
    'service worker shows notification without focused client',
    str_contains($sw, 'hasFocusedClient')
    && str_contains($sw, 'pushsubscriptionchange')
);

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}

echo "\nAll push unsubscribe/consent checks passed.\n";
