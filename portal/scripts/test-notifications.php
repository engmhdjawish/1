<?php

declare(strict_types=1);

/**
 * Notification link, icon, expiry, and activity checks.
 * Usage: php scripts/test-notifications.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/src/Services/NotificationService.php';

use Portal\Services\NotificationService;

$failures = [];

$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";

        return;
    }
    echo "OK: {$label}\n";
};

echo "=== Notification checks ===\n\n";

$assert(
    'relative store links stay on this site',
    NotificationService::normalizeLinkUrl('/store.php?offer=summer') === '/store.php?offer=summer'
);
$assert(
    'bare paths gain a leading slash',
    NotificationService::normalizeLinkUrl('my-orders.php') === '/my-orders.php'
);
$assert(
    'empty links are omitted',
    NotificationService::normalizeLinkUrl('   ') === null
);
$assert(
    'https links are kept',
    NotificationService::normalizeLinkUrl('https://example.com/offers') === 'https://example.com/offers'
);

$rejected = false;
try {
    NotificationService::normalizeLinkUrl('//evil.example/phish');
} catch (\InvalidArgumentException) {
    $rejected = true;
}
$assert('protocol-relative links are rejected', $rejected);

$rejected = false;
try {
    NotificationService::normalizeLinkUrl('javascript:alert(1)');
} catch (\InvalidArgumentException) {
    $rejected = true;
}
$assert('javascript links are rejected', $rejected);

$assert(
    'icons are limited to symbol names',
    NotificationService::sanitizeIcon('Local_Offer', 'campaign') === 'local_offer'
        && NotificationService::sanitizeIcon('<script>', 'campaign') === 'campaign'
        && NotificationService::sanitizeIcon('', 'campaign') === 'campaign'
);

$expires = NotificationService::normalizeExpiresAt('2026-10-14T18:30');
$assert(
    'datetime-local expiry is normalized',
    is_string($expires) && str_starts_with($expires, '2026-10-14 18:30:00')
);
$assert('blank expiry is omitted', NotificationService::normalizeExpiresAt('') === null);

$rejected = false;
try {
    NotificationService::normalizeExpiresAt('not-a-date');
} catch (\InvalidArgumentException) {
    $rejected = true;
}
$assert('invalid expiry is rejected', $rejected);

$assert(
    'only a newer activity timestamp counts as a change',
    NotificationService::activityIsNewer('2026-10-14 12:00:00+00', '2026-10-14 11:00:00+00')
        && !NotificationService::activityIsNewer('2026-10-14 11:00:00+00', '2026-10-14 12:00:00+00')
        && !NotificationService::activityIsNewer('2026-10-14 12:00:00+00', '')
        && !NotificationService::activityIsNewer(null, '2026-10-14 12:00:00+00')
);

$assert(
    'known sources have Arabic labels',
    NotificationService::sourceLabel('order_received') === 'استلام طلب'
        && NotificationService::sourceLabel('special_offer') === 'عرض خاص'
        && NotificationService::sourceLabel('manual') === 'يدوي'
);

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . " check(s) failed.\n");
    exit(1);
}

echo "\nAll notification checks passed.\n";
