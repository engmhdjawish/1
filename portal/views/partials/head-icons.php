<?php

declare(strict_types=1);

/** @var string|null $companyLogoUrl */
/** @var string|null $siteName */

require_once __DIR__ . '/../helpers.php';

$siteName = trim((string) ($siteName ?? '')) !== '' ? (string) $siteName : 'جاويش للتجارة';
$icons = portal_site_icons($companyLogoUrl ?? null);
$pwaBuild = \Portal\Support\PwaBuild::id();
?>
<meta name="portal-build" content="<?= h($pwaBuild) ?>">
<link rel="manifest" href="/manifest.php">
<?= portal_defer_script('/assets/pwa-update.js') ?>
<script>
  /* Fallback only: assets/pwa-update.js owns registration and updates. Same URL scheme (/sw.js?b=<build>),
     so there is never a second competing registration. */
  (function () {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) { return; }
    var build = <?= json_encode($pwaBuild, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.addEventListener('load', function () {
      if (window.PortalPwaUpdate) { return; }
      navigator.serviceWorker.getRegistration('/').then(function (registration) {
        var worker = registration && (registration.installing || registration.waiting || registration.active);
        if (worker && /[?&]b=/.test(worker.scriptURL)) { return null; }
        return navigator.serviceWorker.register('/sw.js?b=' + encodeURIComponent(build), { scope: '/', updateViaCache: 'none' });
      }).catch(function () {});
    });
  })();
</script>
<link rel="icon" href="<?= h($icons['favicon_ico']) ?>" sizes="48x48">
<link rel="icon" href="<?= h($icons['favicon_svg']) ?>" type="image/svg+xml">
<link rel="icon" href="<?= h($icons['favicon_png_32']) ?>" type="image/png"<?= $icons['uses_company_logo'] ? '' : ' sizes="32x32"' ?>>
<link rel="apple-touch-icon" href="<?= h($icons['apple_touch']) ?>" sizes="180x180">
<meta name="theme-color" content="#D81921">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h($siteName) ?>">
