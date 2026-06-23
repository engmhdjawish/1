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
$packagesAvailable = $showQuantity ? packages_available_display($item) : 0.0;
$guid = material_guid($item);
$detailUrl = $guid !== '' ? product_url($guid, $productReturnUrl, $productOfferSlug) : '';
$quickViewGuidsJson = $quickViewGuids !== [] ? json_encode($quickViewGuids, JSON_UNESCAPED_UNICODE) : '';
$materialCode = trim((string) ($item['materialCode'] ?? $item['code'] ?? ''));
$materialType = trim((string) ($item['materialType'] ?? ''));
$manufacturer = trim((string) ($item['manufacturer'] ?? ''));
$showAnyPrice = ($showPriceSyp || $showPriceUsd) && (bool) ($displayOptions['show_price'] ?? false);
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
        <?php if ($materialType !== ''): ?>
          <span class="store-product-card__chip"><?= h($materialType) ?></span>
        <?php endif; ?>
        <?php if ($materialCode !== ''): ?>
          <span class="store-product-card__code-badge" dir="ltr"><?= h($materialCode) ?></span>
        <?php endif; ?>
        <?php
          $material = $item;
          $variant = 'card';
          require __DIR__ . '/material-image-frame.php';
        ?>
      </div>
    <?php endif; ?>
    <div class="store-product-card__body">
      <h3 class="store-product-card__title"><?= h((string) ($item['name'] ?? '-')) ?></h3>
      <?php if ($manufacturer !== ''): ?>
        <div class="store-product-card__brand"><?= h($manufacturer) ?></div>
      <?php endif; ?>
      <div class="store-product-card__pack">
        تعبئة <?= h(format_packaging($packaging)) ?> <?= h($primaryUnit) ?> / <?= h($packageUnit) ?>
      </div>
      <?php if ($showAnyPrice): ?>
        <div class="store-product-card__price">
          <?php require __DIR__ . '/offer-price-block.php'; ?>
        </div>
      <?php endif; ?>
      <?php if ($showQuantity): ?>
        <div class="store-product-card__stock">
          <span class="material-symbols-outlined text-sm" aria-hidden="true">inventory</span>
          <?= number_format($packagesAvailable, 0, '.', ',') ?> <?= h($packageUnit) ?>
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
