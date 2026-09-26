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

echo "=== Pull missing material images from Amine ===\n";
echo "page_size={$pageSize} max_pages={$maxPages}\n\n";

$totalPulled = 0;
$totalFailed = 0;
$totalScanned = 0;
$page = 1;

for ($i = 0; $i < $maxPages; $i++) {
    $result = MaterialImageStorageService::pullMissingLocalsChunk($page, $pageSize);
    $scanned = (int) ($result['scanned'] ?? 0);
    $pulled = (int) ($result['pulled'] ?? 0);
    $failed = (int) ($result['failed'] ?? 0);
    $hasMore = (bool) ($result['has_more'] ?? false);

    $totalScanned += $scanned;
    $totalPulled += $pulled;
    $totalFailed += $failed;

    echo "pass " . ($i + 1) . " (page {$page}): " . (string) ($result['message'] ?? '') . "\n";
    foreach (($result['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $mark = ($item['ok'] ?? false) ? 'OK' : 'FAIL';
        $code = (string) ($item['material_code'] ?? '');
        $guid = (string) ($item['image_guid'] ?? '');
        echo "  [{$mark}] {$code} {$guid}\n";
    }

    if (!($result['ok'] ?? false) && $scanned === 0 && $i === 0) {
        fwrite(STDERR, "Stopped: " . (string) ($result['message'] ?? 'unknown error') . "\n");
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

echo "\nDone. scanned={$totalScanned} pulled={$totalPulled} failed={$totalFailed}\n";
exit($totalFailed > 0 && $totalPulled === 0 ? 1 : 0);
