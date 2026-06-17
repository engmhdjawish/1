<?php

declare(strict_types=1);

use Portal\Auth\CustomerSession;
use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;

/** @var array<string, mixed> $product */
/** @var array<string, mixed> $displayOptions */
/** @var string|null $returnUrl */
/** @var string|null $offerSlug */

$product = is_array($product ?? null) ? $product : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
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
$packagesAvailable = $showQuantity ? packages_available_display($product) : 0.0;

$returnUrl = safe_return_url($returnUrl ?? ($_GET['return'] ?? '/store.php'));
$backLabel = return_link_label($returnUrl);

$specs = array_filter([
    'النوع' => (string) ($product['materialType'] ?? ''),
    'الفئة العمرية' => (string) ($product['ageCategory'] ?? ''),
    'القياس' => (string) ($product['sizeRange'] ?? ''),
    'الشركة' => (string) ($product['manufacturer'] ?? ''),
    'بلد المنشأ' => (string) ($product['countryOfOrigin'] ?? ''),
    'المجموعة' => (string) ($product['groupName'] ?? ''),
], static fn (string $value): bool => trim($value) !== '');
$imageGuid = material_image_guid($product);
?>
<section class="mb-4">
  <a href="<?= h($returnUrl) ?>" class="text-sm text-primary font-semibold inline-flex items-center gap-1">
    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
    <?= h($backLabel) ?>
  </a>
</section>

<article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">
    <?php if ($showImages): ?>
      <div class="bg-gray-100 min-h-[280px] lg:min-h-[420px] flex items-center justify-center">
        <?php if ($imageGuid !== ''): ?>
          <img
            src="/api/image.php?id=<?= urlencode($imageGuid) ?>"
            alt="<?= h((string) ($product['name'] ?? '')) ?>"
            class="w-full h-full max-h-[520px] object-contain"
          >
        <?php else: ?>
          <span class="material-symbols-outlined text-gray-300 text-7xl" aria-hidden="true">inventory_2</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="p-6 md:p-8 flex flex-col gap-4">
      <div>
        <p class="text-xs text-gray-500 mb-1"><?= h((string) ($product['materialCode'] ?? $product['code'] ?? '')) ?></p>
        <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900"><?= h((string) ($product['name'] ?? 'مادة')) ?></h1>
        <?php if (!empty($product['manufacturer'])): ?>
          <p class="text-sm text-gray-600 mt-2"><?= h((string) $product['manufacturer']) ?></p>
        <?php endif; ?>
      </div>

      <div class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-sm font-bold text-gray-700 w-fit">
        التعبئة: <?= h(format_packaging($packaging)) ?> <?= h($primaryUnit) ?> / <?= h($packageUnit) ?>
      </div>

      <?php if ($showPriceSyp || $showPriceUsd): ?>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2">
          <?php if ($offerBadge !== ''): ?>
            <span class="inline-flex mb-1 px-2.5 py-1 rounded-full bg-red-600 text-white text-xs font-extrabold"><?= h($offerBadge) ?></span>
          <?php endif; ?>
          <?php if ($showPriceSyp): ?>
            <div>
              <div class="text-xs text-gray-500">سعر <?= h($primaryUnit) ?></div>
              <?php if ($hasOffer && $origUnitSp > $unitSaleSp): ?>
                <div class="text-xs text-gray-400 line-through"><?= format_money($origUnitSp, true) ?> ل.س</div>
              <?php endif; ?>
              <div class="font-bold"><?= format_money($unitSaleSp, true) ?> ل.س</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">سعر <?= h($packageUnit) ?></div>
              <?php if ($hasOffer && $origPackSp > $packageSaleSp): ?>
                <div class="text-sm text-gray-400 line-through"><?= format_money($origPackSp, true) ?> ل.س</div>
              <?php endif; ?>
              <div class="text-primary text-2xl font-extrabold"><?= format_money($packageSaleSp, true) ?> ل.س</div>
            </div>
          <?php endif; ?>
          <?php if ($showPriceUsd): ?>
            <div class="pt-2 border-t border-gray-200">
              <div class="text-xs text-gray-500">سعر <?= h($packageUnit) ?> بالدولار</div>
              <?php if ($hasOffer && $origPackUsd > $packageSaleUsd): ?>
                <div class="text-sm text-gray-400 line-through">$<?= number_format($origPackUsd, 2, '.', ',') ?></div>
              <?php endif; ?>
              <div class="text-emerald-700 text-xl font-extrabold">$<?= number_format($packageSaleUsd, 2, '.', ',') ?></div>
            </div>
          <?php endif; ?>
          <?php if ($hasOffer && ($offerMin !== null || $offerMax !== null)): ?>
            <p class="text-xs text-amber-800 pt-2 border-t border-gray-200">
              حدود العرض:
              <?php if ($offerMin !== null): ?>الحد الأدنى <?= h(SpecialOfferService::formatQuantityLabel($offerMin)) ?> <?= h($packageUnit) ?><?php endif; ?>
              <?php if ($offerMin !== null && $offerMax !== null): ?> — <?php endif; ?>
              <?php if ($offerMax !== null): ?>الحد الأقصى <?= h(SpecialOfferService::formatQuantityLabel($offerMax)) ?> <?= h($packageUnit) ?><?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-500 rounded-xl border border-dashed border-gray-300 px-4 py-3">الأسعار غير متاحة لحسابك الحالي. سجّل دخولك كعميل مفعّل أو تواصل معنا.</p>
      <?php endif; ?>

      <?php if ($showQuantity): ?>
        <div class="text-sm text-gray-700">
          <span class="font-bold">المتوفر:</span>
          <?= number_format($packagesAvailable, 0, '.', ',') ?> <?= h($packageUnit) ?>
          <span class="text-gray-400">(<?= number_format($warehouseQty, 0, '.', ',') ?> <?= h($primaryUnit) ?>)</span>
        </div>
      <?php endif; ?>

      <div class="flex flex-wrap gap-2 pt-2">
        <a href="<?= h($returnUrl) ?>" class="h-11 inline-flex items-center justify-center rounded-xl border border-gray-300 px-5 text-sm font-bold"><?= h($backLabel) ?></a>
        <?php if (!CustomerSession::check()): ?>
          <a href="/login.php?type=customer" class="h-11 inline-flex items-center justify-center rounded-xl bg-primary text-white px-5 text-sm font-bold">دخول العملاء</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($specs !== []): ?>
    <div class="border-t border-gray-200 p-6 md:p-8">
      <h2 class="font-bold text-lg mb-4">المواصفات</h2>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        <?php foreach ($specs as $label => $value): ?>
          <div class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3">
            <dt class="text-xs text-gray-500 mb-1"><?= h($label) ?></dt>
            <dd class="font-semibold"><?= h($value) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </div>
  <?php endif; ?>
</article>
