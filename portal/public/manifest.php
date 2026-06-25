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

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$manifestIcons = [
    [
        'src' => '/icons/icon-192.png',
        'sizes' => '192x192',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => '/icons/icon-512.png',
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'any',
    ],
    [
        'src' => '/icons/icon-512.png',
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'maskable',
    ],
];

echo json_encode([
    'id' => '/?source=pwa',
    'name' => $siteName,
    'short_name' => $shortName,
    'description' => 'متجر جاويش للتجارة — تصفح المنتجات واطلب بسهولة',
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
    'icons' => $manifestIcons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
