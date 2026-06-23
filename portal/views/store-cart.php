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
            <div class="store-cart-table-wrap overflow-x-auto">
              <table class="store-cart-table">
                <thead>
                  <tr>
                    <th>المنتج</th>
                    <th>سعر الطرد</th>
                    <th>الكمية</th>
                    <th>الإجمالي</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($cartItems as $line): ?>
                    <?php
                      $materialGuid = (string) ($line['material_guid'] ?? '');
                      $qty = max(1, (int) round((float) ($line['quantity'] ?? 1)));
                      $packageUnit = (string) ($line['package_unit'] ?? 'طرد');
                      $priceSp = (float) ($line['sale_price_sp'] ?? 0);
                      $priceUsd = (float) ($line['sale_price_usd'] ?? 0);
                      $lineTotalSp = $qty * $priceSp;
                      $lineTotalUsd = $qty * $priceUsd;
                    ?>
                    <tr data-cart-line="<?= h($materialGuid) ?>">
                      <td>
                        <div class="store-cart-product">
                          <?php if (!empty($line['image_url'])): ?>
                            <?php $zoomUrl = material_image_zoom_url((string) $line['image_url']); ?>
                            <button type="button" class="store-cart-product__thumb" data-cart-image-zoom="<?= h($zoomUrl) ?>" title="تكبير الصورة للتدقيق">
                              <img src="<?= h((string) $line['image_url']) ?>" alt="">
                              <span class="store-cart-product__zoom-icon material-symbols-outlined" aria-hidden="true">zoom_in</span>
                            </button>
                          <?php else: ?>
                            <div class="store-cart-product__placeholder">
                              <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                            </div>
                          <?php endif; ?>
                          <div>
                            <div class="font-bold text-sm"><?= h((string) ($line['material_name_ar'] ?? '')) ?></div>
                            <?php if (!empty($line['material_code'])): ?>
                              <div class="text-xs text-gray-500 font-mono" dir="ltr"><?= h((string) $line['material_code']) ?></div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                      <td class="text-sm whitespace-nowrap">
                        <?php if ($showPriceSyp && $priceSp > 0): ?>
                          <span class="font-bold text-primary"><?= format_money($priceSp, true) ?> ل.س</span>
                        <?php elseif ($showPriceUsd && $priceUsd > 0): ?>
                          <span class="font-bold text-emerald-700">$<?= number_format($priceUsd, 2, '.', ',') ?></span>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="store-qty-stepper" data-cart-qty-control data-guid="<?= h($materialGuid) ?>">
                          <button type="button" data-bump="-1" aria-label="إنقاص">−</button>
                          <input
                            type="number"
                            min="1"
                            <?php if ($maxPackagesPerMaterial !== null): ?>max="<?= (int) $maxPackagesPerMaterial ?>"<?php endif; ?>
                            value="<?= (int) $qty ?>"
                            data-qty-input
                          >
                          <button type="button" data-bump="1" aria-label="زيادة">+</button>
                        </div>
                        <div class="text-xs text-gray-500 mt-1"><?= h($packageUnit) ?></div>
                      </td>
                      <td class="font-bold text-sm whitespace-nowrap">
                        <?php if ($showPriceSyp): ?>
                          <?= format_money($lineTotalSp, true) ?> ل.س
                        <?php elseif ($showPriceUsd): ?>
                          $<?= number_format($lineTotalUsd, 2, '.', ',') ?>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td class="text-center">
                        <button type="button" class="p-2 rounded-full text-red-600 hover:bg-red-50" data-remove-item="<?= h($materialGuid) ?>" aria-label="حذف">
                          <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
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

<div id="storeImageLightbox" class="store-image-lightbox" hidden aria-hidden="true">
  <button type="button" class="store-image-lightbox__close" data-lightbox-close aria-label="إغلاق">
    <span class="material-symbols-outlined">close</span>
  </button>
  <div class="store-image-lightbox__backdrop" data-lightbox-close></div>
  <figure class="store-image-lightbox__frame">
    <img src="" alt="" id="storeImageLightboxImg">
    <figcaption id="storeImageLightboxCaption" class="store-image-lightbox__caption"></figcaption>
  </figure>
</div>
