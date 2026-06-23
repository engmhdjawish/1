<?php

declare(strict_types=1);

/** @var array<string, mixed> $material */
/** @var string|null $materialImageGuidOverride */
/** @var string $variant card|detail|strip */
/** @var bool $thumb */

$frameMaterial = is_array($material ?? null) ? $material : [];
$frameVariant = in_array(($variant ?? 'card'), ['card', 'detail', 'strip'], true) ? (string) $variant : 'card';
$frameThumb = (bool) ($thumb ?? ($frameVariant !== 'detail'));
$frameImageGuid = isset($materialImageGuidOverride)
    ? trim((string) $materialImageGuidOverride)
    : material_image_guid($frameMaterial);
$frameImageAlt = trim((string) ($frameMaterial['name'] ?? ''));
$frameImageSrc = $frameImageGuid !== '' ? material_image_api_url($frameImageGuid, $frameThumb) : '';
?>
<div class="material-image-frame material-image-frame--<?= h($frameVariant) ?>">
  <div class="material-image-frame__photo">
    <?php if ($frameImageSrc !== ''): ?>
      <img src="<?= h($frameImageSrc) ?>" alt="<?= h($frameImageAlt) ?>" loading="lazy">
    <?php else: ?>
      <span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span>
    <?php endif; ?>
  </div>
</div>
