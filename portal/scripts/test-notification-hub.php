<?php

declare(strict_types=1);

/**
 * Smoke checks for the dashboard notifications hub helpers.
 * Usage: php scripts/test-notification-hub.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\NotificationService;
use Portal\Services\PushSubscriptionService;
use Portal\Services\WebPushService;

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "OK: {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL: {$label}\n";
};

$templates = NotificationService::composeTemplates();
$assert('compose templates available', count($templates) >= 4);
$assert('compose template shape', isset($templates[0]['title'], $templates[0]['body'], $templates[0]['icon']));

$stats = NotificationService::sentStats();
foreach (['total', 'manual_count', 'auto_count', 'active_count', 'read_total', 'dismiss_total'] as $key) {
    $assert("sentStats has {$key}", array_key_exists($key, $stats));
}

$reach = NotificationService::audienceReach();
$assert('audienceReach has customers_active', array_key_exists('customers_active', $reach));
$assert('audienceReach has push totals', isset($reach['push']['total'], $reach['push']['guest']));

$pushCounts = PushSubscriptionService::countsByReaderType();
$assert('push counts keys', isset($pushCounts['total'], $pushCounts['customer'], $pushCounts['staff']));

$view = (string) file_get_contents(dirname(__DIR__) . '/views/dashboard/notifications.php');
$assert('hub shows push health', str_contains($view, 'إشعارات الجهاز'));
$assert('hub has refill action', str_contains($view, 'data-refill-notification'));
$assert('hub has audience reach hint', str_contains($view, 'data-audience-reach-hint'));
$assert('hub confirms before send', str_contains($view, 'تأكيد إرسال'));
$assert('hub can open viewers list', str_contains($view, 'data-view-readers'));

$controller = (string) file_get_contents(dirname(__DIR__) . '/public/dashboard/notifications.php');
$assert('controller loads audience reach', str_contains($controller, 'audienceReach'));
$assert('controller mentions device push in flash', str_contains($controller, 'WebPushService::isConfigured'));

$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/notifications.php');
$assert('API exposes readers action', str_contains($api, "action === 'readers'"));
$assert('API read accepts via', str_contains($api, "payload['via']"));

$service = (string) file_get_contents(dirname(__DIR__) . '/src/Services/NotificationService.php');
$assert('listReadersForNotification exists', str_contains($service, 'function listReadersForNotification'));
$assert('view_via column ensured', str_contains($service, 'view_via'));

$sw = (string) file_get_contents(dirname(__DIR__) . '/public/sw.js');
$assert('push click marks read with via=push', str_contains($sw, "via: 'push'"));

// Soft check — configured flag is boolean either way.
$assert('WebPushService::isConfigured is callable', is_bool(WebPushService::isConfigured()));
$assert('listReaders returns array', is_array(NotificationService::listReadersForNotification('00000000-0000-4000-8000-000000000001')));

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}

echo "\nNotification hub checks passed.\n";
