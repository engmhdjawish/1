<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */

$showPriceSyp = $showPriceSyp ?? true;
$showPriceUsd = $showPriceUsd ?? true;
$hasOffer = !empty($item['has_offer']);
$packaging = ShareCartService::packaging($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);

if ($hasOffer) {
    $origPackSp = (float) ($item['original_package_sale_price_sp'] ?? 0);
    $origPackUsd = (float) ($item['original_package_sale_price_usd'] ?? 0);
    $effPackSp = (float) ($item['effective_package_sale_price_sp'] ?? ShareCartService::packageSalePriceSp($item));
    $effPackUsd = (float) ($item['effective_package_sale_price_usd'] ?? ShareCartService::packageSalePriceUsd($item));
} else {
    $origPackSp = 0.0;
    $origPackUsd = 0.0;
    $effPackSp = ShareCartService::packageSalePriceSp($item);
    $effPackUsd = ShareCartService::packageSalePriceUsd($item);
}
$badge = trim((string) ($item['offer_badge'] ?? ''));
?>
<?php if ($badge !== ''): ?>
  <span class="inline-flex mb-1 px-2 py-0.5 rounded-full bg-red-600 text-white text-[10px] font-extrabold"><?= h($badge) ?></span>
<?php endif; ?>
<?php if ($showPriceSyp && ($effPackSp > 0 || $origPackSp > 0)): ?>
  <div class="mt-2">
    <?php if ($hasOffer && $origPackSp > $effPackSp): ?>
      <div class="text-xs text-gray-400 line-through"><?= format_money($origPackSp, true) ?> ل.س</div>
    <?php endif; ?>
    <div class="text-primary font-extrabold text-base"><?= format_money($effPackSp > 0 ? $effPackSp : $origPackSp, true) ?> ل.س
      <span class="text-xs font-normal text-gray-500">/ <?= h($packageUnit) ?></span>
    </div>
  </div>
<?php endif; ?>
<?php if ($showPriceUsd && ($effPackUsd > 0 || $origPackUsd > 0)): ?>
  <div class="mt-1">
    <?php if ($hasOffer && $origPackUsd > $effPackUsd): ?>
      <div class="text-xs text-gray-400 line-through">$<?= number_format($origPackUsd, 2, '.', ',') ?></div>
    <?php endif; ?>
    <div class="text-emerald-700 font-bold text-sm">$<?= number_format($effPackUsd > 0 ? $effPackUsd : $origPackUsd, 2, '.', ',') ?>
      <span class="text-xs font-normal text-gray-500">/ <?= h($packageUnit) ?></span>
    </div>
  </div>
<?php endif; ?>
