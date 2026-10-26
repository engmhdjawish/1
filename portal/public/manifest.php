<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Config;
use Portal\Services\PortalSettingsService;
use Portal\Support\HttpsGate;
use Portal\Support\Utf8Text;

require dirname(__DIR__) . '/views/helpers.php';

$company = PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== ''
    ? (string) $company['company_name']
    : 'جاويش للتجارة';
$shortName = Utf8Text::length($siteName) > 12 ? Utf8Text::substr($siteName, 0, 12) : $siteName;
$logoUrl = PortalSettingsService::companyLogoUrl($company);
$icons = portal_site_icons($logoUrl ?? '');

header('Content-Type: application/manifest+json; charset=utf-8');
// Short cache so damaged WebAPKs pick up a healed start_url quickly.
header('Cache-Control: public, max-age=60, must-revalidate');

$appUrl = rtrim(Config::appUrl(), '/');
$useAbsolute = $appUrl !== '' && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#i', $appUrl);
$canonicalOrigin = $useAbsolute ? $appUrl : '';
$requestOrigin = portal_request_origin();
if ($requestOrigin === '' && $canonicalOrigin !== '') {
    $requestOrigin = $canonicalOrigin;
}

/*
 * id stays RELATIVE for stable identity within each origin.
 * start_url + scope are absolute to the REQUEST host so an apex-installed
 * WebAPK that was poisoned with a www start_url can self-heal on update
 * (out-of-origin start_url makes Chrome open a Custom Tab with ×).
 */
$startPath = '/?source=pwa';
$startUrl = $requestOrigin !== '' ? $requestOrigin . $startPath : $startPath;
$scopeUrl = $requestOrigin !== '' ? $requestOrigin . '/' : '/';
// Bust WebAPK icon hash so Chrome re-fetches the healed start_url/scope.
$manifestIcons = array_map(static function (array $icon): array {
    $src = (string) ($icon['src'] ?? '');
    if ($src !== '') {
        $icon['src'] = $src . (str_contains($src, '?') ? '&' : '?') . 'webapk=4';
    }

    return $icon;
}, $icons['manifest_icons']);
$shortcutIcon = [['src' => $manifestIcons[0]['src'] ?? '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];

$related = [];
if ($requestOrigin !== '') {
    $related[] = [
        'platform' => 'webapp',
        'url' => $requestOrigin . '/manifest.php',
    ];
}
$seedForSibling = $canonicalOrigin !== '' ? $canonicalOrigin : $requestOrigin;
$sibling = $seedForSibling !== '' ? HttpsGate::siblingHost($seedForSibling) : '';
if ($sibling !== '' && $requestOrigin !== '') {
    $scheme = str_starts_with(strtolower($requestOrigin), 'http://') ? 'http' : 'https';
    $siblingOrigin = $scheme . '://' . $sibling;
    if (strcasecmp($siblingOrigin, $requestOrigin) !== 0) {
        $related[] = [
            'platform' => 'webapp',
            'url' => $siblingOrigin . '/manifest.php',
        ];
    }
}

$manifest = [
    'id' => $startPath,
    'name' => $siteName,
    'short_name' => $shortName,
    'description' => portal_share_description($company, $siteName),
    'start_url' => $startUrl,
    'scope' => $scopeUrl,
    // Standalone only — "browser" in display_override showed × + title in some builds.
    'display' => 'standalone',
    'handle_links' => 'preferred',
    'orientation' => 'portrait-primary',
    'dir' => 'rtl',
    'lang' => 'ar',
    'theme_color' => '#D81921',
    // background_color bump forces Chrome WebAPK content-hash update for stuck CCT icons.
    'background_color' => '#f6f6f9',
    'categories' => ['shopping', 'business'],
    'prefer_related_applications' => false,
    'related_applications' => $related,
    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
    'icons' => $manifestIcons,
    'shortcuts' => [
        ['name' => 'المتجر', 'url' => ($requestOrigin !== '' ? $requestOrigin : '') . '/store.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'السلة', 'url' => ($requestOrigin !== '' ? $requestOrigin : '') . '/store-cart.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'طلباتي', 'url' => ($requestOrigin !== '' ? $requestOrigin : '') . '/my-orders.php?source=pwa', 'icons' => $shortcutIcon],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
