<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\PortalSettingsService;

require dirname(__DIR__) . '/views/helpers.php';

$company = PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== ''
    ? (string) $company['company_name']
    : 'جاويش للتجارة';
$shortName = portal_pwa_short_name($siteName);
$logoUrl = PortalSettingsService::companyLogoUrl($company);
$icons = portal_site_icons($logoUrl ?? '');

header('Content-Type: application/manifest+json; charset=utf-8');
// Short on purpose: a changed manifest should reach installed apps within a minute of a deploy.
header('Cache-Control: public, max-age=60, must-revalidate');

/*
 * Origin used for every absolute URL below.
 *  - Canonical host (www): pinned to the configured canonical origin.
 *  - Sibling host (apex): the request origin. A manifest cannot point cross-origin (the Web App Manifest
 *    spec ignores a start_url that is not same-origin with the document, and an apex-installed WebAPK is
 *    bound to https://<apex>/), so forcing www here would break the apex app's update instead of moving it.
 *
 * Identity: `id` stays '/?source=pwa' on both hosts (resolves to https://<host>/?source=pwa). Changing it
 * would orphan every installed app (duplicate install prompt, no more updates).
 * Existing WebAPKs only receive Chrome's background update when the in-scope page links this same
 * /manifest.php URL and the manifest is installable (display standalone). Serving display "browser" or a
 * start_url/scope on the other host freezes the old WebAPK with its old icon and an out-of-scope bar.
 */
$origin = portal_manifest_origin();
$startPath = '/?source=pwa';
$startUrl = $origin . $startPath;
$scopeUrl = $origin . '/';
$iconRevision = portal_pwa_icon_revision();

$manifestIcons = array_map(static function (array $icon) use ($origin, $iconRevision): array {
    $src = (string) ($icon['src'] ?? '');
    if ($src === '') {
        return $icon;
    }
    if (!str_starts_with($src, 'http://') && !str_starts_with($src, 'https://')) {
        $src = $origin . (str_starts_with($src, '/') ? $src : '/' . $src);
    }
    // Changing the icon URL hash makes Chrome's WebAPK update check refresh old launcher icons.
    $icon['src'] = $src . (str_contains($src, '?') ? '&' : '?') . 'webapk=' . $iconRevision;

    return $icon;
}, $icons['manifest_icons']);
$shortcutIconSrc = (string) ($manifestIcons[0]['src'] ?? ($origin . '/icons/icon-192.png'));
$shortcutIcon = [['src' => $shortcutIconSrc, 'sizes' => '192x192', 'type' => 'image/png']];

$manifest = [
    'id' => $startPath,
    'name' => $siteName,
    'short_name' => $shortName,
    'description' => portal_share_description($company, $siteName),
    'start_url' => $startUrl,
    'scope' => $scopeUrl,
    'display' => 'standalone',
    'handle_links' => 'preferred',
    'orientation' => 'portrait-primary',
    'dir' => 'rtl',
    'lang' => 'ar',
    'theme_color' => '#D81921',
    'background_color' => '#f6f6f9',
    'categories' => ['shopping', 'business'],
    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
    'icons' => $manifestIcons,
    // Lets /pwa-id.php detect (navigator.getInstalledRelatedApps) whether a WebAPK with this identity exists.
    'related_applications' => [['platform' => 'webapp', 'url' => $origin . '/manifest.php']],
    'shortcuts' => [
        ['name' => 'المتجر', 'url' => $origin . '/store.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'السلة', 'url' => $origin . '/store-cart.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'طلباتي', 'url' => $origin . '/my-orders.php?source=pwa', 'icons' => $shortcutIcon],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
