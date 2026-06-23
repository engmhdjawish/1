<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StoreCartService;

/** @var array<string, mixed> $item */
/** @var array{show_images?: bool, show_price?: bool, show_quantity?: bool, price_mode?: string} $displayOptions */
/** @var bool $linkToDetail */
/** @var string|null $productReturnUrl */
/** @var string|null $productOfferSlug */
/** @var bool $useQuickView */
/** @var list<string>|null $quickViewGuids */

$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$linkToDetail = (bool) ($linkToDetail ?? true);
$useQuickView = (bool) ($useQuickView ?? true);
$quickViewGuids = is_array($quickViewGuids ?? null)
    ? array_values(array_filter(array_map('strval', $quickViewGuids), static fn (string $g): bool => trim($g) !== ''))
    : [];
$productReturnUrl = isset($productReturnUrl) ? (string) $productReturnUrl : null;
$productOfferSlug = isset($productOfferSlug) ? trim((string) $productOfferSlug) : null;
if ($productOfferSlug === '') {
    $productOfferSlug = null;
}
$showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
$showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
$showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
$showQuantity = (bool) ($displayOptions['show_quantity'] ?? false);
$allowCart = (bool) ($displayOptions['allow_cart'] ?? false);
$capturePrices = (bool) ($displayOptions['show_price'] ?? false);

$contextOffer = null;
if ($productOfferSlug !== null) {
    $contextOffer = SpecialOfferService::activeOfferBySlug($productOfferSlug);
}
$guid = material_guid($item);
if ($guid !== '') {
    $overlay = SpecialOfferService::pricingOverlay($item, $contextOffer);
    if (!empty($overlay['has_offer'])) {
        $item = array_merge($item, $overlay);
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
$packagesAvailable = 0.0;
if ($showQuantity) {
    $packagesAvailable = packages_available_display($item);
}
$guid = material_guid($item);
$imageGuid = material_image_guid($item);
$detailUrl = $guid !== '' ? product_url($guid, $productReturnUrl, $productOfferSlug) : '';
$quickViewGuidsJson = $quickViewGuids !== [] ? json_encode($quickViewGuids, JSON_UNESCAPED_UNICODE) : '';
$materialCode = trim((string) ($item['materialCode'] ?? $item['code'] ?? ''));
?>
<article class="store-product-card">
  <?php if ($linkToDetail && $detailUrl !== ''): ?>
    <a
      href="<?= h($detailUrl) ?>"
      class="store-product-card__link"
      <?php if ($useQuickView): ?>
        data-quick-view="1"
        data-product-guid="<?= h($guid) ?>"
        data-offer-slug="<?= h((string) ($productOfferSlug ?? '')) ?>"
        <?php if ($quickViewGuidsJson !== ''): ?>data-quick-view-guids="<?= h($quickViewGuidsJson) ?>"<?php endif; ?>
        <?php if ($productReturnUrl !== null && $productReturnUrl !== ''): ?>data-return-url="<?= h($productReturnUrl) ?>"<?php endif; ?>
      <?php endif; ?>
    >
  <?php endif; ?>
    <?php if ($showImages): ?>
      <div class="store-product-card__media">
        <?php
          $material = $item;
          $variant = 'card';
          require __DIR__ . '/material-image-frame.php';
        ?>
      </div>
    <?php endif; ?>
    <div class="store-product-card__body">
      <?php if ($materialCode !== ''): ?>
        <div class="store-product-card__code"><?= h($materialCode) ?></div>
      <?php endif; ?>
      <h3 class="store-product-card__title"><?= h((string) ($item['name'] ?? '-')) ?></h3>
      <?php if (!empty($item['manufacturer']) || !empty($item['materialType'])): ?>
        <div class="store-product-card__meta">
          <?= h((string) ($item['manufacturer'] ?? '')) ?><?= !empty($item['materialType']) ? ' · ' . h((string) $item['materialType']) : '' ?>
        </div>
      <?php endif; ?>
      <div class="store-product-card__price">
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
      </div>
      <?php if ($showQuantity): ?>
        <div class="store-product-card__stock">
          متاح: <?= number_format($packagesAvailable, 0, '.', ',') ?> <?= h($packageUnit) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php if ($linkToDetail && $detailUrl !== ''): ?></a><?php endif; ?>
  <?php if ($allowCart): ?>
    <div class="store-product-card__footer">
      <?php
        $cartItems = StoreCartService::items();
        $cartQtyForItem = $guid !== '' ? (int) round((float) ($cartItems[$guid]['quantity'] ?? 0)) : 0;
        require __DIR__ . '/store-add-to-cart-form.php';
      ?>
    </div>
  <?php endif; ?>
</article>
