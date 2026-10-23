<?php

declare(strict_types=1);

use Portal\Support\StorePricePreference;

/** @var list<array{href: string, label: string, icon?: string}> $navLinks */
/** @var string $siteName */
/** @var string $siteTagline */
/** @var string|null $companyLogoUrl */
/** @var bool $headerShowCurrency */
/** @var string $storePriceCurrency */
/** @var bool $storeAllowCart */
/** @var int $storeCartCount */
/** @var array<string, mixed>|null $customer */
/** @var bool $staffLoggedIn */
/** @var array<string, mixed>|null $staffUser */
/** @var bool $staffSiteMode */
/** @var string $siteTour */

$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$isNavActive = static function (string $href) use ($requestPath): bool {
    $path = (string) (parse_url($href, PHP_URL_PATH) ?: '');
    if ($path === '') {
        return false;
    }

    return $requestPath === $path || str_starts_with($requestPath, $path . '?');
};
$siteTagline = trim((string) ($siteTagline ?? ''));
$siteTour = (string) ($siteTour ?? '');
$hasSession = $staffSiteMode || $customer !== null;
?>
<header class="site-header<?= $hasSession ? ' site-header--session' : '' ?>">
  <div class="site-header__shell">
    <div class="site-header__bar">
      <a href="/index.php" class="site-header__brand" aria-label="<?= h($siteName) ?>">
        <?php if (!empty($companyLogoUrl)): ?>
          <?php
            $siteLogoVariant = 'header';
            $siteLogoAlt = '';
            require __DIR__ . '/site-logo.php';
          ?>
        <?php endif; ?>
        <span class="site-header__brand-copy">
          <span class="site-header__brand-name"><?= h($siteName) ?></span>
          <?php if ($siteTagline !== ''): ?>
            <span class="site-header__brand-tagline"><?= h($siteTagline) ?></span>
          <?php endif; ?>
        </span>
      </a>

      <nav class="site-header__nav" aria-label="التنقل الرئيسي">
        <?php foreach ($navLinks as $link): ?>
          <a
            href="<?= h($link['href']) ?>"
            class="site-header__nav-link <?= $isNavActive($link['href']) ? 'is-active' : '' ?>"
            <?php if (str_contains($link['href'], 'store.php')): ?>data-guide="nav-store"<?php endif; ?>
          ><?= h($link['label']) ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="site-header__tools">
        <?php if ($siteTour !== ''): ?>
          <button type="button" class="site-header__icon-btn" data-site-guide-replay data-guide="help" title="جولة تعريفية بالصفحة" aria-label="جولة تعريفية بالصفحة">
            <span class="material-symbols-outlined" aria-hidden="true">help</span>
          </button>
        <?php endif; ?>
        <button type="button" class="site-header__icon-btn" data-pwa-open hidden title="تثبيت التطبيق" aria-label="تثبيت التطبيق">
          <span class="material-symbols-outlined" aria-hidden="true">install_mobile</span>
        </button>
        <?php require __DIR__ . '/notification-bell.php'; ?>
        <?php if ($storeAllowCart): ?>
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
            <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
            <span
              data-store-cart-badge
              class="site-header__badge <?= $storeCartPackageCount > 0 ? '' : 'hidden' ?>"
              title="<?= $storeCartPackageCount > 0 ? h(format_packages_display($storeCartPackageCount) . ' طرد') : '' ?>"
            ><span data-store-cart-badge-packages><?= $storeCartPackageCount > 0 ? h(format_packages_display($storeCartPackageCount)) : '0' ?></span></span>
          </button>
        <?php endif; ?>
      </div>

      <div class="site-header__session" data-guide="auth">
        <?php if ($hasSession): ?>
          <?php require __DIR__ . '/site-header-account.php'; ?>
        <?php elseif (portal_show_guest_auth_links()): ?>
          <a href="<?= h(portal_login_url('customer')) ?>" class="site-header__btn site-header__btn--ghost" data-guide="login">
            <span class="material-symbols-outlined" aria-hidden="true">login</span>
            دخول
          </a>
          <a href="/register.php" class="site-header__btn site-header__btn--primary" data-guide="register" title="طلب إنشاء حساب عميل جديد">
            <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
            حساب جديد
          </a>
        <?php endif; ?>
      </div>

      <?php if ($headerShowCurrency): ?>
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
    </div>
  </div>
</header>
