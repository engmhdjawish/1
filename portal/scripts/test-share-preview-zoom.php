<?php

declare(strict_types=1);

/**
 * Shared product links open home preview for humans; bots keep product.php OG.
 * Preview supports guid deep-links and image-only pinch zoom.
 * Usage: php scripts/test-share-preview-zoom.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/views/helpers.php';

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$assert(
    'whatsapp unfurl is treated as a bot',
    portal_is_link_unfurl_bot('WhatsApp/2.0')
);
$assert(
    'facebook external hit is treated as a bot',
    portal_is_link_unfurl_bot('facebookexternalhit/1.1')
);
$assert(
    'telegram bot is treated as a bot',
    portal_is_link_unfurl_bot('TelegramBot (like TwitterBot)')
);
$assert(
    'normal mobile safari is not a bot',
    !portal_is_link_unfurl_bot('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')
);
$assert(
    'empty UA is not a bot',
    !portal_is_link_unfurl_bot('')
);

$productPhp = (string) file_get_contents(dirname(__DIR__) . '/public/product.php');
$assert(
    'product.php redirects humans to home preview',
    str_contains($productPhp, "portal_is_link_unfurl_bot()")
    && str_contains($productPhp, "'preview' => \$guid")
    && str_contains($productPhp, '/index.php?')
);
$assert(
    'product.php keeps share-browse and return visits on the full page',
    str_contains($productPhp, '$shareBrowse === null')
    && str_contains($productPhp, '$returnUrl === \'\'')
);
$assert(
    'product.php still builds OG for crawlers',
    str_contains($productPhp, 'portal_product_share_description')
    && str_contains($productPhp, 'ogImage')
);

$previewJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/store-product-preview.js');
$assert(
    'preview opens shared guid via API when not on the page',
    str_contains($previewJs, 'openFromGuid')
    && str_contains($previewJs, '/api/store-product.php')
    && str_contains($previewJs, 'mapApiProductToPreview')
);
$assert(
    'preview still supports first/last paging edges',
    str_contains($previewJs, "preview === 'first'")
    && str_contains($previewJs, "preview === 'last'")
);
$assert(
    'preview pinch zoom stays on the image and blocks swipe while zoomed',
    str_contains($previewJs, 'zoomState')
    && str_contains($previewJs, 'pinchDistance')
    && str_contains($previewJs, 'resetImageZoom')
    && str_contains($previewJs, 'zoomState.scale > 1.001')
);
$assert(
    'preview exports deep-link helpers',
    str_contains($previewJs, 'window.StoreProductPreview = { open, close, openFromGuid, openFromPayload }')
);

$storeUi = (string) file_get_contents(dirname(__DIR__) . '/public/css/store-ui.css');
$assert(
    'preview image stage disables browser pinch-zoom',
    str_contains($storeUi, 'touch-action: none')
    && str_contains($storeUi, '.store-product-preview.is-image-zoomed')
);

$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$assert(
    'home preview scripts load even when cart is deferred',
    str_contains($layout, '$enableProductPreview && empty($GLOBALS[\'storeProductPreviewScriptLoaded\'])')
    && substr_count($layout, "portal_defer_script('/assets/store-product-preview.js')") >= 2
);

$homePageJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/home-page.js');
$assert(
    'home eagerly loads cart scripts for shared preview deep-links',
    str_contains($homePageJs, "get('preview')")
    && str_contains($homePageJs, 'loadScripts()')
);

$shareJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/product-share.js');
$assert(
    'copied share URLs still point at product.php for OG unfurls',
    str_contains($shareJs, '/product.php?guid=')
);

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    exit(1);
}

echo "\nAll share preview zoom checks passed.\n";
