<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;

$logo = PortalSettingsService::companyLogoUrl();
if ($logo === null || $logo === '') {
    echo "No company_logo configured.\n";
    exit(1);
}

$ok = CompanyBrandIconService::regenerateFromLogoUrl($logo);
if (!$ok) {
    echo "Failed to generate brand icons from: {$logo}\n";
    echo "Ensure GD is enabled and the logo is PNG/JPG/WebP (not SVG).\n";
    exit(1);
}

echo "Brand icons generated in: " . CompanyBrandIconService::brandingDir() . "\n";
foreach ([32, 180, 192, 512] as $size) {
    $path = CompanyBrandIconService::iconAbsolutePath($size);
    echo "  {$size}: " . (is_file($path) ? 'OK' : 'MISSING') . "\n";
}
