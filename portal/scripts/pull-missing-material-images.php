<?php

declare(strict_types=1);

/**
 * Pull Amine-linked material images that are missing on the portal disk.
 *
 * Fast path (default): one local GUID index + Amine pages, then download only missing GUIDs.
 *
 * Usage:
 *   php scripts/pull-missing-material-images.php
 *   php scripts/pull-missing-material-images.php --limit=100
 *   php scripts/pull-missing-material-images.php --count-only
 *   php scripts/pull-missing-material-images.php --legacy --max-pages=50 --page-size=15
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
$limit = null;
$countOnly = false;
$legacy = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--count-only') {
        $countOnly = true;
        continue;
    }
    if ($arg === '--legacy') {
        $legacy = true;
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
        $limit = max(1, (int) $m[1]);
        continue;
    }
    if (preg_match('/^--max-pages=(\d+)$/', $arg, $m) === 1) {
        $maxPages = max(1, (int) $m[1]);
    }
    if (preg_match('/^--page-size=(\d+)$/', $arg, $m) === 1) {
        $pageSize = max(1, min(30, (int) $m[1]));
    }
}

if ($limit === null) {
    $limit = $maxPages * $pageSize;
}

$log = static function (string $message): void {
    echo $message . "\n";
    if (function_exists('flush')) {
        flush();
    }
};

if ($countOnly) {
    $log('=== إحصاء سريع للصور الناقصة محلياً ===');
    $result = MaterialImageStorageService::countMissingLocals(
        static function (string $message) use ($log): void {
            $log('  ' . $message);
        }
    );
    if (!($result['ok'] ?? false)) {
        fwrite(STDERR, (string) ($result['message'] ?? 'فشل الإحصاء') . "\n");
        exit(1);
    }
    $log('');
    $log('مواد لها صورة في الأمين: ' . (int) ($result['amine_with_image'] ?? 0));
    $log('منها موجودة محلياً:     ' . (int) ($result['local_for_amine'] ?? 0));
    $log('ناقصة محلياً (المطلوب):  ' . (int) ($result['missing'] ?? 0));
    $log('حجم فهرس الملفات المحلي: ' . (int) ($result['local_guid_index_size'] ?? 0));
    exit(0);
}

// Drop stale lock files from killed previous runs (advisory locks die with process,
// but leftover empty lock files are confusing when debugging).
$lockDir = rtrim((string) (\Portal\Config::storagePath()), '/\\') . DIRECTORY_SEPARATOR . 'locks';
if (is_dir($lockDir)) {
    $cleared = 0;
    foreach (glob($lockDir . DIRECTORY_SEPARATOR . 'amine-image-pull-*.lock') ?: [] as $lockFile) {
        if (@unlink($lockFile)) {
            $cleared++;
        }
    }
    if ($cleared > 0) {
        $log("تم مسح {$cleared} ملف قفل قديم من storage/locks.");
    }
}

if (!$legacy) {
    $log('=== سحب سريع للصور الناقصة (فهرس محلي + قائمة أمين) ===');
    $log("limit={$limit}");
    $log('ملاحظة: المسح مرة واحدة ثم تنزيل الناقص فقط (بدون إعادة من الصفحة 1).');
    $log('');

    $result = MaterialImageStorageService::pullAllMissingLocals(
        $limit,
        static function (string $message) use ($log): void {
            $log('  ' . $message);
        }
    );

    if (!($result['ok'] ?? false) && (int) ($result['scanned'] ?? 0) === 0) {
        fwrite(STDERR, 'Stopped: ' . (string) ($result['message'] ?? 'unknown error') . "\n");
        exit(1);
    }

    $log('');
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

    $totalPulled = (int) ($result['pulled'] ?? 0);
    $totalFailed = (int) ($result['failed'] ?? 0);
    $totalScanned = (int) ($result['scanned'] ?? 0);
    $log('');
    $log("Done. missing_found=" . (int) ($result['missing_found'] ?? 0)
        . " scanned={$totalScanned} pulled={$totalPulled} failed={$totalFailed}");
    exit($totalFailed > 0 && $totalPulled === 0 ? 1 : 0);
}

$log('=== Pull missing material images from Amine (legacy chunk loop) ===');
$log("page_size={$pageSize} max_pages={$maxPages}");
$log('ملاحظة: لكل صورة ستظهر خطوات (قفل / بيانات / تنزيل ≤60ث / مصغّرة).');
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
