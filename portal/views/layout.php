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
/** @var bool|null $deferStoreCartJs */
/** @var bool|null $enableOnboarding */
/** @var string|null $lcpPreloadUrl */

require_once __DIR__ . '/helpers.php';

$companyContext ??= PortalSettingsService::companySettings();
$companyLogoUrl ??= PortalSettingsService::companyLogoUrl($companyContext);
$siteName = trim((string) ($companyContext['company_name'] ?? '')) !== ''
    ? (string) $companyContext['company_name']
    : 'جاويش للتجارة';

$customer = CustomerSession::check() ? CustomerSession::customer() : null;
$staffLoggedIn = WebSession::check();
$pagePath = portal_request_path();
$isCatalogPage = portal_is_catalog_page($pagePath);
$isLightPage = in_array($pagePath, ['/login.php', '/staff-login.php', '/customer-login.php', '/register.php', '/about.php'], true);
$storeDisplay = StoreCatalogService::displayOptions();
StorePricePreference::bootstrap();
StorePricePreference::applyFromRequest($_GET);
$storeShowPrice = StoreCatalogService::headerShowsPriceCurrency($pagePath);
$storePriceCurrency = StorePricePreference::current();
$storeAllowCart = (bool) ($storeDisplay['allow_cart'] ?? false);
$storeCartCount = $storeAllowCart ? StoreCartService::itemCount() : 0;
$storeCartPackageCount = $storeAllowCart ? StoreCartService::packageCount() : 0.0;

$enableQuickView = (bool) ($enableQuickView ?? $isCatalogPage);
$deferStoreCartJs = (bool) ($deferStoreCartJs ?? false);
$enableStoreCartJs = (bool) ($enableStoreCartJs ?? ($storeAllowCart && !$isLightPage && !$deferStoreCartJs));
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
    ['href' => '/index.php', 'label' => 'الرئيسية', 'icon' => 'home'],
    ['href' => '/store.php', 'label' => 'المتجر', 'icon' => 'storefront'],
    ['href' => '/about.php', 'label' => 'من نحن', 'icon' => 'info'],
];
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
    <?php if (!empty($lcpPreloadUrl ?? '')): ?>
      <?= portal_preload_image((string) $lcpPreloadUrl) ?>
    <?php endif; ?>
    <?= portal_stylesheet('/css/site-app.min.css') ?>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Material+Symbols+Outlined&display=swap" rel="stylesheet">
    <style><?= portal_inline_critical_css() ?>
      body { font-family: Manrope, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f6f6f8; color: #111827; }
      .site-link { color: #374151; }
      .site-link:hover, .site-link.is-active { color: #D81921; }
      .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
      .material-symbols-outlined { font-family: 'Material Symbols Outlined', sans-serif; font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24; vertical-align: middle; line-height: 1; font-size: 1.35rem; }
    </style>
    <?php if ($pagePath === '/index.php'): ?>
      <link rel="prefetch" href="/store.php" as="document">
    <?php endif; ?>
    <?php if (!empty($extraHead ?? '')): ?>
      <?= $extraHead ?>
    <?php endif; ?>
</head>
<body class="min-h-screen text-text-main bg-surface-bg flex flex-col" data-store-price-currency="<?= h($storePriceCurrency) ?>">
<?php require __DIR__ . '/partials/site-header.php'; ?>

<div class="max-w-7xl w-full mx-auto px-4 pt-4 md:pt-6">
  <?php require __DIR__ . '/partials/amine-service-banner.php'; ?>
</div>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 py-6 md:py-8">
  <?= $content ?>
</main>

<?php require __DIR__ . '/partials/site-footer.php'; ?>

<button type="button" id="siteScrollTopBtn" class="site-scroll-top" hidden aria-label="العودة لأعلى الصفحة">
  <span class="material-symbols-outlined" aria-hidden="true">keyboard_arrow_up</span>
</button>

<?php if ($storeAllowCart && !in_array($pagePath, ['/store-cart.php', '/cart.php'], true)): ?>
  <?php require __DIR__ . '/partials/store-cart-drawer.php'; ?>
  <?php require __DIR__ . '/partials/store-image-lightbox.php'; ?>
<?php endif; ?>

<?php if (($enableStoreCartJs || $deferStoreCartJs) && empty($GLOBALS['storeProductPreviewRendered'])): ?>
  <?php $GLOBALS['storeProductPreviewRendered'] = true; ?>
  <?php require __DIR__ . '/partials/store-product-preview.php'; ?>
<?php endif; ?>

<?php if ($enableOnboarding): ?>
  <?php
    $siteOnboardingAutoStart = true;
    require __DIR__ . '/partials/site-onboarding.php';
  ?>
<?php endif; ?>

<?= portal_defer_script('/assets/public-nav.js') ?>
<?php if ($enableQuickView): ?>
  <?php require __DIR__ . '/partials/product-quick-view.php'; ?>
  <?= portal_defer_script('/assets/product-quick-view.js') ?>
<?php endif; ?>
<?php if ($storeShowPrice): ?>
  <?= portal_defer_script('/assets/store-pref.js') ?>
<?php endif; ?>
<?php if ($enableStoreCartJs): ?>
  <script type="application/json" id="storeCartBootstrap"><?= json_encode(
      StoreCartService::bootstrapPayload(),
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
  ) ?></script>
  <script>
    (() => {
      if (window.__storeCartSubmitGuard) return;
      window.__storeCartSubmitGuard = true;
      document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-store-add-cart')) return;
        event.preventDefault();
      }, true);
    })();
  </script>
  <?= portal_defer_script('/assets/store-image-zoom.js') ?>
  <?= portal_defer_script('/assets/store-cart.js') ?>
  <?php if (empty($GLOBALS['storeProductPreviewScriptLoaded'])): ?>
    <?php $GLOBALS['storeProductPreviewScriptLoaded'] = true; ?>
    <?= portal_defer_script('/assets/store-product-preview.js') ?>
  <?php endif; ?>
<?php elseif ($deferStoreCartJs): ?>
  <script type="application/json" id="storeCartBootstrap"><?= json_encode(
      StoreCartService::bootstrapPayload(),
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
  ) ?></script>
  <script type="application/json" id="deferStoreScriptUrls"><?= json_encode([
      portal_asset_url('/assets/store-image-zoom.js'),
      portal_asset_url('/assets/store-cart.js'),
      portal_asset_url('/assets/store-product-preview.js'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <script>
    (() => {
      if (window.__storeCartSubmitGuard) return;
      window.__storeCartSubmitGuard = true;
      document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-store-add-cart')) return;
        event.preventDefault();
      }, true);
    })();
  </script>
<?php endif; ?>
<?php if ($enableOnboarding): ?>
  <?= portal_defer_script('/assets/site-onboarding.js') ?>
<?php endif; ?>
<?php if ($enableSiteAnalytics): ?>
  <script src="<?= h(portal_asset_url('/assets/site-analytics.js')) ?>" data-endpoint="/api/site-analytics.php" defer></script>
<?php endif; ?>
<?= portal_defer_script('/assets/phone-input.js') ?>
<?php if (!empty($enableLoginPageJs)): ?>
  <?= portal_defer_script('/assets/login-page.js') ?>
<?php endif; ?>
<?= portal_defer_script('/assets/pwa.js') ?>
<?= portal_defer_script('/assets/site-page-loading.js') ?>
<?= portal_defer_script('/assets/notifications.js') ?>
<?php if ($staffLoggedIn || $customer !== null): ?>
<script>
(function () {
  const visitorId = (() => {
    try {
      const key = 'jawish_vid';
      let id = localStorage.getItem(key);
      if (!id) {
        id = window.crypto?.randomUUID?.() || `v-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        localStorage.setItem(key, id);
      }
      return id;
    } catch {
      return '';
    }
  })();
  const beat = () => fetch('/api/session-heartbeat.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ visitor_id: visitorId }),
  })
    .then((response) => response.json().catch(() => null))
    .then((data) => {
      if (!data || !data.login_required) return;
      const path = window.location.pathname + window.location.search;
      if (path.startsWith('/dashboard')) {
        window.location.href = '/staff-login.php?redirect=' + encodeURIComponent(path);
        return;
      }
      if (path.startsWith('/my-') || path.startsWith('/cart.php') || path.startsWith('/store-cart.php')) {
        window.location.href = '/customer-login.php?redirect=' + encodeURIComponent(path);
      }
    })
    .catch(() => {});
  beat();
  window.setInterval(beat, 60000);
})();
</script>
<?php endif; ?>
<?php if (!empty($extraFooter ?? '')): ?>
  <?= $extraFooter ?>
<?php endif; ?>
</body>
</html>
