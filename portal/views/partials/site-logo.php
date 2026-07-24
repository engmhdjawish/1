<?php

declare(strict_types=1);

/**
 * @var string|null $companyLogoUrl
 * @var string $siteLogoAlt
 * @var string $siteLogoVariant header|drawer|hero
 */

$url = trim((string) ($companyLogoUrl ?? ''));
if ($url === '') {
    return;
}

$variant = (string) ($siteLogoVariant ?? 'header');
if (!in_array($variant, ['header', 'drawer', 'hero', 'hero-dark', 'hero-home', 'footer', 'mobile-toolbar'], true)) {
    $variant = 'header';
}

$alt = trim((string) ($siteLogoAlt ?? ''));
$logoSrc = $url;
if (in_array($variant, ['header', 'mobile-toolbar'], true)) {
    // Keep SVG raster (format=png); avoid lossy WebP on brand logos.
    $logoSrc = portal_site_media_display_url($url, 640, false);
}
?>
<span class="site-logo-wrap site-logo-wrap--<?= h($variant) ?>">
  <img
    src="<?= h($logoSrc) ?>"
    alt="<?= h($alt) ?>"
    class="site-logo-img"
    decoding="async"
    <?= $variant === 'header' ? 'fetchpriority="high"' : '' ?>
  >
</span>
