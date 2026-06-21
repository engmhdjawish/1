<?php

declare(strict_types=1);

/** @var array<string, mixed> $material */
/** @var string $imageGuid */
/** @var string $variant card|detail|strip */
/** @var array<string, string>|null $companyContext */
/** @var string|null $companyLogoUrl */
/** @var bool $thumb */

$material = is_array($material ?? null) ? $material : [];
$imageGuid = trim((string) ($imageGuid ?? ''));
$variant = in_array(($variant ?? 'card'), ['card', 'detail', 'strip'], true) ? (string) $variant : 'card';
$thumb = (bool) ($thumb ?? ($variant !== 'detail'));
$branding = material_image_branding(
    is_array($companyContext ?? null) ? $companyContext : null,
    isset($companyLogoUrl) ? (string) $companyLogoUrl : null
);
$productLine = material_product_line($material);
$packagingLine = material_packaging_line($material);
$imageAlt = trim((string) ($material['name'] ?? ''));
$imageSrc = $imageGuid !== ''
    ? '/api/image.php?id=' . rawurlencode($imageGuid) . ($thumb ? '&thumb=1' : '')
    : '';
?>
<div class="material-image-frame material-image-frame--<?= h($variant) ?>">
  <div class="material-image-frame__photo">
    <?php if ($imageSrc !== ''): ?>
      <img src="<?= h($imageSrc) ?>" alt="<?= h($imageAlt) ?>" loading="lazy">
    <?php else: ?>
      <span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span>
    <?php endif; ?>
  </div>
  <div class="material-image-frame__footer">
    <div class="material-image-frame__details">
      <?php if ($productLine !== ''): ?>
        <div class="material-image-frame__product"><?= h($productLine) ?></div>
      <?php endif; ?>
      <?php if ($packagingLine !== ''): ?>
        <div class="material-image-frame__packaging"><?= h($packagingLine) ?></div>
      <?php endif; ?>
    </div>
    <div class="material-image-frame__brand">
      <?php if ($branding['logo_url'] !== null): ?>
        <img class="material-image-frame__logo" src="<?= h((string) $branding['logo_url']) ?>" alt="">
      <?php endif; ?>
      <div class="material-image-frame__brand-text">
        <div class="material-image-frame__business"><?= h((string) $branding['name']) ?></div>
        <?php if ($branding['phone'] !== ''): ?>
          <div class="material-image-frame__phone" dir="ltr"><?= h((string) $branding['phone']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
