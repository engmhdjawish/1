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
$frameLoading = (string) ($loading ?? ($frameVariant === 'detail' ? 'eager' : 'lazy'));
$frameFetchPriority = (string) ($fetchPriority ?? ($frameVariant === 'detail' ? 'high' : 'auto'));
$frameDecoding = (string) ($decoding ?? 'async');
$frameHasImage = $frameImageSrc !== '';
?>
<div class="material-image-frame material-image-frame--<?= h($frameVariant) ?><?= $frameHasImage ? '' : ' material-image-frame--empty' ?>">
  <div class="material-image-frame__photo">
    <?php if ($frameHasImage): ?>
      <img
        src="<?= h($frameImageSrc) ?>"
        alt="<?= h($frameImageAlt) ?>"
        loading="<?= h($frameLoading) ?>"
        decoding="<?= h($frameDecoding) ?>"
        class="material-image-frame__img"
        <?php if ($frameFetchPriority === 'high'): ?>fetchpriority="high"<?php elseif ($frameVariant === 'card' || $frameVariant === 'strip'): ?>fetchpriority="low"<?php endif; ?>
        onerror="window.portalMaterialImageFrameEmpty&&window.portalMaterialImageFrameEmpty(this)"
      >
    <?php else: ?>
      <div class="material-image-frame__empty" role="img" aria-label="بلا صورة">
        <span class="material-symbols-outlined material-image-frame__empty-icon" aria-hidden="true">hide_image</span>
        <span class="material-image-frame__empty-label">بلا صورة</span>
      </div>
    <?php endif; ?>
  </div>
</div>
