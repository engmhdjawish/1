<?php

declare(strict_types=1);

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;
use Portal\Services\PortalSettingsService;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;
use Portal\Support\StorePricePreference;

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
StorePricePreference::applyFromRequest($_GET);
$storeShowPrice = (bool) ($storeDisplay['show_price'] ?? false);
$storePriceCurrency = StorePricePreference::current();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#D81921">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= h($siteName) ?>">
    <link rel="manifest" href="/manifest.php">
    <link rel="apple-touch-icon" href="<?= h($companyLogoUrl !== '' ? $companyLogoUrl : '/icons/app-icon.svg') ?>">
    <title><?= h($title) ?> — <?= h($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet">
    <link href="/css/material-image-frame.css" rel="stylesheet">
    <link href="/css/site-brand.css" rel="stylesheet">
    <link href="/css/site-header.css" rel="stylesheet">
    <link href="/css/site-footer.css" rel="stylesheet">
    <link href="/css/pwa-install.css" rel="stylesheet">
    <link href="/css/store-ui.css" rel="stylesheet">
    <link href="/css/notifications.css" rel="stylesheet">
    <?php if ($storeAllowCart): ?>
      <link href="/css/store-cart.css" rel="stylesheet">
    <?php endif; ?>
    <link href="/css/notifications.css" rel="stylesheet">
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
    </style>
    <?php if (!empty($extraHead ?? '')): ?>
      <?= $extraHead ?>
    <?php endif; ?>
</head>
<body class="min-h-screen text-text-main bg-surface-bg flex flex-col">
<?php require __DIR__ . '/partials/site-header.php'; ?>

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
<?php if ($storeShowPrice): ?>
  <script src="/assets/store-pref.js" defer></script>
<?php endif; ?>
<?php if ($storeAllowCart): ?>
  <script src="/assets/store-cart.js" defer></script>
<?php endif; ?>
<script src="/assets/pwa.js" defer></script>
<script src="/assets/notifications.js" defer></script>
<?php if (!empty($extraFooter ?? '')): ?>
  <?= $extraFooter ?>
<?php endif; ?>
</body>
</html>
