<?php

declare(strict_types=1);

use Portal\Auth\CustomerSession;
use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StoreCartService;

/** @var array<string, mixed> $product */
/** @var array<string, mixed> $displayOptions */
/** @var string|null $returnUrl */
/** @var string|null $offerSlug */
/** @var array{ok?: bool, message?: string}|string|null $cartNotice */

$product = is_array($product ?? null) ? $product : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$cartNoticeMessage = '';
$cartNoticeOk = true;
if (is_array($cartNotice ?? null)) {
    $cartNoticeMessage = (string) ($cartNotice['message'] ?? '');
    $cartNoticeOk = (bool) ($cartNotice['ok'] ?? false);
} elseif (isset($cartNotice) && is_string($cartNotice)) {
    $cartNoticeMessage = $cartNotice;
}
$allowCart = (bool) ($displayOptions['allow_cart'] ?? false);
$offerSlug = trim((string) ($offerSlug ?? $_GET['offer'] ?? ''));
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
$showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
$showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
$showQuantity = (bool) ($displayOptions['show_quantity'] ?? false);
$showImages = (bool) ($displayOptions['show_images'] ?? true);

$contextOffer = $offerSlug !== '' ? SpecialOfferService::activeOfferBySlug($offerSlug) : null;
$guid = material_guid($product);
if ($guid !== '') {
    $overlay = SpecialOfferService::pricingOverlay($product, $contextOffer);
    if (!empty($overlay['has_offer'])) {
        $product = array_merge($product, $overlay);
    }
}

$packaging = ShareCartService::packaging($product);
$primaryUnit = ShareCartService::primaryUnitLabel($product);
$packageUnit = ShareCartService::packageUnitLabel($product);
$hasOffer = !empty($product['has_offer']);
$unitSaleSp = ShareCartService::unitSalePriceSp($product);
$unitSaleUsd = ShareCartService::unitSalePriceUsd($product);
$packageSaleSp = ShareCartService::packageSalePriceSp($product);
$packageSaleUsd = ShareCartService::packageSalePriceUsd($product);
$origPackSp = $hasOffer ? (float) ($product['original_package_sale_price_sp'] ?? 0) : 0.0;
$origPackUsd = $hasOffer ? (float) ($product['original_package_sale_price_usd'] ?? 0) : 0.0;
$origUnitSp = $hasOffer ? (float) ($product['original_unit_sale_price_sp'] ?? 0) : 0.0;
$offerBadge = trim((string) ($product['offer_badge'] ?? ''));
$offer = is_array($product['offer'] ?? null) ? $product['offer'] : null;
$offerMin = $offer !== null && is_numeric((string) ($offer['min_packages'] ?? ''))
    ? (float) $offer['min_packages'] : null;
$offerMax = $offer !== null && is_numeric((string) ($offer['max_packages'] ?? ''))
    ? (float) $offer['max_packages'] : null;
$warehouseQty = (float) ($product['warehouseQuantity'] ?? 0);
$packagesAvailable = ($allowCart || $showQuantity) ? packages_available_display($product) : 0.0;
$outOfStock = $allowCart && $packagesAvailable <= 0;
$materialCode = trim((string) ($product['materialCode'] ?? $product['code'] ?? ''));
$productName = trim((string) ($product['name'] ?? 'مادة'));
$manufacturer = trim((string) ($product['manufacturer'] ?? ''));

$returnUrl = safe_return_url($returnUrl ?? ($_GET['return'] ?? '/store.php'));
$backLabel = return_link_label($returnUrl);

$specs = array_filter([
    'النوع' => (string) ($product['materialType'] ?? ''),
    'الفئة العمرية' => (string) ($product['ageCategory'] ?? ''),
    'القياس' => (string) ($product['sizeRange'] ?? ''),
    'الشركة' => (string) ($product['manufacturer'] ?? ''),
    'بلد المنشأ' => (string) ($product['countryOfOrigin'] ?? ''),
    'المجموعة' => (string) ($product['groupName'] ?? ''),
    'التعبئة' => format_packaging($packaging) . ' ' . $primaryUnit . ' / ' . $packageUnit,
], static fn (string $value): bool => trim($value) !== '');
?>
<nav class="store-breadcrumb" aria-label="مسار التنقل">
  <a href="/index.php">الرئيسية</a>
  <span class="store-breadcrumb__sep" aria-hidden="true">›</span>
  <a href="/store.php">المتجر</a>
  <span class="store-breadcrumb__sep" aria-hidden="true">›</span>
  <span class="store-breadcrumb__current" title="<?= h($productName) ?>"><?= h($productName) ?></span>
</nav>

<?php if ($cartNoticeMessage !== ''): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $cartNoticeOk ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>"><?= h($cartNoticeMessage) ?></p>
<?php endif; ?>

<article
  class="store-product-detail"
  data-analytics-product="1"
  data-product-guid="<?= h($guid) ?>"
  data-product-code="<?= h($materialCode) ?>"
  data-product-name="<?= h($productName) ?>"
  data-analytics-label="<?= h('عرض صنف: ' . $productName . ($materialCode !== '' ? ' (' . $materialCode . ')' : '')) ?>"
>
  <div class="store-product-detail__layout">
  <?php if ($showImages): ?>
    <div class="store-product-detail__gallery">
      <?php
        $material = $product;
        $variant = 'detail';
        $thumb = false;
        require __DIR__ . '/partials/material-image-frame.php';
      ?>
    </div>
  <?php endif; ?>

  <div class="store-product-detail__info">
    <?php if ($materialCode !== ''): ?>
      <div class="store-product-detail__code"><?= h($materialCode) ?></div>
    <?php endif; ?>
    <h1 class="store-product-detail__title"><?= h($productName) ?></h1>
    <?php if ($manufacturer !== ''): ?>
      <p class="store-product-detail__brand">الشركة: <strong><?= h($manufacturer) ?></strong></p>
    <?php endif; ?>
    <div class="store-product-detail__pack">
      <span class="material-symbols-outlined text-base" aria-hidden="true">inventory_2</span>
      التعبئة: <?= h(format_packaging($packaging)) ?> <?= h($primaryUnit) ?> / <?= h($packageUnit) ?>
    </div>

    <div class="store-buybox">
      <?php if ($showPriceSyp || $showPriceUsd): ?>
        <?php if ($offerBadge !== ''): ?>
          <span class="inline-flex mb-2 px-2.5 py-1 rounded-full bg-red-600 text-white text-xs font-extrabold"><?= h($offerBadge) ?></span>
        <?php endif; ?>

        <?php if ($showPriceSyp && ($packageSaleSp > 0 || $unitSaleSp > 0)): ?>
          <?php if ($hasOffer && $origPackSp > $packageSaleSp): ?>
            <div class="store-buybox__price-old"><?= format_money($origPackSp, true) ?> ل.س</div>
          <?php endif; ?>
          <div class="store-buybox__price-main">
            <?= format_money($packageSaleSp, true) ?>
            <span class="currency">ل.س / <?= h($packageUnit) ?></span>
          </div>
          <div class="text-xs text-gray-500 mt-1">
            سعر <?= h($primaryUnit) ?>:
            <?php if ($hasOffer && $origUnitSp > $unitSaleSp): ?>
              <span class="line-through text-gray-400"><?= format_money($origUnitSp, true) ?></span>
            <?php endif; ?>
            <?= format_money($unitSaleSp, true) ?> ل.س
          </div>
        <?php endif; ?>

        <?php if ($showPriceUsd && $packageSaleUsd > 0): ?>
          <?php if ($hasOffer && $origPackUsd > $packageSaleUsd): ?>
            <div class="store-buybox__price-old">$<?= number_format($origPackUsd, 2, '.', ',') ?></div>
          <?php endif; ?>
          <div class="store-buybox__price-usd">$<?= number_format($packageSaleUsd, 2, '.', ',') ?> / <?= h($packageUnit) ?></div>
        <?php endif; ?>

        <?php if ($hasOffer && ($offerMin !== null || $offerMax !== null)): ?>
          <p class="text-xs text-amber-800 mt-2 pt-2 border-t border-gray-200">
            حدود العرض:
            <?php if ($offerMin !== null): ?>الحد الأدنى <?= h(SpecialOfferService::formatQuantityLabel($offerMin)) ?> <?= h($packageUnit) ?><?php endif; ?>
            <?php if ($offerMin !== null && $offerMax !== null): ?> — <?php endif; ?>
            <?php if ($offerMax !== null): ?>الحد الأقصى <?= h(SpecialOfferService::formatQuantityLabel($offerMax)) ?> <?= h($packageUnit) ?><?php endif; ?>
          </p>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-sm text-gray-500">الأسعار غير متاحة لحسابك الحالي. سجّل دخولك كعميل مفعّل أو تواصل معنا.</p>
      <?php endif; ?>

      <?php if ($showQuantity || $allowCart): ?>
        <?php if ($outOfStock): ?>
          <div class="store-buybox__stock store-buybox__stock--out">
            <span class="material-symbols-outlined text-base" aria-hidden="true">inventory_2</span>
            نفدت الكمية المتاحة للطلب حالياً
          </div>
        <?php else: ?>
          <div class="store-buybox__stock <?= $packagesAvailable <= 2 ? 'store-buybox__stock--low' : '' ?>">
            <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
            متاح: <?= number_format($packagesAvailable, 0, '.', ',') ?> <?= h($packageUnit) ?>
            <?php if ($showQuantity): ?>
              <span class="text-gray-400 font-normal">(<?= number_format($warehouseQty, 0, '.', ',') ?> <?= h($primaryUnit) ?>)</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($allowCart && !$outOfStock): ?>
        <?php
          $item = $product;
          $cartItems = StoreCartService::items();
          $cartQtyForItem = $guid !== '' ? (int) round((float) ($cartItems[$guid]['quantity'] ?? 0)) : 0;
          require __DIR__ . '/partials/store-add-to-cart-form.php';
        ?>
      <?php endif; ?>

      <ul class="store-buybox__trust">
        <li><span class="material-symbols-outlined text-base text-emerald-600" aria-hidden="true">verified</span> طلب آمن عبر المتجر الإلكتروني</li>
        <li><span class="material-symbols-outlined text-base text-emerald-600" aria-hidden="true">local_shipping</span> سنتواصل معك لتأكيد الطلب والتوصيل</li>
      </ul>
    </div>

    <a href="<?= h($returnUrl) ?>" class="store-btn store-btn--secondary w-fit">
      <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
      <?= h($backLabel) ?>
    </a>
    <?php if (!CustomerSession::check()): ?>
      <p class="text-xs text-gray-500">لديك حساب؟ <a href="<?= h(portal_login_url('customer')) ?>" class="text-primary font-bold">سجّل دخولك</a> لمتابعة طلباتك.</p>
    <?php endif; ?>
  </div>
  </div>

  <?php if ($specs !== []): ?>
    <section class="store-specs">
      <h2 class="store-specs__title">مواصفات المنتج</h2>
      <table class="store-specs__table">
        <tbody>
          <?php foreach ($specs as $label => $value): ?>
            <tr>
              <th scope="row"><?= h($label) ?></th>
              <td><?= h($value) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>
</article>
