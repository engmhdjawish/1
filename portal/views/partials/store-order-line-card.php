<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var bool $showLineTotal */

$showPriceSyp = (bool) ($showPriceSyp ?? true);
$showPriceUsd = (bool) ($showPriceUsd ?? false);
$showLineTotal = (bool) ($showLineTotal ?? true);
$prices = store_order_line_prices($item);
$hasOffer = store_line_has_offer($item);
$imageUrl = trim((string) ($item['image_url'] ?? ''));
$zoomUrl = $imageUrl !== '' ? material_image_zoom_url($imageUrl) : '';
?>
<article class="store-order-line-card<?= $hasOffer ? ' store-order-line-card--offer' : '' ?>">
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
    <div class="store-order-line-card__head">
      <div class="store-order-line-card__head-main">
        <?php if ($hasOffer): ?>
          <?php $badge = store_line_offer_badge($item); $size = 'sm'; require __DIR__ . '/offer-item-badge.php'; ?>
        <?php endif; ?>
        <h3 class="store-order-line-card__title"><?= h((string) ($item['material_name_ar'] ?? '—')) ?></h3>
      </div>
    </div>

    <div class="store-order-line-card__meta">
      <?php if (!empty($item['material_code'])): ?>
        <span class="store-order-line-card__code store-num" dir="ltr"><?= h((string) $item['material_code']) ?></span>
      <?php endif; ?>
      <span class="store-order-line-card__qty">
        <span class="store-num" dir="ltr"><?= h(format_packages_display($prices['quantity'])) ?></span>
        <?= h($prices['package_unit']) ?>
      </span>
    </div>

    <div class="store-order-line-card__foot">
      <?php if ($showPriceSyp || $showPriceUsd): ?>
        <?php $size = 'compact'; require __DIR__ . '/store-order-line-prices.php'; ?>
      <?php endif; ?>

      <?php if ($showLineTotal && ($showPriceSyp || $showPriceUsd)): ?>
        <div class="store-order-line-card__total">
          <span>الإجمالي</span>
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
