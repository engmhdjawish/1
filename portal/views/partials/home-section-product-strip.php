<?php

declare(strict_types=1);

use Portal\Services\SpecialOfferService;
use Portal\Services\StoreCartService;

/** @var array<string, mixed> $section */
/** @var list<array<string, mixed>> $products */
/** @var array{show_price: bool, show_quantity: bool, allow_cart: bool, allow_order: bool, show_images: bool, price_mode: string} $storeCatalogDisplay */

$displayOptions = is_array($section['display_options'] ?? null) ? $section['display_options'] : [];
$priceState = section_price_display_state($displayOptions, $storeCatalogDisplay, 'home');
$showImages = (bool) ($priceState['show_images'] ?? true);
$showAnyPrice = (bool) ($priceState['show_any_price'] ?? false);
$isOfferSection = !empty($section['is_offer_section']);
$sectionSlug = trim((string) ($section['slug'] ?? ''));
$sectionReturnUrl = home_section_return_url($section);
$sectionOfferSlug = $isOfferSection && $sectionSlug !== '' ? $sectionSlug : null;
$previewDisplayOptions = $priceState['preview_display_options'];
$homeAllowCart = (bool) ($previewDisplayOptions['allow_cart'] ?? false);
$contextOffer = $isOfferSection && $sectionSlug !== ''
    ? SpecialOfferService::activeOfferBySlug($sectionSlug)
    : null;
$cartItems = $homeAllowCart ? StoreCartService::items() : [];

if ($products === []) {
    echo '<div class="home-section__empty">لا توجد منتجات في هذا القسم حالياً.</div>';

    return;
}
?>
<div class="home-strip">
  <?php foreach ($products as $item): ?>
    <?php
      if (!is_array($item)) {
          continue;
      }
      $guid = material_guid($item);
      if ($guid !== '' && empty($item['has_offer'])) {
          $overlay = SpecialOfferService::pricingOverlay($item, $contextOffer);
          if (!empty($overlay['has_offer'])) {
              $item = array_merge($item, $overlay);
          }
      }
      $cartQtyForItem = $homeAllowCart && $guid !== ''
          ? (float) ($cartItems[$guid]['quantity'] ?? 0)
          : 0.0;
      $previewPayload = $guid !== ''
          ? product_preview_payload(
              $item,
              $previewDisplayOptions,
              $cartQtyForItem,
              $sectionReturnUrl,
              $sectionOfferSlug,
              $isOfferSection ? null : ($sectionSlug !== '' ? $sectionSlug : null)
          )
          : null;
      $previewJson = $previewPayload !== null
          ? json_encode($previewPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
          : '';
    ?>
    <article
      class="home-product-card"
      <?php if ($guid !== '' && $previewJson !== ''): ?>
        data-store-preview-card
        data-preview-guid="<?= h($guid) ?>"
        data-preview="<?= h($previewJson) ?>"
      <?php endif; ?>
    >
      <?php if ($showImages): ?>
        <button
          type="button"
          class="home-product-card__media home-product-card__media--preview"
          data-store-product-preview
          title="معاينة الصورة والأسعار"
        >
          <span class="home-product-card__zoom-hint material-symbols-outlined" aria-hidden="true">zoom_in</span>
          <?php $material = $item; $variant = 'strip'; require __DIR__ . '/material-image-frame.php'; ?>
        </button>
      <?php endif; ?>
      <div class="home-product-card__body">
        <div class="home-product-card__name"><?= h((string) ($item['name'] ?? '-')) ?></div>
        <?php if ($showAnyPrice): ?>
          <?php require __DIR__ . '/offer-price-block.php'; ?>
        <?php elseif (!(bool) ($storeCatalogDisplay['show_price'] ?? false)): ?>
          <?php $context = 'card'; require __DIR__ . '/store-price-lock.php'; ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
