<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;
use Portal\Services\SiteMediaService;

$logo = PortalSettingsService::companyLogoUrl();
if ($logo === null || $logo === '') {
    fwrite(STDERR, "No company_logo configured in company_settings.\n");
    exit(1);
}

$diag = CompanyBrandIconService::diagnose($logo);
echo "Logo URL: {$logo}\n";
echo 'Source path: ' . ($diag['source_path'] ?? 'null') . "\n";
echo 'Source exists: ' . (($diag['source_exists'] ?? false) ? 'yes' : 'no') . "\n";
echo 'MIME: ' . ($diag['source_mime'] ?? 'n/a') . "\n";
echo 'GD loaded: ' . (($diag['gd_loaded'] ?? false) ? 'yes' : 'no') . "\n";
echo 'Branding writable: ' . (($diag['branding_writable'] ?? false) ? 'yes' : 'no') . "\n";

if (preg_match('~^/media/site\.php\?id=([^&]+)~i', $logo, $matches) === 1) {
    $asset = SiteMediaService::getById(rawurldecode((string) $matches[1]));
    echo 'DB asset: ' . ($asset !== null ? 'found' : 'missing') . "\n";
    if ($asset !== null) {
        echo 'storage_path: ' . (string) ($asset['storage_path'] ?? '') . "\n";
        echo 'mime_type: ' . (string) ($asset['mime_type'] ?? '') . "\n";
    }
}

$ok = CompanyBrandIconService::regenerateFromLogoUrl($logo);
if (!$ok) {
    fwrite(STDERR, 'Failed: ' . (CompanyBrandIconService::lastError() ?? 'unknown error') . "\n");
    exit(1);
}

echo "Brand icons generated in: " . CompanyBrandIconService::brandingDir() . "\n";
foreach ([32, 180, 192, 512] as $size) {
    $path = CompanyBrandIconService::iconAbsolutePath($size);
    echo "  {$size}: " . (is_file($path) ? 'OK' : 'MISSING') . "\n";
}
