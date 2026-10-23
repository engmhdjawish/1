<?php

declare(strict_types=1);

/**
 * Link previews: wholesale wording, no prices, and a 1200×630 card image.
 * Usage: php scripts/test-share-preview.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/views/helpers.php';

use Portal\Services\ShareCardService;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$default = portal_share_description([], 'متجر س');
$assert('default description mentions wholesale', str_contains($default, 'الجملة'));
$assert('default description no longer hardcodes shoes', !str_contains($default, 'أحذية'));
$assert('custom description wins and is whitespace-collapsed', portal_share_description(['share_description_ar' => "  نص   مخصص\n"], 'x') === 'نص مخصص');
$assert('home path uses share description', portal_seo_description('/index.php', 'متجر س', null, ['share_description_ar' => 'وصف']) === 'وصف');

$product = [
    'name' => 'صندل 101',
    'materialType' => 'صندل',
    'ageCategory' => 'نسائي',
    'sizeRange' => '40-45',
    'packageConversionFactor' => 12,
    'primaryUnit' => 'زوج',
    'packageUnit' => 'طرد',
    'manufacturer' => 'النجمة',
    'unitSalePriceSyp' => 47500,
    'unitSalePriceUsd' => 3.7,
];
$text = portal_product_share_description($product, 'متجر س');
$assert('product text has type, sizes and package', str_contains($text, 'صندل') && str_contains($text, '40-45') && str_contains($text, 'طرد 12 زوج'));
$assert('product text never carries a price', !str_contains($text, '47500') && !str_contains($text, '47,500') && !str_contains($text, '3.7'));
$assert('product text says wholesale', str_contains($text, 'بالجملة'));

$assert('image guid validation', ShareCardService::isImageGuid('6B1F0001-1C2D-4E5F-8A9B-000000001EEF') && !ShareCardService::isImageGuid('../etc/passwd'));
$assert('product card url is lowercased', ShareCardService::productCardUrl('ABCDEF00-0000-4000-8000-000000000000') === '/share-image.php?img=abcdef00-0000-4000-8000-000000000000');

if (function_exists('imagecreatetruecolor')) {
    $path = ShareCardService::siteCardPath(['company_logo' => '', 'share_image' => '']);
    $size = $path !== null ? @getimagesize($path) : false;
    $assert('site card is a 1200x630 jpeg', is_array($size) && $size[0] === 1200 && $size[1] === 630 && $size['mime'] === 'image/jpeg');
    $assert('unknown product image falls back (null)', ShareCardService::productCardPath('00000000-0000-4000-8000-000000000000') === null);
}

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}
echo "\nAll share preview checks passed.\n";
