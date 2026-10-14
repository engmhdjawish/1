<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Services/HomeSearchService.php';

use Portal\Services\HomeSearchService;

$candidates = [
    ['label' => 'رجالي', 'hint' => 'قسم', 'href' => '/store.php?section=men', 'kind' => 'section'],
    ['label' => 'أطفال', 'hint' => 'قسم', 'href' => '/store.php?section=kids', 'kind' => 'section'],
    ['label' => 'حذاء رياضي', 'hint' => 'مادة', 'href' => '/product.php?guid=1', 'kind' => 'product'],
];

$prefix = HomeSearchService::rank('رجا', $candidates);
$guessOk = ($prefix['guess'] ?? '') === 'رجالي';
$firstOk = ($prefix['suggestions'][0]['label'] ?? '') === 'رجالي';

$typo = HomeSearchService::rank('رجالى', $candidates);
$typoOk = ($typo['guess'] ?? '') === 'رجالي' || ($typo['suggestions'][0]['label'] ?? '') === 'رجالي';

$normalized = HomeSearchService::normalize('أحذية أطفال');
$normalizeOk = $normalized === 'احذيه اطفال';

$empty = HomeSearchService::rank('   ', $candidates);
$emptyOk = ($empty['suggestions'][0]['kind'] ?? '') === 'discover';

$correction = HomeSearchService::rank('رجاليي', $candidates);
$correctionOk = ($correction['guess'] ?? '') === 'رجالي';

if (!$guessOk || !$firstOk || !$typoOk || !$normalizeOk || !$emptyOk || !$correctionOk) {
    fwrite(STDERR, json_encode([
        'prefix' => $prefix,
        'typo' => $typo,
        'normalized' => $normalized,
        'empty' => $empty,
        'correction' => $correction,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo "home search rank ok\n";
