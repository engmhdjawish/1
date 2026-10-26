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
// Short cache so damaged WebAPKs / BIP checks pick up install-host policy quickly.
header('Cache-Control: public, max-age=60, must-revalidate');

$requestOrigin = portal_request_origin();
$installOrigin = portal_pwa_install_origin();
if ($installOrigin === '' && $requestOrigin !== '') {
    $installOrigin = $requestOrigin;
}
$onInstallHost = portal_pwa_is_install_host();

/*
 * ONE installable identity going forward:
 * - www (PORTAL_APP_URL / www sibling) is the only standalone BIP target.
 * - apex still serves content + a non-installable shim that points related_applications
 *   at www, so Chrome cannot advertise a second launcher icon.
 * - Icons are ALWAYS absolute URLs on the install origin so both hosts share one icon identity.
 * Chrome cannot merge two already-installed WebAPKs; broken apex CCT shells must migrate once.
 */
$startPath = '/?source=pwa';
// Keep apex start_url/scope same-origin so residual apex WebAPKs are not poisoned further.
$startUrl = ($requestOrigin !== '' ? $requestOrigin : $installOrigin) . $startPath;
$scopeUrl = ($requestOrigin !== '' ? $requestOrigin : $installOrigin) . '/';
if ($onInstallHost && $installOrigin !== '') {
    $startUrl = $installOrigin . $startPath;
    $scopeUrl = $installOrigin . '/';
}

$iconBase = $installOrigin !== '' ? $installOrigin : $requestOrigin;
$manifestIcons = array_map(static function (array $icon) use ($iconBase): array {
    $src = (string) ($icon['src'] ?? '');
    if ($src === '') {
        return $icon;
    }
    if (!str_starts_with($src, 'http://') && !str_starts_with($src, 'https://')) {
        $src = ($iconBase !== '' ? $iconBase : '') . (str_starts_with($src, '/') ? $src : '/' . $src);
    }
    // Bust WebAPK icon hash so Chrome re-fetches unified absolute icons.
    $icon['src'] = $src . (str_contains($src, '?') ? '&' : '?') . 'webapk=5';

    return $icon;
}, $icons['manifest_icons']);
$shortcutIconSrc = (string) ($manifestIcons[0]['src'] ?? (($iconBase !== '' ? $iconBase : '') . '/icons/icon-192.png'));
$shortcutIcon = [['src' => $shortcutIconSrc, 'sizes' => '192x192', 'type' => 'image/png']];

$related = [];
if ($installOrigin !== '') {
    $related[] = [
        'platform' => 'webapp',
        'url' => $installOrigin . '/manifest.php',
    ];
}
// On the install host, also advertise the sibling so getInstalledRelatedApps can
// detect an orphaned apex WebAPK and suppress a second BIP there.
if ($onInstallHost && $requestOrigin !== '' && $installOrigin !== ''
    && strcasecmp($requestOrigin, $installOrigin) === 0) {
    $siblingHost = \Portal\Support\HttpsGate::siblingHost($installOrigin);
    if ($siblingHost !== '') {
        $scheme = str_starts_with(strtolower($installOrigin), 'http://') ? 'http' : 'https';
        $siblingOrigin = $scheme . '://' . $siblingHost;
        if (strcasecmp($siblingOrigin, $installOrigin) !== 0) {
            $related[] = [
                'platform' => 'webapp',
                'url' => $siblingOrigin . '/manifest.php',
            ];
        }
    }
}

$shortcutBase = $onInstallHost && $installOrigin !== '' ? $installOrigin : ($requestOrigin !== '' ? $requestOrigin : '');

$manifest = [
    'id' => $startPath,
    'name' => $siteName,
    'short_name' => $shortName,
    'description' => portal_share_description($company, $siteName),
    'start_url' => $startUrl,
    'scope' => $scopeUrl,
    // Apex: browser — not installable. www: standalone — the only WebAPK.
    'display' => $onInstallHost ? 'standalone' : 'browser',
    'handle_links' => $onInstallHost ? 'preferred' : 'auto',
    'orientation' => 'portrait-primary',
    'dir' => 'rtl',
    'lang' => 'ar',
    'theme_color' => '#D81921',
    'background_color' => '#f6f6f9',
    'categories' => ['shopping', 'business'],
    // Apex prefers the www webapp so Chrome stops advertising a second install.
    'prefer_related_applications' => !$onInstallHost,
    'related_applications' => $related,
    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
    'icons' => $manifestIcons,
    'shortcuts' => [
        ['name' => 'المتجر', 'url' => $shortcutBase . '/store.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'السلة', 'url' => $shortcutBase . '/store-cart.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'طلباتي', 'url' => $shortcutBase . '/my-orders.php?source=pwa', 'icons' => $shortcutIcon],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
