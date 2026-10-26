<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Config;
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
header('Cache-Control: public, max-age=300');

$appUrl = rtrim(Config::appUrl(), '/');
$useAbsolute = $appUrl !== '' && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#i', $appUrl);
$origin = $useAbsolute ? $appUrl : '';
$absolute = static function (string $path) use ($origin): string {
    $path = '/' . ltrim($path, '/');

    return $origin !== '' ? $origin . $path : $path;
};

/*
 * Keep id / start_url / scope RELATIVE.
 * Absolute ids (https://host/?source=pwa) after the www/apex unification made Chrome
 * treat the PWA as a new app → second install + shared links opening in the browser.
 * Relative id "/?source=pwa" matches the pre-unification identity on the canonical host.
 */
$startPath = '/?source=pwa';
$shortcutIcon = [['src' => $icons['manifest_icons'][0]['src'] ?? '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];

$manifest = [
    'id' => $startPath,
    'name' => $siteName,
    'short_name' => $shortName,
    'description' => portal_share_description($company, $siteName),
    'start_url' => $startPath,
    'scope' => '/',
    'display' => 'standalone',
    'display_override' => ['standalone', 'browser'],
    // Prefer opening in-scope navigations inside the installed app (Android Chrome).
    'handle_links' => 'preferred',
    'orientation' => 'portrait-primary',
    'dir' => 'rtl',
    'lang' => 'ar',
    'theme_color' => '#D81921',
    'background_color' => '#f6f6f8',
    'categories' => ['shopping', 'business'],
    'prefer_related_applications' => false,
    'related_applications' => [
        [
            'platform' => 'webapp',
            // Absolute so getInstalledRelatedApps can resolve the canonical install.
            'url' => $absolute('/manifest.php'),
        ],
    ],
    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
    'icons' => $icons['manifest_icons'],
    'shortcuts' => [
        ['name' => 'المتجر', 'url' => '/store.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'السلة', 'url' => '/store-cart.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'طلباتي', 'url' => '/my-orders.php?source=pwa', 'icons' => $shortcutIcon],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
