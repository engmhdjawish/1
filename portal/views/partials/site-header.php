<?php

declare(strict_types=1);

use Portal\Support\StorePricePreference;

/** @var list<array{href: string, label: string, icon?: string}> $navLinks */
/** @var string $siteName */
/** @var string|null $companyLogoUrl */
/** @var bool $storeShowPrice */
/** @var string $storePriceCurrency */
/** @var bool $storeAllowCart */
/** @var int $storeCartCount */
/** @var array<string, mixed>|null $customer */
/** @var bool $staffLoggedIn */
/** @var array<string, mixed>|null $staffUser */
/** @var bool $staffSiteMode */

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
    <div class="site-header__row site-header__row--primary">
      <div class="site-header__brand">
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
              <?php if (str_contains($link['href'], 'store.php')): ?>data-guide="nav-store"<?php endif; ?>
            >
              <?= h($link['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </nav>

      <div class="site-header__actions">
        <div class="site-header__toolbar">
          <?php if ($staffSiteMode): ?>
            <?php require __DIR__ . '/site-header-staff.php'; ?>
            <span class="site-header__divider site-header__divider--staff" aria-hidden="true"></span>
          <?php endif; ?>

          <div class="site-header__auth" data-guide="auth">
            <?php if ($customer): ?>
              <?php require __DIR__ . '/site-header-account.php'; ?>
            <?php elseif (!$staffSiteMode): ?>
              <div class="site-header__guest-auth">
                <a href="<?= h(portal_login_url('customer')) ?>" class="site-header__guest-auth-link" data-guide="login">
                  <span class="material-symbols-outlined" aria-hidden="true">login</span>
                  <span class="site-header__guest-auth-label">تسجيل الدخول</span>
                </a>
                <span class="site-header__guest-auth-sep" aria-hidden="true"></span>
                <a href="/register.php" class="site-header__guest-auth-link site-header__guest-auth-link--register" data-guide="register" title="طلب إنشاء حساب عميل جديد">حساب جديد</a>
              </div>
            <?php endif; ?>
          </div>

          <?php require __DIR__ . '/notification-bell.php'; ?>

          <?php if ($storeAllowCart): ?>
            <span class="site-header__divider" aria-hidden="true"></span>
            <button
              type="button"
              class="site-header__icon-btn site-header__icon-btn--cart"
              data-guide="cart"
              data-store-cart-open
              title="السلة"
              aria-label="السلة"
              aria-controls="store-cart-drawer"
              aria-expanded="false"
            >
              <span class="material-symbols-outlined">shopping_cart</span>
              <span
                data-store-cart-badge
                class="site-header__badge <?= $storeCartPackageCount > 0 ? '' : 'hidden' ?>"
                title="<?= $storeCartPackageCount > 0 ? h(format_packages_display($storeCartPackageCount) . ' طرد') : '' ?>"
              ><span data-store-cart-badge-packages><?= $storeCartPackageCount > 0 ? h(format_packages_display($storeCartPackageCount)) : '0' ?></span></span>
            </button>
          <?php endif; ?>

          <span class="site-header__divider" aria-hidden="true"></span>
          <button
            type="button"
            class="site-header__icon-btn"
            data-pwa-open
            title="تثبيت التطبيق"
            aria-label="تثبيت التطبيق"
          >
            <span class="material-symbols-outlined">install_mobile</span>
          </button>

          <?php if ($storeShowPrice): ?>
            <span class="site-header__divider site-header__divider--desktop-currency" aria-hidden="true"></span>
            <div class="site-header__currency" data-guide="currency" role="group" aria-label="عملة عرض الأسعار">
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
        </div>
      </div>
    </div>

    <div class="site-header__row site-header__row--mobile-nav">
      <nav class="site-header__mobile-nav" aria-label="تنقل سريع">
        <?php foreach ($navLinks as $link): ?>
          <?php $isStoreLink = str_contains($link['href'], 'store.php'); ?>
          <a
            href="<?= h($link['href']) ?>"
            class="site-header__mobile-nav-link <?= $isNavActive($link['href']) ? 'is-active' : '' ?><?= $isStoreLink ? ' site-header__mobile-nav-link--store' : '' ?>"
            <?php if ($isStoreLink): ?>data-guide="nav-store"<?php endif; ?>
          >
            <?php if (!empty($link['icon'])): ?>
              <span class="material-symbols-outlined site-header__mobile-nav-icon" aria-hidden="true"><?= h((string) $link['icon']) ?></span>
            <?php endif; ?>
            <span class="site-header__mobile-nav-label"><?= h($link['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php if ($storeShowPrice): ?>
        <div class="site-header__mobile-currency" data-guide="currency" role="group" aria-label="عملة عرض الأسعار">
          <div class="store-currency-toggle store-currency-toggle--mobile">
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
    </div>
  </div>
</header>
