<?php

declare(strict_types=1);

use Portal\Support\StorePricePreference;

/** @var list<array{href: string, label: string}> $navLinks */
/** @var string $siteName */
/** @var string|null $companyLogoUrl */
/** @var bool $storeShowPrice */
/** @var string $storePriceCurrency */
/** @var bool $storeAllowCart */
/** @var int $storeCartCount */
/** @var array<string, mixed>|null $customer */
/** @var bool $staffLoggedIn */

$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$isNavActive = static function (string $href) use ($requestPath): bool {
    $path = (string) (parse_url($href, PHP_URL_PATH) ?: '');
    if ($path === '') {
        return false;
    }

    return $requestPath === $path || str_starts_with($requestPath, $path . '?');
};
?>
<header class="site-header">
  <div class="site-header__shell">
    <div class="site-header__row">
      <div class="site-header__brand">
        <button
          type="button"
          id="openPublicNavBtn"
          class="site-header__menu-btn"
          aria-controls="publicNavDrawer"
          aria-expanded="false"
          aria-label="فتح القائمة"
        >
          <span class="material-symbols-outlined">menu</span>
        </button>
        <a href="/index.php" class="site-brand-link font-extrabold text-primary text-base sm:text-lg inline-flex items-center gap-3 min-w-0" aria-label="<?= h($siteName) ?>">
          <?php if (!empty($companyLogoUrl)): ?>
            <?php
              $siteLogoVariant = 'header';
              $siteLogoAlt = $siteName;
              require __DIR__ . '/site-logo.php';
            ?>
            <span class="sr-only"><?= h($siteName) ?></span>
          <?php else: ?>
            <span class="truncate"><?= h($siteName) ?></span>
          <?php endif; ?>
        </a>
      </div>

      <nav class="site-header__nav" aria-label="التنقل الرئيسي">
        <div class="site-header__nav-list">
          <?php foreach ($navLinks as $link): ?>
            <a
              href="<?= h($link['href']) ?>"
              class="site-header__nav-link <?= $isNavActive($link['href']) ? 'is-active' : '' ?>"
            >
              <?= h($link['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </nav>

      <div class="site-header__actions">
        <div class="site-header__toolbar">
          <?php if ($storeShowPrice): ?>
            <div class="site-header__currency" role="group" aria-label="عملة عرض الأسعار">
              <span class="site-header__currency-label">العملة</span>
              <div class="store-currency-toggle">
                <button
                  type="button"
                  class="store-currency-toggle__btn <?= $storePriceCurrency === StorePricePreference::SYP ? 'is-active' : '' ?>"
                  data-store-currency="<?= h(StorePricePreference::SYP) ?>"
                  title="عرض الأسعار بالليرة السورية"
                >ل.س</button>
                <button
                  type="button"
                  class="store-currency-toggle__btn <?= $storePriceCurrency === StorePricePreference::USD ? 'is-active' : '' ?>"
                  data-store-currency="<?= h(StorePricePreference::USD) ?>"
                  title="عرض الأسعار بالدولار"
                >$</button>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($storeAllowCart): ?>
            <?php if ($storeShowPrice): ?>
              <span class="site-header__divider" aria-hidden="true"></span>
            <?php endif; ?>
            <a href="/store-cart.php" class="site-header__icon-btn" title="السلة" aria-label="السلة">
              <span class="material-symbols-outlined">shopping_cart</span>
              <span
                data-store-cart-badge
                class="site-header__badge <?= $storeCartCount > 0 ? '' : 'hidden' ?>"
              ><?= (int) $storeCartCount ?></span>
            </a>
          <?php endif; ?>

          <span class="site-header__divider" aria-hidden="true"></span>

          <div class="site-header__auth">
            <?php if ($customer): ?>
              <a href="/account.php" class="site-header__btn site-header__btn--ghost hidden sm:inline-flex">حسابي</a>
              <span class="site-header__user"><?= h((string) ($customer['name_ar'] ?? '')) ?></span>
              <a href="/logout.php" class="site-header__btn site-header__btn--ghost site-header__btn--compact" title="تسجيل الخروج">
                <span class="material-symbols-outlined text-base" aria-hidden="true">logout</span>
                <span class="hidden sm:inline">خروج</span>
              </a>
            <?php else: ?>
              <a href="/login.php?type=customer" class="site-header__btn site-header__btn--ghost hidden sm:inline-flex">دخول</a>
              <a href="/register.php" class="site-header__btn site-header__btn--primary">تسجيل</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($staffLoggedIn && !$customer): ?>
          <a href="/dashboard/index.php" class="site-header__staff-link" title="لوحة التحكم">
            <span class="material-symbols-outlined" aria-hidden="true">dashboard</span>
            لوحة التحكم
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>

<div id="publicNavOverlay" class="md:hidden fixed inset-0 z-40 bg-black/40 opacity-0 pointer-events-none transition" aria-hidden="true"></div>
<aside id="publicNavDrawer" class="md:hidden fixed top-0 right-0 z-50 h-full w-[min(88vw,300px)] bg-white border-l border-gray-200 shadow-2xl flex flex-col translate-x-full" aria-hidden="true">
  <div class="site-drawer__head">
    <div class="site-drawer__brand">
      <?php if (!empty($companyLogoUrl)): ?>
        <?php
          $siteLogoVariant = 'drawer';
          $siteLogoAlt = $siteName;
          require __DIR__ . '/site-logo.php';
        ?>
      <?php endif; ?>
      <span class="site-drawer__title"><?= h($siteName) ?></span>
    </div>
    <button type="button" id="closePublicNavBtn" class="site-drawer__close" aria-label="إغلاق">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <nav class="site-drawer__nav" aria-label="قائمة الجوال">
    <?php foreach ($navLinks as $link): ?>
      <a
        href="<?= h($link['href']) ?>"
        data-public-nav-link="1"
        class="site-drawer__link <?= $isNavActive($link['href']) ? 'is-active' : '' ?>"
      ><?= h($link['label']) ?></a>
    <?php endforeach; ?>

    <?php if ($storeShowPrice): ?>
      <div class="site-drawer__section">
        <p class="site-drawer__section-label">عملة الأسعار</p>
        <div class="site-drawer__currency px-3">
          <div class="store-currency-toggle store-currency-toggle--drawer">
            <button type="button" class="store-currency-toggle__btn <?= $storePriceCurrency === StorePricePreference::SYP ? 'is-active' : '' ?>" data-store-currency="<?= h(StorePricePreference::SYP) ?>">ل.س</button>
            <button type="button" class="store-currency-toggle__btn <?= $storePriceCurrency === StorePricePreference::USD ? 'is-active' : '' ?>" data-store-currency="<?= h(StorePricePreference::USD) ?>">$</button>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="site-drawer__section">
      <?php if ($customer): ?>
        <a href="/account.php" data-public-nav-link="1" class="site-drawer__link">حسابي</a>
        <div class="site-drawer__user"><?= h((string) ($customer['name_ar'] ?? '')) ?></div>
        <a href="/logout.php" data-public-nav-link="1" class="site-drawer__link site-drawer__link--danger">تسجيل الخروج</a>
      <?php else: ?>
        <a href="/login.php?type=customer" data-public-nav-link="1" class="site-drawer__link">دخول العملاء</a>
        <a href="/register.php" data-public-nav-link="1" class="site-drawer__link is-active">تسجيل عميل جديد</a>
      <?php endif; ?>
      <?php if ($staffLoggedIn && !$customer): ?>
        <a href="/dashboard/index.php" data-public-nav-link="1" class="site-drawer__link">لوحة التحكم</a>
      <?php endif; ?>
    </div>
  </nav>
</aside>
