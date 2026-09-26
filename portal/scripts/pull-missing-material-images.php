<?php

declare(strict_types=1);

/**
 * Pull Amine-linked material images that are missing on the portal disk.
 *
 * Usage:
 *   php scripts/pull-missing-material-images.php
 *   php scripts/pull-missing-material-images.php --max-pages=20 --page-size=15
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\MaterialImageStorageService;

$maxPages = 50;
$pageSize = 15;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--max-pages=(\d+)$/', $arg, $m) === 1) {
        $maxPages = max(1, (int) $m[1]);
    }
    if (preg_match('/^--page-size=(\d+)$/', $arg, $m) === 1) {
        $pageSize = max(1, min(30, (int) $m[1]));
    }
}

$log = static function (string $message): void {
    echo $message . "\n";
    if (function_exists('flush')) {
        flush();
    }
};

$log('=== Pull missing material images from Amine ===');
$log("page_size={$pageSize} max_pages={$maxPages}");
$log('ملاحظة: أول دفعة قد تستغرق وقتاً إن كان API بطيئاً — سيظهر تقدّم فوري.');
$log('');

$totalPulled = 0;
$totalFailed = 0;
$totalScanned = 0;
$page = 1;

for ($i = 0; $i < $maxPages; $i++) {
    $log('--- pass ' . ($i + 1) . " / page {$page} ---");
    $result = MaterialImageStorageService::pullMissingLocalsChunk(
        $page,
        $pageSize,
        static function (string $message) use ($log): void {
            $log('  ' . $message);
        }
    );
    $scanned = (int) ($result['scanned'] ?? 0);
    $pulled = (int) ($result['pulled'] ?? 0);
    $failed = (int) ($result['failed'] ?? 0);
    $hasMore = (bool) ($result['has_more'] ?? false);

    $totalScanned += $scanned;
    $totalPulled += $pulled;
    $totalFailed += $failed;

    $log('نتيجة: ' . (string) ($result['message'] ?? ''));
    foreach (($result['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $mark = ($item['ok'] ?? false) ? 'OK' : 'FAIL';
        $code = (string) ($item['material_code'] ?? '');
        $guid = (string) ($item['image_guid'] ?? '');
        $log("  [{$mark}] {$code} {$guid}");
    }

    if (!($result['ok'] ?? false) && $scanned === 0 && $i === 0) {
        fwrite(STDERR, 'Stopped: ' . (string) ($result['message'] ?? 'unknown error') . "\n");
        exit(1);
    }

    if ($scanned === 0) {
        break;
    }

    if ($pulled > 0) {
        // Successful pulls drop out of the "missing" filter — restart from page 1.
        $page = 1;
        continue;
    }

    if ($hasMore) {
        // This page could not be pulled; advance to avoid looping forever.
        $page++;
        continue;
    }

    break;
}

$log('');
$log("Done. scanned={$totalScanned} pulled={$totalPulled} failed={$totalFailed}");
exit($totalFailed > 0 && $totalPulled === 0 ? 1 : 0);
