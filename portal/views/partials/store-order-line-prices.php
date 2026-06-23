<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var string $size compact|default */

$showPriceSyp = (bool) ($showPriceSyp ?? true);
$showPriceUsd = (bool) ($showPriceUsd ?? false);
$size = ($size ?? 'default') === 'compact' ? 'compact' : 'default';
$prices = store_order_line_prices($item);
$hasOffer = store_line_has_offer($item);
?>
<div class="store-order-line-prices store-order-line-prices--<?= h($size) ?>">
  <?php if ($showPriceSyp && ($prices['pack_sp'] > 0 || $prices['orig_pack_sp'] > 0)): ?>
    <div class="store-order-line-prices__row store-order-line-prices__row--main">
      <span class="store-order-line-prices__label"><?= h($prices['package_unit']) ?></span>
      <div class="store-order-line-prices__values">
        <?php if ($hasOffer && $prices['orig_pack_sp'] > $prices['pack_sp']): ?>
          <span class="store-order-line-prices__old store-num" dir="ltr"><?= format_money($prices['orig_pack_sp'], true) ?> ل.س</span>
        <?php endif; ?>
        <span class="store-order-line-prices__amount store-num" dir="ltr"><?= format_money($prices['pack_sp'], true) ?> <small>ل.س</small></span>
      </div>
    </div>
    <?php if ($prices['unit_sp'] > 0): ?>
      <div class="store-order-line-prices__row">
        <span class="store-order-line-prices__label"><?= h($prices['primary_unit']) ?></span>
        <div class="store-order-line-prices__values">
          <?php if ($hasOffer && $prices['orig_unit_sp'] > $prices['unit_sp']): ?>
            <span class="store-order-line-prices__old store-num" dir="ltr"><?= format_money($prices['orig_unit_sp'], true) ?> ل.س</span>
          <?php endif; ?>
          <span class="store-order-line-prices__amount store-order-line-prices__amount--unit store-num" dir="ltr"><?= format_money($prices['unit_sp'], true) ?> <small>ل.س</small></span>
        </div>
      </div>
    <?php endif; ?>
  <?php elseif ($showPriceUsd && ($prices['pack_usd'] > 0 || $prices['orig_pack_usd'] > 0)): ?>
    <div class="store-order-line-prices__row store-order-line-prices__row--main">
      <span class="store-order-line-prices__label"><?= h($prices['package_unit']) ?></span>
      <div class="store-order-line-prices__values">
        <?php if ($hasOffer && $prices['orig_pack_usd'] > $prices['pack_usd']): ?>
          <span class="store-order-line-prices__old store-num" dir="ltr">$<?= number_format($prices['orig_pack_usd'], 2, '.', ',') ?></span>
        <?php endif; ?>
        <span class="store-order-line-prices__amount store-num" dir="ltr">$<?= number_format($prices['pack_usd'], 2, '.', ',') ?></span>
      </div>
    </div>
    <?php if ($prices['unit_usd'] > 0): ?>
      <div class="store-order-line-prices__row">
        <span class="store-order-line-prices__label"><?= h($prices['primary_unit']) ?></span>
        <div class="store-order-line-prices__values">
          <?php if ($hasOffer && $prices['orig_unit_usd'] > $prices['unit_usd']): ?>
            <span class="store-order-line-prices__old store-num" dir="ltr">$<?= number_format($prices['orig_unit_usd'], 2, '.', ',') ?></span>
          <?php endif; ?>
          <span class="store-order-line-prices__amount store-order-line-prices__amount--unit store-num" dir="ltr">$<?= number_format($prices['unit_usd'], 2, '.', ',') ?></span>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
