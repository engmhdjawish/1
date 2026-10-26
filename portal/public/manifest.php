<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\PortalSettingsService;
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
header('Cache-Control: public, max-age=300, must-revalidate');

$requestOrigin = portal_request_origin();

/*
 * Each host (www and apex) serves its OWN installable identity, fully same-origin.
 * Existing WebAPKs only receive Chrome's background update when the in-scope page
 * links this same /manifest.php URL and the manifest resolves to the same id
 * (origin + '/?source=pwa') AND is installable (display standalone). Pointing
 * start_url/scope at the other host or serving display "browser" freezes the old
 * WebAPK with its old icon and an out-of-scope × bar forever.
 */
$startPath = '/?source=pwa';
$startUrl = $requestOrigin . $startPath;
$scopeUrl = $requestOrigin . '/';

$manifestIcons = array_map(static function (array $icon) use ($requestOrigin): array {
    $src = (string) ($icon['src'] ?? '');
    if ($src === '') {
        return $icon;
    }
    if (!str_starts_with($src, 'http://') && !str_starts_with($src, 'https://')) {
        $src = $requestOrigin . (str_starts_with($src, '/') ? $src : '/' . $src);
    }
    // Changing the icon URL hash makes Chrome's WebAPK update check refresh old launcher icons.
    $icon['src'] = $src . (str_contains($src, '?') ? '&' : '?') . 'webapk=6';

    return $icon;
}, $icons['manifest_icons']);
$shortcutIconSrc = (string) ($manifestIcons[0]['src'] ?? ($requestOrigin . '/icons/icon-192.png'));
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
    'shortcuts' => [
        ['name' => 'المتجر', 'url' => $requestOrigin . '/store.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'السلة', 'url' => $requestOrigin . '/store-cart.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'طلباتي', 'url' => $requestOrigin . '/my-orders.php?source=pwa', 'icons' => $shortcutIcon],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
