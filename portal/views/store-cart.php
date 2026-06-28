<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $cartItems */
/** @var list<array<string, mixed>> $pricedCartItems */
/** @var list<array<string, mixed>> $unpricedCartItems */
/** @var list<array<string, mixed>> $unavailableItems */
/** @var array{total_sp: float, total_usd: float} $totals */
/** @var array{total_sp: float, total_usd: float} $displayTotals */
/** @var bool $allowCart */
/** @var bool $allowOrder */
/** @var bool $showPrice */
/** @var bool $customerShowsPrices */
/** @var bool $globalShowsPrices */
/** @var bool $hasMixedPricing */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var string $priceMode */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string $defaultGuestName */
/** @var string $defaultGuestPhone */
/** @var bool $isLoggedInCustomer */
/** @var float|null $maxPackagesPerMaterial */
/** @var string|null $maxPackagesLabel */
/** @var list<string> $stockNotices */

use Portal\Services\SpecialOfferService;

$maxPackagesLabel = $maxPackagesPerMaterial !== null
    ? SpecialOfferService::formatQuantityLabel($maxPackagesPerMaterial)
    : null;
?>
<div
  class="store-cart-page max-w-6xl mx-auto"
  data-store-cart-page="1"
  data-default-name="<?= h($defaultGuestName) ?>"
  data-default-phone="<?= h($defaultGuestPhone) ?>"
  data-logged-in="<?= $isLoggedInCustomer ? '1' : '0' ?>"
  data-price-mode="<?= h($priceMode ?? 'syp') ?>"
>
  <header class="store-cart-header flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <h1 class="text-2xl md:text-3xl font-extrabold">سلة المتجر</h1>
      <p class="text-sm text-gray-600 mt-1">راجع الأصناف ثم أرسل طلبك — التحديثات فورية بدون إعادة تحميل الصفحة.</p>
      <?php if ($maxPackagesLabel !== null): ?>
        <p class="store-limit-banner mt-3 mb-0">الحد الأقصى للطلب: <strong><?= h($maxPackagesLabel) ?></strong> طرد لكل مادة.</p>
      <?php endif; ?>
    </div>
    <a href="/store.php" class="store-btn store-btn--secondary">
      <span class="material-symbols-outlined text-[20px]" aria-hidden="true">storefront</span>
      متابعة التسوق
    </a>
  </header>

  <?php if ($error): ?>
    <p class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm" data-cart-error><?= h($error) ?></p>
  <?php else: ?>
    <p class="mb-4 hidden rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm" data-cart-error></p>
  <?php endif; ?>

  <?php if ($notice): ?>
    <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm" data-cart-notice><?= h($notice) ?></p>
  <?php else: ?>
    <p class="mb-4 hidden rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm" data-cart-notice></p>
  <?php endif; ?>

  <div class="hidden mb-4" data-cart-stock-notices></div>

  <?php if (!$allowCart): ?>
    <section class="store-panel text-center py-10">
      <p class="text-gray-600"><?= h($error ?? 'سياسة المتجر الحالية لا تسمح باستخدام السلة.') ?></p>
      <a href="/store.php" class="store-btn store-btn--primary mt-4">العودة للمتجر</a>
    </section>
  <?php else: ?>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div class="lg:col-span-8" data-cart-body>
        <?php if ($cartItems === [] && $unavailableItems === []): ?>
          <div class="store-cart-empty">
            <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">shopping_cart</span>
            <p class="text-gray-500 mt-3">السلة فارغة.</p>
            <a href="/store.php" class="store-btn store-btn--primary mt-4">تصفح المتجر</a>
          </div>
        <?php else: ?>
          <?php
            $renderCartSection = static function (
                array $sectionItems,
                string $sectionClass,
                string $icon,
                string $title,
                string $subtitle,
                bool $showSectionHeader
            ) use ($maxPackagesPerMaterial, $showPriceSyp, $showPriceUsd): void {
                if ($sectionItems === []) {
                    return;
                }
                ?>
            <section class="store-cart-section <?= h($sectionClass) ?>">
              <?php if ($showSectionHeader): ?>
                <header class="store-cart-section__head">
                  <div class="store-cart-section__title-row">
                    <span class="material-symbols-outlined store-cart-section__icon" aria-hidden="true"><?= h($icon) ?></span>
                    <div>
                      <h3 class="store-cart-section__title"><?= h($title) ?></h3>
                      <p class="store-cart-section__subtitle"><?= h($subtitle) ?></p>
                    </div>
                  </div>
                  <span class="store-cart-section__count"><?= count($sectionItems) ?> صنف</span>
                </header>
              <?php endif; ?>
              <div class="store-cart-lines">
                <?php foreach ($sectionItems as $item): ?>
                  <?php
                    $lineShowsPrice = store_line_has_display_price($item);
                    require __DIR__ . '/partials/store-cart-line-card.php';
                  ?>
                <?php endforeach; ?>
              </div>
            </section>
                <?php
            };
          ?>

          <?php if ($pricedCartItems !== []): ?>
            <?php $renderCartSection(
                $pricedCartItems,
                'store-cart-section--priced',
                'sell',
                'أصناف بسعر محدد',
                'الأسعار المعروضة قابلة للتحديث حتى إرسال الطلب.',
                $hasMixedPricing
            ); ?>
          <?php endif; ?>

          <?php if ($unpricedCartItems !== []): ?>
            <?php $renderCartSection(
                $unpricedCartItems,
                'store-cart-section--unpriced',
                'receipt_long',
                'يُسعّر عند التأكيد',
                'سيُحدد سعر هذه الأصناف عند مراجعة الطلب.',
                $hasMixedPricing || $unpricedCartItems !== []
            ); ?>
          <?php endif; ?>

          <?php if ($unavailableItems !== []): ?>
            <section class="store-cart-unavailable mt-4" data-cart-unavailable>
              <div class="store-cart-unavailable__head">
                <div>
                  <h3 class="font-bold text-red-800">غير متوفرة للطلب</h3>
                  <p class="text-xs text-red-700 mt-0.5">هذه الأصناف لن تُرسل مع الطلب. قد يكون السبب حجز كميتها لطلبات أخرى قيد المعالجة.</p>
                </div>
                <button type="button" class="text-xs font-bold text-red-700 hover:underline" data-clear-unavailable>إزالة الكل</button>
              </div>
              <div class="store-cart-unavailable__list">
                <?php foreach ($unavailableItems as $line): ?>
                  <?php
                    $materialGuid = (string) ($line['material_guid'] ?? '');
                    $packageUnit = (string) ($line['package_unit'] ?? 'طرد');
                    $qty = max(1, (int) round((float) ($line['quantity'] ?? 1)));
                  ?>
                  <div class="store-cart-unavailable__item" data-unavailable-guid="<?= h($materialGuid) ?>">
                    <div class="flex items-center gap-3 min-w-0">
                      <?php if (!empty($line['image_url'])): ?>
                        <img src="<?= h((string) $line['image_url']) ?>" alt="" class="w-14 h-14 rounded-lg object-cover bg-gray-100 shrink-0 opacity-70" loading="lazy">
                      <?php endif; ?>
                      <div class="min-w-0">
                        <div class="font-bold text-sm text-gray-800"><?= h((string) ($line['material_name_ar'] ?? '')) ?></div>
                        <div class="text-xs text-red-700 mt-1"><?= h((string) ($line['stock_message'] ?? 'نفدت الكمية المتاحة.')) ?></div>
                        <div class="text-xs text-gray-500 mt-1">الكمية المطلوبة: <?= (int) $qty ?> <?= h($packageUnit) ?></div>
                      </div>
                    </div>
                    <button type="button" class="text-xs font-bold text-gray-600 hover:text-red-600 shrink-0" data-remove-unavailable="<?= h($materialGuid) ?>">إزالة</button>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <aside class="lg:col-span-4" data-cart-summary>
        <div class="store-panel store-cart-summary space-y-4">
          <?php
            $summarySp = (float) ($displayTotals['total_sp'] ?? 0);
            $summaryUsd = (float) ($displayTotals['total_usd'] ?? 0);
            $unpricedCount = count($unpricedCartItems);
          ?>
          <?php if ($showPriceSyp && $summarySp > 0): ?>
            <div class="store-cart-summary__total store-price-currency store-price-currency--syp">الإجمالي: <?= format_money($summarySp, true) ?> ل.س</div>
          <?php elseif ($showPriceUsd && $summaryUsd > 0): ?>
            <div class="store-cart-summary__total store-price-currency store-price-currency--usd">الإجمالي: $<?= number_format($summaryUsd, 2, '.', ',') ?></div>
          <?php endif; ?>
          <?php if ($unpricedCount > 0): ?>
            <p class="store-cart-summary__unpriced-note">
              <?= $unpricedCount ?> <?= $unpricedCount === 1 ? 'صنف' : 'أصناف' ?> بدون سعر محدد — يُسعّر عند التأكيد
            </p>
          <?php endif; ?>
          <button type="button" class="store-btn store-btn--ghost" data-clear-cart>تفريغ السلة</button>

          <?php if ($allowOrder && $cartItems !== []): ?>
            <form data-checkout-form class="space-y-3 border-t border-gray-100 pt-4">
              <?php if ($isLoggedInCustomer): ?>
                <p class="text-sm text-gray-600 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                  إرسال الطلب بحسابك المسجّل — بياناتك مأخوذة من ملفك ولا يمكن تغييرها هنا.
                </p>
              <?php else: ?>
                <label class="block text-sm font-bold">الاسم الكامل *
                  <input name="guest_name_ar" required value="<?= h($defaultGuestName) ?>" class="store-input mt-1">
                </label>
                <label class="block text-sm font-bold">رقم الهاتف *
                  <input name="guest_phone" required dir="ltr" value="<?= h($defaultGuestPhone) ?>" class="store-input mt-1 text-left">
                </label>
              <?php endif; ?>
              <label class="block text-sm font-bold">ملاحظات
                <textarea name="notes_ar" rows="3" class="store-input mt-1 h-auto py-2 text-sm"></textarea>
              </label>
              <button type="submit" class="store-btn store-btn--primary w-full">تأكيد وإرسال الطلب</button>
            </form>
          <?php elseif (!$allowOrder): ?>
            <p class="text-sm text-amber-800">سياسة المتجر لا تسمح بإرسال الطلبات حالياً.</p>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/store-image-lightbox.php'; ?>
