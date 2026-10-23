<?php

declare(strict_types=1);

/**
 * Notification categories, paged admin history and the double-send guard.
 * Usage: php scripts/test-notifications-organize.php   (needs Postgres)
 */

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Database;
use Portal\Services\NotificationService;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$assert('order sources map to orders', NotificationService::category('order_status') === 'orders' && NotificationService::category('new_order') === 'orders');
$assert('offer source maps to offers', NotificationService::category('special_offer') === 'offers');
$assert('account sources map to account', NotificationService::category('password_changed') === 'account');
$assert('manual and unknown map to news', NotificationService::category('manual') === 'news' && NotificationService::category('whatever') === 'news');

NotificationService::ensureTable();
$pdo = Database::pdo();
$tag = 'test-organize-' . bin2hex(random_bytes(3));
$ids = [];
try {
    $ids[] = NotificationService::createPublic($tag . ' حملة', 'نص الحملة', 'all', '/store.php', 'campaign');
    $ids[] = NotificationService::createPublic($tag . ' عرض', 'نص العرض', 'all', '/store.php', 'local_offer', null, null, 'special_offer');

    $manual = NotificationService::listSentPage(['kind' => 'manual', 'q' => $tag], 1, 20);
    $auto = NotificationService::listSentPage(['kind' => 'auto', 'q' => $tag], 1, 20);
    $all = NotificationService::listSentPage(['kind' => 'all', 'q' => $tag], 1, 20);
    $assert('admin manual tab only has campaigns', $manual['total'] === 1 && str_ends_with((string) $manual['rows'][0]['title_ar'], 'حملة'));
    $assert('admin auto tab only has automatic', $auto['total'] === 1 && (string) $auto['rows'][0]['source'] === 'special_offer');
    $assert('admin all tab has both', $all['total'] === 2);
    $assert('search with LIKE wildcards is literal', NotificationService::listSentPage(['kind' => 'all', 'q' => '%%' . $tag], 1, 20)['total'] === 0);
    $assert('page is clamped', NotificationService::listSentPage(['kind' => 'all', 'q' => $tag], 99, 5)['page'] === 1);

    $offers = array_filter(NotificationService::listForReader(100, 0, 'offers'), static fn (array $r): bool => str_starts_with((string) $r['title_ar'], $tag));
    $news = array_filter(NotificationService::listForReader(100, 0, 'news'), static fn (array $r): bool => str_starts_with((string) $r['title_ar'], $tag));
    $assert('reader offers filter', count($offers) === 1);
    $assert('reader news filter excludes offers', count($news) === 1 && array_values($news)[0]['category'] === 'news');

    $assert('just-sent campaign is a duplicate', NotificationService::isRecentManualDuplicate($tag . ' حملة', 'نص الحملة'));
    $assert('different text is not a duplicate', !NotificationService::isRecentManualDuplicate($tag . ' حملة', 'نص آخر'));
} finally {
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM portal_notifications WHERE id = CAST(:id AS uuid)')->execute(['id' => $id]);
    }
}

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}
echo "\nnotifications organize ok\n";
