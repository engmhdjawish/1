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
/** @var bool|null $enableQuickView */
/** @var bool|null $enableStoreCartJs */
/** @var bool|null $enableOnboarding */

require_once __DIR__ . '/helpers.php';

$companyContext ??= PortalSettingsService::companySettings();
$companyLogoUrl ??= PortalSettingsService::companyLogoUrl($companyContext);
$siteName = trim((string) ($companyContext['company_name'] ?? '')) !== ''
    ? (string) $companyContext['company_name']
    : 'جاويش للتجارة';

$customer = CustomerSession::check() ? CustomerSession::customer() : null;
$staffLoggedIn = WebSession::check();
$storeDisplay = StoreCatalogService::displayOptions();
StorePricePreference::bootstrap();
StorePricePreference::applyFromRequest($_GET);
$storeShowPrice = (bool) ($storeDisplay['show_price'] ?? false);
$storePriceCurrency = StorePricePreference::current();
$storeAllowCart = (bool) ($storeDisplay['allow_cart'] ?? false);
$storeCartCount = $storeAllowCart ? StoreCartService::itemCount() : 0;

$pagePath = portal_request_path();
$isCatalogPage = portal_is_catalog_page($pagePath);
$isLightPage = in_array($pagePath, ['/login.php', '/register.php', '/about.php'], true);

$enableQuickView = (bool) ($enableQuickView ?? $isCatalogPage);
$enableStoreCartJs = (bool) ($enableStoreCartJs ?? ($storeAllowCart && !$isLightPage));
$enableOnboarding = (bool) ($enableOnboarding ?? !$isLightPage);
$enableSiteAnalytics = (bool) ($enableSiteAnalytics ?? true);

$metaDescription = portal_seo_description($pagePath, $siteName, $metaDescription ?? null);
$canonicalUrl = portal_canonical_url($canonicalUrl ?? null);
$ogTitle = trim((string) ($ogTitle ?? $title . ' — ' . $siteName));
$ogImage = trim((string) ($ogImage ?? ''));
if ($ogImage === '' && !empty($companyLogoUrl)) {
    $ogImage = str_starts_with((string) $companyLogoUrl, 'http')
        ? (string) $companyLogoUrl
        : portal_absolute_url((string) $companyLogoUrl);
}
$jsonLdBlocks = [
    portal_json_ld_organization($siteName, $companyLogoUrl ?? null),
];
if ($pagePath === '/index.php' || $pagePath === '/') {
    $jsonLdBlocks[] = portal_json_ld_website($siteName);
}
$jsonLdPayload = count($jsonLdBlocks) === 1
    ? $jsonLdBlocks[0]
    : ['@context' => 'https://schema.org', '@graph' => $jsonLdBlocks];

$navLinks = [
    ['href' => '/index.php', 'label' => 'الرئيسية'],
    ['href' => '/store.php', 'label' => 'المتجر'],
    ['href' => '/about.php', 'label' => 'من نحن'],
];
if ($customer) {
    $navLinks[] = ['href' => '/my-orders.php', 'label' => 'طلباتي'];
    $navLinks[] = ['href' => '/my-profile.php', 'label' => 'الملف الشخصي'];
}
?>
<!DOCTYPE html>
<html class="light" lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <script>
      (function () {
        var host = window.location.hostname;
        if (window.location.protocol === 'http:' && host !== 'localhost' && host !== '127.0.0.1') {
          window.location.replace(
            'https://' + host + window.location.pathname + window.location.search + window.location.hash
          );
        }
      })();
    </script>
    <?php require __DIR__ . '/partials/head-icons.php'; ?>
    <title><?= h($title) ?> — <?= h($siteName) ?></title>
    <meta name="description" content="<?= h($metaDescription) ?>">
    <meta name="robots" content="<?= h((string) ($metaRobots ?? 'index, follow')) ?>">
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ar_SY">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:title" content="<?= h($ogTitle) ?>">
    <meta property="og:description" content="<?= h($metaDescription) ?>">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <?php if ($ogImage !== ''): ?>
      <meta property="og:image" content="<?= h($ogImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= h($ogImage !== '' ? 'summary_large_image' : 'summary') ?>">
    <meta name="twitter:title" content="<?= h($ogTitle) ?>">
    <meta name="twitter:description" content="<?= h($metaDescription) ?>">
    <?php if ($ogImage !== ''): ?>
      <meta name="twitter:image" content="<?= h($ogImage) ?>">
    <?php endif; ?>
    <script type="application/ld+json"><?= json_encode($jsonLdPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.tailwindcss.com">
    <?php if ($pagePath === '/index.php'): ?>
      <link rel="prefetch" href="/store.php" as="document">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&display=swap" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/material-image-frame.css')) ?>" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/site-brand.css')) ?>" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/site-header.css')) ?>" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/site-footer.css')) ?>" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/pwa-install.css')) ?>" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/site-page-loading.css')) ?>" rel="stylesheet">
    <link href="<?= h(portal_asset_url('/css/notifications.css')) ?>" rel="stylesheet">
    <?php if (!$isLightPage): ?>
      <link href="<?= h(portal_asset_url('/css/store-ui.css')) ?>" rel="stylesheet">
    <?php endif; ?>
    <?php if ($storeAllowCart && !$isLightPage): ?>
      <link href="<?= h(portal_asset_url('/css/store-cart.css')) ?>" rel="stylesheet">
    <?php endif; ?>
    <?php if ($enableOnboarding): ?>
      <link href="<?= h(portal_asset_url('/css/site-onboarding.css')) ?>" rel="stylesheet">
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

<?php if ($enableOnboarding): ?>
  <?php
    $siteOnboardingAutoStart = true;
    require __DIR__ . '/partials/site-onboarding.php';
  ?>
<?php endif; ?>

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
    window.PublicNav = { setOpen };
    openBtn.addEventListener('click', () => setOpen(true));
    closeBtn.addEventListener('click', () => setOpen(false));
    overlay.addEventListener('click', () => setOpen(false));
    drawer.querySelectorAll('[data-public-nav-link]').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
  })();
</script>
<?php if ($enableQuickView): ?>
  <?php require __DIR__ . '/partials/product-quick-view.php'; ?>
  <script src="<?= h(portal_asset_url('/assets/product-quick-view.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($storeShowPrice): ?>
  <script src="<?= h(portal_asset_url('/assets/store-pref.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($enableStoreCartJs): ?>
  <script src="<?= h(portal_asset_url('/assets/store-cart.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($enableOnboarding): ?>
  <script src="<?= h(portal_asset_url('/assets/site-onboarding.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($enableSiteAnalytics): ?>
  <script src="<?= h(portal_asset_url('/assets/site-analytics.js')) ?>" data-endpoint="/api/site-analytics.php" defer></script>
<?php endif; ?>
<script src="<?= h(portal_asset_url('/assets/pwa.js')) ?>" defer></script>
<script src="<?= h(portal_asset_url('/assets/site-page-loading.js')) ?>" defer></script>
<script src="<?= h(portal_asset_url('/assets/notifications.js')) ?>" defer></script>
<?php if (!empty($extraFooter ?? '')): ?>
  <?= $extraFooter ?>
<?php endif; ?>
</body>
</html>
