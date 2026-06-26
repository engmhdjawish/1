<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;
use Portal\Services\SiteMediaService;

$logo = PortalSettingsService::companyLogoUrl() ?? '';
$diag = CompanyBrandIconService::diagnose($logo);

header('Content-Type: text/plain; charset=utf-8');

echo "=== Logo diagnostic ===\n\n";
echo "company_logo: {$logo}\n";
echo "storage_path: " . ($diag['storage_path'] ?? '') . "\n";
echo "source_path: " . ($diag['source_path'] ?? 'null') . "\n";
echo "source_exists: " . (($diag['source_exists'] ?? false) ? 'YES' : 'NO') . "\n";
echo "source_mime: " . ($diag['source_mime'] ?? 'n/a') . "\n";
echo "gd_loaded: " . (($diag['gd_loaded'] ?? false) ? 'YES' : 'NO') . "\n";
echo "branding_dir: " . ($diag['branding_dir'] ?? '') . "\n";
echo "branding_writable: " . (($diag['branding_writable'] ?? false) ? 'YES' : 'NO') . "\n";
echo "has_brand_icons: " . (($diag['has_brand_icons'] ?? false) ? 'YES' : 'NO') . "\n";

if (preg_match('~^/media/site\.php\?id=([^&]+)~i', $logo, $matches) === 1) {
    $id = rawurldecode((string) $matches[1]);
    $asset = SiteMediaService::getById($id);
    echo "\n=== DB record ===\n";
    if ($asset === null) {
        echo "Asset NOT in database - re-select logo in settings.\n";
    } else {
        echo 'id: ' . (string) ($asset['id'] ?? '') . "\n";
        echo 'storage_path: ' . (string) ($asset['storage_path'] ?? '') . "\n";
        echo 'mime_type: ' . (string) ($asset['mime_type'] ?? '') . "\n";
        $abs = SiteMediaService::absolutePathForId($id);
        echo 'absolute_path: ' . ($abs ?? 'null') . "\n";
        echo 'file_exists: ' . (($abs !== null && is_file($abs)) ? 'YES' : 'NO') . "\n";
    }
}

echo "\nOpen in browser: {$logo}\n";
