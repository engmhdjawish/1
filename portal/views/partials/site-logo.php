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
if (!in_array($variant, ['header', 'drawer', 'hero', 'hero-dark', 'hero-home', 'footer'], true)) {
    $variant = 'header';
}

$alt = trim((string) ($siteLogoAlt ?? ''));
?>
<span class="site-logo-wrap site-logo-wrap--<?= h($variant) ?>">
  <img
    src="<?= h($url) ?>"
    alt="<?= h($alt) ?>"
    class="site-logo-img"
    decoding="async"
    <?= $variant === 'header' ? 'fetchpriority="high"' : '' ?>
  >
</span>
