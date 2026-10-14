<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;

/** @var array<string, mixed> $item */
/** @var array<string, mixed> $displayOptions */
/** @var bool $isSoon */
/** @var string $productReturnUrl */

$item = is_array($item ?? null) ? $item : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$isSoon = (bool) ($isSoon ?? false);
$productReturnUrl = (string) ($productReturnUrl ?? '/store-lab.php');
$showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
$showPrice = (bool) ($displayOptions['show_price'] ?? false);
$showQuantity = (bool) ($displayOptions['show_quantity'] ?? false) && !$isSoon;
$allowCart = (bool) ($displayOptions['allow_cart'] ?? false) && !$isSoon;
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
$hasListedPrice = $showPrice && catalog_material_has_sale_price($item, $priceMode);

$guid = material_guid($item);
$detailUrl = $guid !== '' ? product_url($guid, $productReturnUrl) : '';
$name = trim((string) ($item['name'] ?? ''));
if ($name === '') {
    $name = 'مادة';
}
$materialCode = trim((string) ($item['materialCode'] ?? $item['code'] ?? ''));
$materialType = trim((string) ($item['materialType'] ?? ''));
$manufacturer = trim((string) ($item['manufacturer'] ?? ''));
$sizeRange = trim((string) ($item['sizeRange'] ?? ''));
$packaging = ShareCartService::packaging($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$productAnchor = portal_product_anchor($guid);
$packagesAvailable = 0.0;
if ($showQuantity) {
    try {
        $packagesAvailable = packages_available_display($item);
    } catch (Throwable $exception) {
        $packagesAvailable = 0.0;
    }
}
?>
<article class="sl-card<?= $isSoon ? ' sl-card--soon' : '' ?>"<?= $productAnchor !== '' ? ' id="' . h($productAnchor) . '"' : '' ?>>
  <?php if ($showImages): ?>
    <a class="sl-card__media" href="<?= h($detailUrl !== '' ? $detailUrl : '#') ?>"<?= $detailUrl === '' ? ' tabindex="-1"' : '' ?>>
      <?php if ($isSoon): ?>
        <span class="sl-card__badge">يتوفر قريبا</span>
      <?php elseif ($materialType !== ''): ?>
        <span class="sl-card__badge sl-card__badge--type"><?= h($materialType) ?></span>
      <?php endif; ?>
      <?php
        $material = $item;
        $variant = 'card';
        require __DIR__ . '/material-image-frame.php';
      ?>
    </a>
  <?php endif; ?>
  <div class="sl-card__body">
    <?php if ($materialCode !== '' || $sizeRange !== ''): ?>
      <p class="sl-card__meta">
        <?php if ($materialCode !== ''): ?><span dir="ltr"><?= h($materialCode) ?></span><?php endif; ?>
        <?php if ($materialCode !== '' && $sizeRange !== ''): ?><span aria-hidden="true">·</span><?php endif; ?>
        <?php if ($sizeRange !== ''): ?><span><?= h($sizeRange) ?></span><?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($detailUrl !== ''): ?>
      <a class="sl-card__title" href="<?= h($detailUrl) ?>"><?= h($name) ?></a>
    <?php else: ?>
      <h3 class="sl-card__title"><?= h($name) ?></h3>
    <?php endif; ?>
    <?php if ($manufacturer !== ''): ?>
      <p class="sl-card__brand"><?= h($manufacturer) ?></p>
    <?php endif; ?>
    <p class="sl-card__pack">
      تعبئة <span dir="ltr"><?= h(format_packaging($packaging)) ?></span>
      <?= h($primaryUnit) ?> / <?= h($packageUnit) ?>
    </p>
    <?php if ($showPrice && $hasListedPrice): ?>
      <div class="sl-card__price">
        <?php require __DIR__ . '/offer-price-block.php'; ?>
      </div>
    <?php elseif (!$showPrice): ?>
      <div class="sl-card__price">
        <?php $context = 'card'; $anchor = $productAnchor; require __DIR__ . '/store-price-lock.php'; ?>
      </div>
    <?php endif; ?>
    <?php if ($showQuantity && $packagesAvailable > 0): ?>
      <p class="sl-card__stock">
        <span class="material-symbols-outlined" aria-hidden="true">inventory</span>
        <span dir="ltr"><?= h(format_packages_display($packagesAvailable)) ?></span>
        <?= h($packageUnit) ?>
      </p>
    <?php endif; ?>
    <?php if ($isSoon): ?>
      <p class="sl-card__soon-note">تُباع لدينا، وستعود للطلب عند توفر الكمية.</p>
    <?php endif; ?>
  </div>
  <?php if ($allowCart): ?>
    <div class="sl-card__footer">
      <?php
        try {
            require __DIR__ . '/store-add-to-cart-form.php';
        } catch (Throwable $exception) {
            echo '<p class="sl-card__soon-note">يمكن إضافتها للسلة من صفحة المادة.</p>';
        }
      ?>
    </div>
  <?php endif; ?>
</article>
