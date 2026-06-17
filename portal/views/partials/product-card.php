<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;

/** @var array<string, mixed> $item */
/** @var array{show_images?: bool, show_price?: bool, show_quantity?: bool, price_mode?: string} $displayOptions */
/** @var bool $linkToDetail */
/** @var string|null $productReturnUrl */

$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$linkToDetail = (bool) ($linkToDetail ?? true);
$productReturnUrl = isset($productReturnUrl) ? (string) $productReturnUrl : null;
$showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
$showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
$showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
$showQuantity = (bool) ($displayOptions['show_quantity'] ?? false);

if (empty($item['has_offer'])) {
    $guid = material_guid($item);
    if ($guid !== '') {
        $overlay = SpecialOfferService::pricingOverlay($item);
        if (!empty($overlay['has_offer'])) {
            $item = array_merge($item, $overlay);
        }
    }
}

$packaging = ShareCartService::packaging($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$unitSaleSp = ShareCartService::unitSalePriceSp($item);
$unitSaleUsd = ShareCartService::unitSalePriceUsd($item);
$packageSaleSp = ShareCartService::packageSalePriceSp($item);
$packageSaleUsd = ShareCartService::packageSalePriceUsd($item);
$warehouseQty = (float) ($item['warehouseQuantity'] ?? 0);
$packagesAvailable = packages_available_display($item);
$guid = material_guid($item);
$imageGuid = material_image_guid($item);
$detailUrl = $guid !== '' ? product_url($guid, $productReturnUrl) : '';
?>
<article class="product-card border border-gray-200 rounded-2xl bg-white shadow-sm overflow-hidden flex flex-col h-full transition hover:shadow-md hover:-translate-y-0.5">
  <?php if ($linkToDetail && $detailUrl !== ''): ?><a href="<?= h($detailUrl) ?>" class="flex flex-col flex-1 text-inherit no-underline"><?php endif; ?>
    <?php if ($showImages): ?>
      <div class="h-40 bg-gray-100 flex items-center justify-center overflow-hidden">
        <?php if ($imageGuid !== ''): ?>
          <img
            src="/api/image.php?id=<?= urlencode($imageGuid) ?>&thumb=1"
            alt="<?= h((string) ($item['name'] ?? '')) ?>"
            class="h-40 w-full object-cover"
            loading="lazy"
          >
        <?php else: ?>
          <span class="material-symbols-outlined text-gray-300 text-5xl" aria-hidden="true">inventory_2</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="p-4 flex flex-col flex-1">
      <div class="font-bold text-sm line-clamp-2 min-h-[2.5rem]"><?= h((string) ($item['name'] ?? '-')) ?></div>
      <div class="text-xs text-gray-500 mt-1"><?= h((string) ($item['materialCode'] ?? $item['code'] ?? '')) ?></div>
      <?php if (!empty($item['manufacturer']) || !empty($item['materialType'])): ?>
        <div class="text-xs text-gray-500 mt-1">
          <?= h((string) ($item['manufacturer'] ?? '')) ?><?= !empty($item['materialType']) ? ' • ' . h((string) $item['materialType']) : '' ?>
        </div>
      <?php endif; ?>
      <div class="mt-2 inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-gray-700 w-fit">
        تعبئة <?= h(format_packaging($packaging)) ?> <?= h($primaryUnit) ?>/<?= h($packageUnit) ?>
      </div>
      <?php if ($showPriceSyp && ($packageSaleSp > 0 || $unitSaleSp > 0)): ?>
        <?php
          $showPriceSypBlock = true;
          $showPriceUsdBlock = false;
          require __DIR__ . '/offer-price-block.php';
        ?>
      <?php endif; ?>
      <?php if ($showPriceUsd && ($packageSaleUsd > 0 || $unitSaleUsd > 0)): ?>
        <?php
          $showPriceSypBlock = false;
          $showPriceUsdBlock = true;
          require __DIR__ . '/offer-price-block.php';
        ?>
      <?php endif; ?>
      <?php if ($showQuantity): ?>
        <div class="text-xs text-gray-500 mt-2">
          متاح: <?= number_format($packagesAvailable, 0, '.', ',') ?> <?= h($packageUnit) ?>
        </div>
      <?php endif; ?>
      <?php if ($linkToDetail && $detailUrl !== ''): ?>
        <span class="mt-auto pt-3 text-primary text-xs font-bold inline-flex items-center gap-1">
          عرض التفاصيل
          <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
        </span>
      <?php endif; ?>
    </div>
  <?php if ($linkToDetail && $detailUrl !== ''): ?></a><?php endif; ?>
</article>
