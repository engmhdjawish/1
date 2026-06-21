<?php

declare(strict_types=1);

/** @var array<string, mixed> $material */
/** @var string $imageGuid */
/** @var string $variant card|detail|strip */
/** @var bool $thumb */

$material = is_array($material ?? null) ? $material : [];
$imageGuid = trim((string) ($imageGuid ?? ''));
if ($imageGuid === '') {
    $imageGuid = material_image_guid($material);
}
$variant = in_array(($variant ?? 'card'), ['card', 'detail', 'strip'], true) ? (string) $variant : 'card';
$thumb = (bool) ($thumb ?? ($variant !== 'detail'));
$imageAlt = trim((string) ($material['name'] ?? ''));
$imageSrc = $imageGuid !== '' ? material_image_api_url($imageGuid, $thumb) : '';
?>
<div class="material-image-frame material-image-frame--<?= h($variant) ?>">
  <div class="material-image-frame__photo">
    <?php if ($imageSrc !== ''): ?>
      <img src="<?= h($imageSrc) ?>" alt="<?= h($imageAlt) ?>" loading="lazy">
    <?php else: ?>
      <span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span>
    <?php endif; ?>
  </div>
</div>
