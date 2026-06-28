<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var bool $lineShowsPrice */
/** @var bool $customerShowsPrices */
/** @var float|null $maxPackagesPerMaterial */

$showPriceSyp = (bool) ($showPriceSyp ?? true);
$showPriceUsd = (bool) ($showPriceUsd ?? false);
$lineShowsPrice = (bool) ($lineShowsPrice ?? false);
$customerShowsPrices = (bool) ($customerShowsPrices ?? false);
$prices = store_order_line_prices($item);
$hasOffer = store_line_has_offer($item);
$materialGuid = (string) ($item['material_guid'] ?? '');
$imageUrl = trim((string) ($item['image_url'] ?? ''));
$zoomUrl = $imageUrl !== '' ? material_image_zoom_url($imageUrl) : '';
$packageUnit = (string) ($prices['package_unit'] ?? 'طرد');
?>
<article
  class="store-order-line-card store-cart-line-card<?= $hasOffer ? ' store-order-line-card--offer' : '' ?><?= $customerShowsPrices && !$lineShowsPrice ? ' store-cart-line-card--no-price' : '' ?>"
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
      <div class="store-cart-line-card__head-main min-w-0">
        <?php if ($hasOffer): ?>
          <?php $badge = store_line_offer_badge($item); $size = 'sm'; require __DIR__ . '/offer-item-badge.php'; ?>
        <?php endif; ?>
        <h3 class="store-order-line-card__title"><?= h((string) ($item['material_name_ar'] ?? '—')) ?></h3>
        <?php if (!empty($item['material_code'])): ?>
          <span class="store-order-line-card__code store-num" dir="ltr"><?= h((string) $item['material_code']) ?></span>
        <?php endif; ?>
      </div>
      <button type="button" class="store-cart-line-card__remove" data-remove-item="<?= h($materialGuid) ?>" aria-label="حذف من السلة">
        <span class="material-symbols-outlined" aria-hidden="true">delete</span>
      </button>
    </div>

    <div class="store-cart-line-card__foot">
      <?php if ($lineShowsPrice && ($showPriceSyp || $showPriceUsd)): ?>
        <?php $size = 'compact'; require __DIR__ . '/store-order-line-prices.php'; ?>
      <?php elseif ($customerShowsPrices): ?>
        <div class="store-cart-line-card__no-price">
          <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
          <span>السعر عند التأكيد</span>
        </div>
      <?php endif; ?>

      <div class="store-cart-line-card__controls">
        <div class="store-cart-line-card__qty-row">
          <div class="store-qty-stepper store-qty-stepper--compact" data-cart-qty-control data-guid="<?= h($materialGuid) ?>">
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

        <?php if ($lineShowsPrice && ($showPriceSyp || $showPriceUsd)): ?>
          <?php
            $lineTotalSp = (float) ($prices['line_total_sp'] ?? 0);
            $lineTotalUsd = (float) ($prices['line_total_usd'] ?? 0);
          ?>
          <?php if (($showPriceSyp && $lineTotalSp > 0) || ($showPriceUsd && $lineTotalUsd > 0)): ?>
            <div class="store-order-line-card__total store-cart-line-card__total">
              <span>الإجمالي</span>
              <strong class="store-num" dir="ltr">
                <?php if ($showPriceSyp && $lineTotalSp > 0): ?>
                  <?= format_money($lineTotalSp, true) ?> ل.س
                <?php elseif ($showPriceUsd && $lineTotalUsd > 0): ?>
                  $<?= number_format($lineTotalUsd, 2, '.', ',') ?>
                <?php endif; ?>
              </strong>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</article>
