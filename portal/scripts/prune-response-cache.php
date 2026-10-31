<?php

declare(strict_types=1);

/**
 * Delete expired ResponseCache JSON files under storage/cache.
 * Usage: php scripts/prune-response-cache.php [maxDeletes]
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Support\ResponseCache;

$max = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 50000;
$removed = 0;
$passes = 0;
while ($removed < $max && $passes < 200) {
    $batch = ResponseCache::pruneExpired(min(2000, $max - $removed));
    if ($batch === 0) {
        break;
    }
    $removed += $batch;
    ++$passes;
}

echo json_encode(['removed' => $removed, 'passes' => $passes], JSON_UNESCAPED_UNICODE) . PHP_EOL;
