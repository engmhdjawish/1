<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var float|null $maxPackagesPerMaterial */

$showPriceSyp = (bool) ($showPriceSyp ?? true);
$showPriceUsd = (bool) ($showPriceUsd ?? false);
$prices = store_order_line_prices($item);
$hasOffer = store_line_has_offer($item);
$materialGuid = (string) ($item['material_guid'] ?? '');
$imageUrl = trim((string) ($item['image_url'] ?? ''));
$zoomUrl = $imageUrl !== '' ? material_image_zoom_url($imageUrl) : '';
$packageUnit = (string) ($prices['package_unit'] ?? 'طرد');
?>
<article
  class="store-order-line-card store-cart-line-card<?= $hasOffer ? ' store-order-line-card--offer' : '' ?>"
  data-cart-line="<?= h($materialGuid) ?>"
>
  <div class="store-order-line-card__media">
    <?php if ($imageUrl !== ''): ?>
      <button type="button" class="store-order-line-card__thumb" data-cart-image-zoom="<?= h($zoomUrl) ?>" title="تكبير الصورة للتدقيق">
        <img src="<?= h($imageUrl) ?>" alt="" loading="lazy">
        <span class="store-order-line-card__zoom-icon material-symbols-outlined" aria-hidden="true">zoom_in</span>
      </button>
    <?php else: ?>
      <div class="store-order-line-card__placeholder">
        <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
      </div>
    <?php endif; ?>
  </div>

  <div class="store-order-line-card__body">
    <div class="store-cart-line-card__head">
      <div class="min-w-0">
        <?php if ($hasOffer): ?>
          <?php $badge = store_line_offer_badge($item); $size = 'sm'; require __DIR__ . '/offer-item-badge.php'; ?>
        <?php endif; ?>
        <h3 class="store-order-line-card__title"><?= h((string) ($item['material_name_ar'] ?? '—')) ?></h3>
        <?php if (!empty($item['material_code'])): ?>
          <div class="store-order-line-card__code store-num" dir="ltr"><?= h((string) $item['material_code']) ?></div>
        <?php endif; ?>
      </div>
      <button type="button" class="store-cart-line-card__remove" data-remove-item="<?= h($materialGuid) ?>" aria-label="حذف من السلة">
        <span class="material-symbols-outlined" aria-hidden="true">delete</span>
      </button>
    </div>

    <?php if ($showPriceSyp || $showPriceUsd): ?>
      <?php require __DIR__ . '/store-order-line-prices.php'; ?>
    <?php endif; ?>

    <div class="store-cart-line-card__actions">
      <div class="store-cart-line-card__qty-wrap">
        <span class="store-cart-line-card__qty-label">الكمية</span>
        <div class="store-qty-stepper" data-cart-qty-control data-guid="<?= h($materialGuid) ?>">
          <button type="button" data-bump="-1" aria-label="إنقاص">−</button>
          <input
            type="number"
            class="store-num"
            dir="ltr"
            min="0.01"
            step="0.01"
            <?php if ($maxPackagesPerMaterial !== null): ?>max="<?= h((string) $maxPackagesPerMaterial) ?>"<?php endif; ?>
            value="<?= h(format_packages_display($prices['quantity'])) ?>"
            data-qty-input
          >
          <button type="button" data-bump="1" aria-label="زيادة">+</button>
        </div>
        <span class="store-cart-line-card__unit"><?= h($packageUnit) ?></span>
      </div>

      <?php if ($showPriceSyp || $showPriceUsd): ?>
        <div class="store-order-line-card__total store-cart-line-card__total">
          <span>إجمالي السطر</span>
          <strong class="store-num" dir="ltr">
            <?php if ($showPriceSyp): ?>
              <?= format_money($prices['line_total_sp'], true) ?> ل.س
            <?php else: ?>
              $<?= number_format($prices['line_total_usd'], 2, '.', ',') ?>
            <?php endif; ?>
          </strong>
        </div>
      <?php endif; ?>
    </div>
  </div>
</article>
