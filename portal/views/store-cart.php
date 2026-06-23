<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $cartItems */
/** @var list<array<string, mixed>> $unavailableItems */
/** @var array{total_sp: float, total_usd: float} $totals */
/** @var bool $allowCart */
/** @var bool $allowOrder */
/** @var bool $showPrice */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var string $priceMode */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string $defaultGuestName */
/** @var string $defaultGuestPhone */
/** @var float|null $maxPackagesPerMaterial */
/** @var string|null $maxPackagesLabel */

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
          <?php if ($cartItems !== []): ?>
            <div class="store-cart-lines">
              <?php foreach ($cartItems as $item): ?>
                <?php require __DIR__ . '/partials/store-cart-line-card.php'; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($unavailableItems !== []): ?>
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 mt-4">
              <h3 class="font-bold text-amber-900 mb-2">غير متوفرة حالياً</h3>
              <ul class="text-sm text-amber-900 space-y-1">
                <?php foreach ($unavailableItems as $line): ?>
                  <li><?= h((string) ($line['material_name_ar'] ?? '')) ?></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <aside class="lg:col-span-4" data-cart-summary>
        <div class="store-panel store-cart-summary space-y-4">
          <?php if ($showPriceSyp): ?>
            <div class="store-cart-summary__total">الإجمالي: <?= format_money((float) $totals['total_sp'], true) ?> ل.س</div>
          <?php elseif ($showPriceUsd): ?>
            <div class="store-cart-summary__total">الإجمالي: $<?= number_format((float) $totals['total_usd'], 2, '.', ',') ?></div>
          <?php endif; ?>
          <button type="button" class="store-btn store-btn--ghost" data-clear-cart>تفريغ السلة</button>

          <?php if ($allowOrder && $cartItems !== []): ?>
            <form data-checkout-form class="space-y-3 border-t border-gray-100 pt-4">
              <label class="block text-sm font-bold">الاسم الكامل *
                <input name="guest_name_ar" required value="<?= h($defaultGuestName) ?>" class="store-input mt-1">
              </label>
              <label class="block text-sm font-bold">رقم الهاتف *
                <input name="guest_phone" required dir="ltr" value="<?= h($defaultGuestPhone) ?>" class="store-input mt-1 text-left">
              </label>
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
