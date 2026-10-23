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
header('Cache-Control: public, max-age=3600');

$shortcutIcon = [['src' => $icons['manifest_icons'][0]['src'] ?? '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];

echo json_encode([
    'id' => '/?source=pwa',
    'name' => $siteName,
    'short_name' => $shortName,
    'description' => portal_share_description($company, $siteName),
    'start_url' => '/?source=pwa',
    'scope' => '/',
    'display' => 'standalone',
    'display_override' => ['standalone', 'browser'],
    'orientation' => 'portrait-primary',
    'dir' => 'rtl',
    'lang' => 'ar',
    'theme_color' => '#D81921',
    'background_color' => '#f6f6f8',
    'categories' => ['shopping', 'business'],
    'prefer_related_applications' => false,
    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],
    'icons' => $icons['manifest_icons'],
    'shortcuts' => [
        ['name' => 'المتجر', 'url' => '/store.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'السلة', 'url' => '/store-cart.php?source=pwa', 'icons' => $shortcutIcon],
        ['name' => 'طلباتي', 'url' => '/my-orders.php?source=pwa', 'icons' => $shortcutIcon],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
