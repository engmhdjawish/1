<?php

declare(strict_types=1);

use Portal\Services\MaterialImageDisplayTemplateService;

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
$companyContext = is_array($companyContext ?? null) ? $companyContext : null;
$imageAlt = trim((string) ($material['name'] ?? ''));
$imageSrc = $imageGuid !== ''
    ? '/api/image.php?id=' . rawurlencode($imageGuid) . ($thumb ? '&thumb=1' : '')
    : '';

$template = MaterialImageDisplayTemplateService::getTemplate();
$useTemplate = (bool) ($template['enabled'] ?? true);
$resolvedElements = $useTemplate
    ? MaterialImageDisplayTemplateService::resolvedElements($material, $companyContext)
    : [];
$useTemplate = $useTemplate && $resolvedElements !== [];

$footer = is_array($template['footer'] ?? null) ? $template['footer'] : [];
$photo = is_array($template['photo'] ?? null) ? $template['photo'] : [];
$footerEnabled = $useTemplate ? (bool) ($footer['enabled'] ?? true) : true;

$frameClasses = 'material-image-frame material-image-frame--' . h($variant);
if ($useTemplate) {
    $frameClasses .= ' material-image-frame--template';
    if (!$footerEnabled) {
        $frameClasses .= ' material-image-frame--no-footer';
    }
}

$frameStyle = '';
if ($useTemplate) {
    $frameStyle = MaterialImageDisplayTemplateService::cssMapToString([
        '--mif-accent-color' => (string) ($footer['accent_color'] ?? '#d81921'),
        '--mif-accent-width' => rtrim(rtrim(number_format((float) ($footer['accent_width_rem'] ?? 0.28), 3, '.', ''), '0'), '.') . 'rem',
        '--mif-footer-bg' => (string) ($footer['background'] ?? 'linear-gradient(180deg, #454545 0%, #3a3a3a 100%)'),
        '--mif-footer-padding' => rtrim(rtrim(number_format((float) ($footer['padding_rem'] ?? 0.6), 3, '.', ''), '0'), '.') . 'rem',
        '--mif-footer-min-height' => rtrim(rtrim(number_format((float) ($footer['min_height_rem'] ?? 3.2), 3, '.', ''), '0'), '.') . 'rem',
        '--mif-footer-font-base' => rtrim(rtrim(number_format((float) ($footer['font_base_rem'] ?? 1), 3, '.', ''), '0'), '.') . 'rem',
        '--mif-photo-bg' => (string) ($photo['background'] ?? '#f3f4f6'),
    ]);
}

/** @param list<array<string, mixed>> $elements */
$renderLayer = static function (array $elements, string $region): void {
    $regionElements = array_values(array_filter(
        $elements,
        static fn (array $element): bool => (string) ($element['region'] ?? '') === $region
    ));
    if ($regionElements === []) {
        return;
    }
    ?>
    <div class="material-image-frame__layer" aria-hidden="true">
      <?php foreach ($regionElements as $element): ?>
        <?php
          $inlineStyle = MaterialImageDisplayTemplateService::cssMapToString(
              MaterialImageDisplayTemplateService::elementInlineStyle($element)
          );
        ?>
        <div class="material-image-frame__el material-image-frame__el--<?= h((string) ($element['type'] ?? 'text')) ?>" style="<?= h($inlineStyle) ?>">
          <?= MaterialImageDisplayTemplateService::renderElementInnerHtml($element) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
};
?>
<div class="<?= $frameClasses ?>"<?= $frameStyle !== '' ? ' style="' . h($frameStyle) . '"' : '' ?>>
  <?php if ($useTemplate): ?>
    <div class="material-image-frame__stack">
      <div class="material-image-frame__photo">
        <?php if ($imageSrc !== ''): ?>
          <img src="<?= h($imageSrc) ?>" alt="<?= h($imageAlt) ?>" loading="lazy">
        <?php else: ?>
          <span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span>
        <?php endif; ?>
        <?php $renderLayer($resolvedElements, 'photo'); ?>
      </div>
      <?php if ($footerEnabled): ?>
        <div class="material-image-frame__footer">
          <?php $renderLayer($resolvedElements, 'footer'); ?>
        </div>
      <?php endif; ?>
      <?php $renderLayer($resolvedElements, 'frame'); ?>
    </div>
  <?php else: ?>
    <div class="material-image-frame__photo">
      <?php if ($imageSrc !== ''): ?>
        <img src="<?= h($imageSrc) ?>" alt="<?= h($imageAlt) ?>" loading="lazy">
      <?php else: ?>
        <span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span>
      <?php endif; ?>
    </div>
    <?php
      $branding = material_image_branding($companyContext, isset($companyLogoUrl) ? (string) $companyLogoUrl : null);
      $productLine = material_product_line($material);
      $packagingLine = material_packaging_line($material);
    ?>
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
  <?php endif; ?>
</div>
