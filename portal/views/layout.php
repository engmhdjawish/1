<?php

declare(strict_types=1);

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Services\PortalSettingsService;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;

/** @var string $title */
/** @var string $content */
/** @var array<string, string>|null $companyContext */
/** @var string|null $companyLogoUrl */

require_once __DIR__ . '/helpers.php';

$companyContext ??= PortalSettingsService::companySettings();
$companyLogoUrl ??= PortalSettingsService::companyLogoUrl($companyContext);
$siteName = trim((string) ($companyContext['company_name'] ?? '')) !== ''
    ? (string) $companyContext['company_name']
    : 'جاويش للتجارة';

$customer = CustomerSession::check() ? CustomerSession::customer() : null;
$staffLoggedIn = WebSession::check();
$storeDisplay = StoreCatalogService::displayOptions();
$storeAllowCart = (bool) ($storeDisplay['allow_cart'] ?? false);
$storeCartCount = $storeAllowCart ? StoreCartService::itemCount() : 0;

$navLinks = [
    ['href' => '/index.php', 'label' => 'الرئيسية'],
    ['href' => '/store.php', 'label' => 'المتجر'],
    ['href' => '/about.php', 'label' => 'من نحن'],
];
?>
<!DOCTYPE html>
<html class="light" lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — <?= h($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet">
    <link href="/css/material-image-frame.css" rel="stylesheet">
    <link href="/css/site-brand.css" rel="stylesheet">
    <link href="/css/site-footer.css" rel="stylesheet">
    <?php if ($storeAllowCart): ?>
      <link href="/css/store-cart.css" rel="stylesheet">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: '#D81921',
              'surface-bg': '#f6f6f8',
              'surface-card': '#ffffff',
              'text-main': '#111827',
              'text-muted': '#4b5563'
            }
          }
        }
      };
    </script>
    <style>
      body { font-family: Manrope, sans-serif; background: #f6f6f8; color: #111827; }
      .site-link { color: #374151; }
      .site-link:hover, .site-link.is-active { color: #D81921; }
      .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
      .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24; vertical-align: middle; line-height: 1; }
      #publicNavDrawer { transition: transform 0.25s ease; }
      #publicNavDrawer.is-open { transform: translateX(0); }
      #publicNavOverlay.is-open { opacity: 1; pointer-events: auto; }
    </style>
    <?php if (!empty($extraHead ?? '')): ?>
      <?= $extraHead ?>
    <?php endif; ?>
</head>
<body class="min-h-screen text-text-main bg-surface-bg flex flex-col">
<header class="site-header bg-surface-card border-b border-gray-200 sticky top-0 z-30 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 py-2.5 sm:py-3 flex items-center justify-between gap-3 min-h-[4.75rem] sm:min-h-[5.25rem] lg:min-h-[5.75rem]">
    <div class="flex items-center gap-2 min-w-0">
      <button
        type="button"
        id="openPublicNavBtn"
        class="md:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl border border-gray-200 hover:bg-gray-50"
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
            require __DIR__ . '/partials/site-logo.php';
          ?>
          <span class="sr-only"><?= h($siteName) ?></span>
        <?php else: ?>
          <span class="truncate"><?= h($siteName) ?></span>
        <?php endif; ?>
      </a>
    </div>

    <nav class="hidden md:flex items-center gap-5 text-sm font-semibold">
      <?php foreach ($navLinks as $link): ?>
        <?php $path = parse_url($link['href'], PHP_URL_PATH) ?: ''; ?>
        <a href="<?= h($link['href']) ?>" class="site-link <?= ($_SERVER['REQUEST_URI'] ?? '') === $path || str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), $path . '?') ? 'is-active' : '' ?>">
          <?= h($link['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="flex items-center gap-2 shrink-0">
      <?php if ($storeAllowCart): ?>
        <a href="/store-cart.php" class="relative inline-flex items-center justify-center w-10 h-10 rounded-xl border border-gray-200 hover:border-primary" title="السلة" aria-label="السلة">
          <span class="material-symbols-outlined">shopping_cart</span>
          <span
            data-store-cart-badge
            class="absolute -top-1 -left-1 min-w-[1.125rem] h-[1.125rem] px-1 rounded-full bg-primary text-white text-[10px] font-bold inline-flex items-center justify-center <?= $storeCartCount > 0 ? '' : 'hidden' ?>"
          ><?= (int) $storeCartCount ?></span>
        </a>
      <?php endif; ?>
      <?php if ($customer): ?>
        <a href="/account.php" class="hidden sm:inline-flex h-10 items-center rounded-xl border border-gray-200 px-3 text-sm font-bold hover:border-primary">حسابي</a>
        <span class="hidden sm:inline text-sm font-bold text-gray-700 max-w-[140px] truncate"><?= h((string) ($customer['name_ar'] ?? '')) ?></span>
        <a href="/logout.php" class="h-10 inline-flex items-center gap-1 rounded-xl border border-gray-200 px-3 text-sm font-bold hover:border-primary" title="تسجيل الخروج">
          <span class="material-symbols-outlined text-base" aria-hidden="true">logout</span>
          <span class="hidden sm:inline">خروج</span>
        </a>
      <?php else: ?>
        <a href="/login.php?type=customer" class="hidden sm:inline-flex h-10 items-center rounded-xl border border-gray-200 px-3 text-sm font-bold hover:border-primary">دخول</a>
        <a href="/register.php" class="h-10 inline-flex items-center rounded-xl bg-primary text-white px-3 text-sm font-bold hover:brightness-110">تسجيل</a>
      <?php endif; ?>
      <?php if ($staffLoggedIn && !$customer): ?>
        <a href="/dashboard/index.php" class="hidden lg:inline-flex h-10 items-center rounded-xl border border-gray-200 px-3 text-xs font-bold text-gray-600 hover:border-primary">لوحة التحكم</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div id="publicNavOverlay" class="md:hidden fixed inset-0 z-40 bg-black/40 opacity-0 pointer-events-none transition" aria-hidden="true"></div>
<aside id="publicNavDrawer" class="md:hidden fixed top-0 right-0 z-50 h-full w-[min(88vw,300px)] bg-white border-l border-gray-200 shadow-2xl flex flex-col translate-x-full" aria-hidden="true">
  <div class="flex items-center justify-between gap-2 px-4 py-4 border-b border-gray-200 min-w-0">
    <div class="inline-flex items-center gap-2 min-w-0">
      <?php if (!empty($companyLogoUrl)): ?>
        <?php
          $siteLogoVariant = 'drawer';
          $siteLogoAlt = $siteName;
          require __DIR__ . '/partials/site-logo.php';
        ?>
      <?php endif; ?>
      <span class="font-bold text-primary truncate"><?= h($siteName) ?></span>
    </div>
    <button type="button" id="closePublicNavBtn" class="w-9 h-9 rounded-full hover:bg-red-50 inline-flex items-center justify-center" aria-label="إغلاق">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <nav class="p-4 space-y-1 text-sm font-semibold">
    <?php foreach ($navLinks as $link): ?>
      <a href="<?= h($link['href']) ?>" data-public-nav-link="1" class="block rounded-xl px-3 py-3 hover:bg-gray-50"><?= h($link['label']) ?></a>
    <?php endforeach; ?>
    <?php if ($storeAllowCart): ?>
      <a href="/store-cart.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 hover:bg-gray-50 inline-flex items-center justify-between gap-2">
        <span>السلة</span>
        <span data-store-cart-badge class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-primary text-white text-[10px] font-bold inline-flex items-center justify-center <?= $storeCartCount > 0 ? '' : 'hidden' ?>"><?= (int) $storeCartCount ?></span>
      </a>
    <?php endif; ?>
    <?php if ($customer): ?>
      <a href="/account.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 hover:bg-gray-50">حسابي</a>
      <div class="pt-3 mt-3 border-t border-gray-100 text-gray-600 px-3"><?= h((string) ($customer['name_ar'] ?? '')) ?></div>
      <a href="/logout.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 text-red-600 hover:bg-red-50">تسجيل الخروج</a>
    <?php else: ?>
      <a href="/login.php?type=customer" data-public-nav-link="1" class="block rounded-xl px-3 py-3 hover:bg-gray-50">دخول العملاء</a>
      <a href="/register.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 text-primary hover:bg-red-50">تسجيل عميل جديد</a>
    <?php endif; ?>
    <?php if ($staffLoggedIn && !$customer): ?>
      <a href="/dashboard/index.php" data-public-nav-link="1" class="block rounded-xl px-3 py-3 text-gray-600 hover:bg-gray-50">لوحة التحكم</a>
    <?php endif; ?>
  </nav>
</aside>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 py-6 md:py-8">
  <?= $content ?>
</main>

<?php require __DIR__ . '/partials/site-footer.php'; ?>

<script>
  (() => {
    const drawer = document.getElementById('publicNavDrawer');
    const overlay = document.getElementById('publicNavOverlay');
    const openBtn = document.getElementById('openPublicNavBtn');
    const closeBtn = document.getElementById('closePublicNavBtn');
    if (!drawer || !overlay || !openBtn || !closeBtn) return;
    const setOpen = (open) => {
      drawer.classList.toggle('is-open', open);
      overlay.classList.toggle('is-open', open);
      drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
      overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
      openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.style.overflow = open ? 'hidden' : '';
    };
    openBtn.addEventListener('click', () => setOpen(true));
    closeBtn.addEventListener('click', () => setOpen(false));
    overlay.addEventListener('click', () => setOpen(false));
    drawer.querySelectorAll('[data-public-nav-link]').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
  })();
</script>
<?php require __DIR__ . '/partials/product-quick-view.php'; ?>
<?php if ($storeAllowCart): ?>
  <script src="/assets/store-cart.js" defer></script>
<?php endif; ?>
<?php if (!empty($extraFooter ?? '')): ?>
  <?= $extraFooter ?>
<?php endif; ?>
</body>
</html>
