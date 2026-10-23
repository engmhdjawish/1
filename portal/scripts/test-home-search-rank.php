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

$soonRank = HomeSearchService::rank('حذاء', [
    ['label' => 'حذاء رياضي', 'hint' => 'مادة', 'href' => '/product.php?guid=1', 'kind' => 'product', 'trusted' => true],
    ['label' => 'حذاء شتوي', 'hint' => 'يتوفر قريبا', 'badge' => 'غير متوفر حالياً', 'href' => '/store.php?q=%D8%AD%D8%B0%D8%A7%D8%A1', 'kind' => 'soon', 'trusted' => true],
]);
$soonHit = null;
foreach ($soonRank['suggestions'] as $suggestion) {
    if (($suggestion['kind'] ?? '') === 'soon') {
        $soonHit = $suggestion;
        break;
    }
}
$soonOk = ($soonHit['hint'] ?? '') === 'يتوفر قريبا'
    && ($soonHit['badge'] ?? '') === 'غير متوفر حالياً'
    && ($soonHit['label'] ?? '') === 'حذاء شتوي'
    && ($soonRank['suggestions'][0]['kind'] ?? '') === 'product';

$surface = HomeSearchService::applySurface([
    ['kind' => 'section', 'label' => 'رجالي', 'hint' => 'قسم', 'href' => '/store.php?section=men'],
    ['kind' => 'product', 'label' => 'حذاء', 'hint' => 'مادة', 'href' => '/product.php?guid=1'],
    ['kind' => 'search', 'label' => 'ابحث', 'hint' => 'في كل المواد', 'href' => '/store.php?q=x'],
    ['kind' => 'soon', 'label' => 'حذاء شتوي', 'hint' => 'يتوفر قريبا', 'badge' => 'غير متوفر حالياً', 'href' => '/store.php?q=soon'],
], 'lab');
$surfaceOk = ($surface[0]['href'] ?? '') === '/store.php?section=men'
    && ($surface[0]['hint'] ?? null) === ''
    && ($surface[1]['href'] ?? '') === '/product.php?guid=1'
    && ($surface[1]['hint'] ?? null) === ''
    && ($surface[2]['href'] ?? '') === '/store.php?q=x'
    && ($surface[2]['hint'] ?? null) === ''
    && ($surface[3]['href'] ?? '') === '/store.php?q=soon'
    && ($surface[3]['hint'] ?? null) === ''
    && ($surface[3]['badge'] ?? '') === 'غير متوفر حالياً';
$liveSurface = HomeSearchService::applySurface($surface, '');
$liveSoon = array_values(array_filter(
    $liveSurface,
    static fn (array $suggestion): bool => ($suggestion['kind'] ?? '') === 'soon'
));
$liveSurfaceOk = ($liveSurface[1]['href'] ?? '') === '/product.php?guid=1' && $liveSoon === [];

if (!$guessOk || !$firstOk || !$typoOk || !$normalizeOk || !$emptyOk || !$correctionOk || !$soonOk || !$surfaceOk || !$liveSurfaceOk) {
    fwrite(STDERR, json_encode([
        'prefix' => $prefix,
        'typo' => $typo,
        'normalized' => $normalized,
        'empty' => $empty,
        'correction' => $correction,
        'soon' => $soonRank,
        'surface' => $surface,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo "home search rank ok\n";
