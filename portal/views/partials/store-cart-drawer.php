<?php

declare(strict_types=1);

/** @var bool $storeAllowCart */
/** @var array<string, mixed>|null $customer */

$defaultGuestName = is_array($customer ?? null) ? trim((string) ($customer['name_ar'] ?? '')) : '';
$defaultGuestPhone = is_array($customer ?? null) ? trim((string) ($customer['phone'] ?? '')) : '';
$isLoggedInCustomer = is_array($customer ?? null);
?>
<div
  id="store-cart-drawer"
  class="store-cart-drawer"
  hidden
  aria-hidden="true"
>
  <div class="store-cart-drawer__backdrop" data-store-cart-drawer-close aria-hidden="true"></div>
  <aside class="store-cart-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="store-cart-drawer-title">
    <header class="store-cart-drawer__header">
      <h2 id="store-cart-drawer-title" class="store-cart-drawer__title">سلة المتجر</h2>
      <button type="button" class="store-cart-drawer__close" data-store-cart-drawer-close aria-label="إغلاق السلة">
        <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
      </button>
    </header>
    <div
      class="store-cart-drawer__shell"
      data-store-cart-drawer-root
      data-store-cart-page="drawer"
      data-default-name="<?= h($defaultGuestName) ?>"
      data-default-phone="<?= h($defaultGuestPhone) ?>"
      data-logged-in="<?= $isLoggedInCustomer ? '1' : '0' ?>"
    >
      <div class="store-cart-drawer__loading-overlay" data-cart-drawer-loading hidden>
        <span class="store-cart-drawer__spinner" aria-hidden="true"></span>
        <span>جاري تحميل السلة...</span>
      </div>
      <div class="store-cart-drawer__scroll">
        <p class="mb-4 hidden rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm" data-cart-error></p>
        <p class="mb-4 hidden rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm" data-cart-notice></p>
        <div class="hidden mb-4" data-cart-stock-notices></div>
        <div data-cart-body></div>
      </div>
      <footer class="store-cart-drawer__footer" data-cart-summary></footer>
      <div class="store-cart-checkout" data-cart-checkout-sheet hidden aria-hidden="true">
        <div class="store-cart-checkout__backdrop" data-cart-checkout-close tabindex="-1" aria-hidden="true"></div>
        <section class="store-cart-checkout__panel" role="dialog" aria-modal="true" aria-labelledby="store-cart-checkout-title">
          <header class="store-cart-checkout__header">
            <button type="button" class="store-cart-checkout__back" data-cart-checkout-close aria-label="رجوع للسلة">
              <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
            </button>
            <h3 id="store-cart-checkout-title" class="store-cart-checkout__title">إتمام الطلب</h3>
          </header>
          <div class="store-cart-checkout__body" data-cart-checkout-body></div>
        </section>
      </div>
    </div>
  </aside>
</div>
