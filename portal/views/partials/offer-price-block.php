<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;

/** @var array<string, mixed> $item */

$hasOffer = !empty($item['has_offer']);
$packaging = ShareCartService::packaging($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);

if ($hasOffer) {
    $origPackSp = (float) ($item['original_package_sale_price_sp'] ?? 0);
    $origPackUsd = (float) ($item['original_package_sale_price_usd'] ?? 0);
    $origUnitSp = (float) ($item['original_unit_sale_price_sp'] ?? 0);
    $origUnitUsd = (float) ($item['original_unit_sale_price_usd'] ?? 0);
    $effPackSp = (float) ($item['effective_package_sale_price_sp'] ?? ShareCartService::packageSalePriceSp($item));
    $effPackUsd = (float) ($item['effective_package_sale_price_usd'] ?? ShareCartService::packageSalePriceUsd($item));
    $effUnitSp = ShareCartService::unitSalePriceSp($item);
    $effUnitUsd = ShareCartService::unitSalePriceUsd($item);
} else {
    $origPackSp = 0.0;
    $origPackUsd = 0.0;
    $origUnitSp = 0.0;
    $origUnitUsd = 0.0;
    $effPackSp = ShareCartService::packageSalePriceSp($item);
    $effPackUsd = ShareCartService::packageSalePriceUsd($item);
    $effUnitSp = ShareCartService::unitSalePriceSp($item);
    $effUnitUsd = ShareCartService::unitSalePriceUsd($item);
}
$badge = trim((string) ($item['offer_badge'] ?? ''));
$hasPackSp = $effPackSp > 0 || $origPackSp > 0;
$hasPackUsd = $effPackUsd > 0 || $origPackUsd > 0;
$hasUnitSp = $effUnitSp > 0 || $origUnitSp > 0;
$hasUnitUsd = $effUnitUsd > 0 || $origUnitUsd > 0;
?>
<div class="offer-price-block" data-store-price-block>
  <?php if ($badge !== ''): ?>
    <span class="offer-price-block__badge">
      <span class="material-symbols-outlined text-xs" aria-hidden="true">sell</span>
      <?= h($badge) ?>
    </span>
  <?php endif; ?>

  <?php if ($hasPackSp || $hasUnitSp): ?>
    <div class="store-price-currency store-price-currency--syp">
      <?php if ($hasPackSp): ?>
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر <?= h($packageUnit) ?></span>
          <div class="offer-price-block__values">
            <?php if ($hasOffer && $origPackSp > $effPackSp): ?>
              <span class="offer-price-block__old"><span class="store-num" dir="ltr"><?= format_money($origPackSp, true) ?></span> ل.س</span>
            <?php endif; ?>
            <span class="offer-price-block__amount offer-price-block__amount--syp"><span class="store-num" dir="ltr"><?= format_money($effPackSp > 0 ? $effPackSp : $origPackSp, true) ?></span> <small>ل.س</small></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($hasUnitSp): ?>
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر <?= h($primaryUnit) ?></span>
          <div class="offer-price-block__values">
            <?php if ($hasOffer && $origUnitSp > $effUnitSp): ?>
              <span class="offer-price-block__old"><span class="store-num" dir="ltr"><?= format_money($origUnitSp, true) ?></span> ل.س</span>
            <?php endif; ?>
            <span class="offer-price-block__amount offer-price-block__amount--unit"><span class="store-num" dir="ltr"><?= format_money($effUnitSp > 0 ? $effUnitSp : $origUnitSp, true) ?></span> <small>ل.س</small></span>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($hasPackUsd || $hasUnitUsd): ?>
    <div class="store-price-currency store-price-currency--usd">
      <?php if ($hasPackUsd): ?>
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر <?= h($packageUnit) ?></span>
          <div class="offer-price-block__values">
            <?php if ($hasOffer && $origPackUsd > $effPackUsd): ?>
              <span class="offer-price-block__old">$<span class="store-num" dir="ltr"><?= number_format($origPackUsd, 2, '.', ',') ?></span></span>
            <?php endif; ?>
            <span class="offer-price-block__amount offer-price-block__amount--usd">$<span class="store-num" dir="ltr"><?= number_format($effPackUsd > 0 ? $effPackUsd : $origPackUsd, 2, '.', ',') ?></span></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($hasUnitUsd): ?>
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر <?= h($primaryUnit) ?></span>
          <div class="offer-price-block__values">
            <?php if ($hasOffer && $origUnitUsd > $effUnitUsd): ?>
              <span class="offer-price-block__old">$<span class="store-num" dir="ltr"><?= number_format($origUnitUsd, 2, '.', ',') ?></span></span>
            <?php endif; ?>
            <span class="offer-price-block__amount offer-price-block__amount--unit">$<span class="store-num" dir="ltr"><?= number_format($effUnitUsd > 0 ? $effUnitUsd : $origUnitUsd, 2, '.', ',') ?></span></span>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
