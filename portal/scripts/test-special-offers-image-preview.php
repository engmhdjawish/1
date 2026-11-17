<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (string $label, bool $ok): void {
    echo ($ok ? 'OK' : 'FAIL') . "  {$label}\n";
    if (!$ok) {
        exit(1);
    }
};

$view = (string) file_get_contents($root . '/views/dashboard/special-offers.php');
$js = (string) file_get_contents($root . '/public/assets/dashboard/special-offers.js');
$previewJs = (string) file_get_contents($root . '/public/assets/store-product-preview.js');

$assert('invoice root has offer preview', str_contains($view, 'data-so-offer-preview-root'));
$assert('rows expose preview payload', str_contains($view, 'data-so-offer-preview-line'));
$assert('thumb opens large preview', str_contains($view, 'data-store-product-preview'));
$assert('special-offers attaches preview metadata', str_contains($js, 'attachOfferPreviewLine'));
$assert('store preview supports offer context', str_contains($previewJs, "state.context === 'offer'"));

echo "All special-offers image preview checks passed.\n";