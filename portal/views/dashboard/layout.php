<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var array<string, mixed>|null $user */
/** @var string|null $currentRoute */

use Portal\Auth\WebSession;
use Portal\Support\DashboardNavigation;

require_once __DIR__ . '/../helpers.php';

if (WebSession::check()) {
    if (empty($_SESSION['staff_roles_provisioned'])) {
        \Portal\Support\StaffRoleProvisioner::ensureTaskRoles();
        $_SESSION['staff_roles_provisioned'] = true;
    }
    WebSession::refreshPermissions();
}

$user = WebSession::check() ? WebSession::user() : ($user ?? null);
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/dashboard/index.php';
$requestQuery = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
$currentRoute ??= $requestQuery !== '' ? $requestPath . '?' . $requestQuery : $requestPath;
$navArea = $dashboardNavArea ?? DashboardNavigation::areaForRoute($currentRoute, $user);
$areaMeta = DashboardNavigation::areaMeta($navArea);
$hasSiteContentAccess = DashboardNavigation::hasSiteContentAccess($user);
$hasConfigurationAccess = DashboardNavigation::hasConfigurationAccess($user);
$hasAccountingAccess = DashboardNavigation::canAccessAccountingArea($user);
$headerQuickLinks = DashboardNavigation::headerQuickLinks($user);
$areaTabs = DashboardNavigation::areaTabs($user, $navArea);
$bottomNavLinks = DashboardNavigation::bottomNavLinks($user);
$hasAreaTabs = count($areaTabs) > 1;
$sidebarGroups = DashboardNavigation::sidebarGroupsForArea($navArea, $user);
$sidebarTitle = $areaMeta['title'];
$sidebarSubtitle = $areaMeta['subtitle'];

$renderSidebarFooter = static function () use ($navArea): void {
    if ($navArea !== DashboardNavigation::AREA_OPERATIONS) {
        ?>
        <a href="/dashboard/index.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition" data-dashboard-nav-link>
          <span class="material-symbols-outlined">arrow_forward</span>
          العودة للعمل اليومي
        </a>
        <?php
    }
    ?>
    <a href="/index.php" data-dashboard-no-nav class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
      <span class="material-symbols-outlined">public</span>
      عرض الموقع
    </a>
    <?php
};

$renderNavLink = static function (array $item, string $currentRoute, bool $compact = false): void {
    $route = (string) ($item['route'] ?? '#');
    $isActive = DashboardNavigation::isNavItemActive($currentRoute, $item);
    $classes = $isActive
        ? 'bg-primary/10 text-primary font-bold border-r-4 border-primary'
        : 'text-text-muted hover:bg-surface-low';
    ?>
    <a
      href="<?= h($route) ?>"
      data-dashboard-route="<?= h($route) ?>"
      class="dashboard-sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 transition <?= $classes ?>"
      <?= $compact ? 'data-dashboard-nav-link' : '' ?>
    >
      <span class="material-symbols-outlined <?= $isActive ? 'fill' : '' ?>"><?= h((string) ($item['icon'] ?? 'circle')) ?></span>
      <span class="text-sm"><?= h((string) ($item['label'] ?? '')) ?></span>
    </a>
    <?php
};
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <?php
  use Portal\Services\PortalSettingsService;

  $dashboardCompany = PortalSettingsService::companySettings();
  $companyLogoUrl = PortalSettingsService::companyLogoUrl($dashboardCompany);
  $siteName = trim((string) ($dashboardCompany['company_name'] ?? '')) !== ''
      ? (string) $dashboardCompany['company_name']
      : 'جاويش للتجارة';
  require dirname(__DIR__) . '/partials/head-icons.php';
  ?>
  <title><?= h($title) ?> — لوحة التحكم</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link href="<?= h(portal_asset_url('/assets/dashboard/dashboard.css')) ?>" rel="stylesheet">
  <link href="<?= h(portal_asset_url('/css/notifications.css')) ?>" rel="stylesheet">
  <link href="/css/store-ui.css" rel="stylesheet">
  <link href="/css/store-cart.css" rel="stylesheet">
  <link href="/css/customer-portal.css" rel="stylesheet">
  <link href="<?= h(portal_asset_url('/css/tailwind.css')) ?>" rel="stylesheet">
  <style>
    body { font-family: 'Manrope', sans-serif; background-color: #f6f6f8; }
    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
      font-size: 22px;
      line-height: 1;
    }
    .material-symbols-outlined.fill {
      font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24;
    }
    #dashboard-drawer {
      transition: transform 0.25s ease, visibility 0.25s ease;
      transform: translate3d(100%, 0, 0);
      visibility: hidden;
      pointer-events: none;
    }
    #dashboard-drawer.is-open {
      transform: translate3d(0, 0, 0);
      visibility: visible;
      pointer-events: auto;
    }
    #dashboard-drawer-backdrop {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    #dashboard-drawer-backdrop.is-open { opacity: 1; visibility: visible; pointer-events: auto; }
    @media (min-width: 1024px) {
      #dashboard-drawer,
      #dashboard-drawer-backdrop {
        display: none !important;
      }
      .dashboard-desktop-sidebar {
        display: flex !important;
      }
    }
    @media (max-width: 1023px) {
      .dashboard-desktop-sidebar {
        display: none !important;
      }
    }
    .dashboard-area-tabs {
      display: flex;
      gap: 0.35rem;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      padding: 0.5rem 1rem;
      background: #ffffff;
      border-bottom: 1px solid #E5E7EB;
    }
    .dashboard-area-tabs::-webkit-scrollbar {
      display: none;
    }
    .dashboard-area-tab {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      white-space: nowrap;
      border-radius: 0.75rem;
      padding: 0.45rem 0.85rem;
      font-size: 0.75rem;
      font-weight: 800;
      color: #5d3f3c;
      border: 1px solid #E5E7EB;
      background: #fff;
      text-decoration: none;
      transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }
    .dashboard-area-tab:hover {
      background: #f3f3f5;
      color: #D81921;
    }
    .dashboard-area-tab.is-active {
      background: rgba(216, 25, 33, 0.1);
      border-color: rgba(216, 25, 33, 0.25);
      color: #D81921;
    }
    .dashboard-area-tab .material-symbols-outlined {
      font-size: 1rem;
    }
    @media (max-width: 1023px) {
      body.dashboard-app.has-bottom-nav .dashboard-main-content {
        padding-bottom: 0.75rem;
      }
      body.dashboard-app.has-bottom-nav .dashboard-bottom-nav-spacer {
        display: block;
        height: calc(5rem + 0.75rem + env(safe-area-inset-bottom, 0px));
      }
    }
  </style>
  <?php if (!empty($extraHead ?? '')): ?>
    <?= $extraHead ?>
  <?php endif; ?>
</head>
<body class="min-h-screen text-slate-900 dashboard-app<?= $hasAreaTabs ? ' has-area-tabs' : '' ?><?= $bottomNavLinks !== [] ? ' has-bottom-nav' : '' ?>" data-dashboard-has-area-tabs="<?= $hasAreaTabs ? '1' : '0' ?>">
  <header class="sticky top-0 z-50 h-16 bg-surface-white shadow-sm border-b border-border-subtle">
    <div class="h-full px-4 lg:px-10 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2 min-w-0">
        <button
          type="button"
          id="dashboard-menu-btn"
          class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl border border-border-subtle hover:bg-surface-low shrink-0"
          aria-controls="dashboard-drawer"
          aria-expanded="false"
          aria-label="فتح القائمة"
        >
          <span class="material-symbols-outlined">menu</span>
        </button>
        <a href="/dashboard/index.php" class="font-extrabold text-primary text-lg truncate">Jawish Trading</a>
        <?php if (!$hasAreaTabs && $headerQuickLinks !== []): ?>
        <nav class="hidden lg:flex items-center gap-1 text-sm mr-2" data-dashboard-header-quick-links>
          <?php foreach ($headerQuickLinks as $item): ?>
            <?php
              $itemRoute = (string) ($item['route'] ?? '');
              $isActive = $currentRoute === $itemRoute
                  || ($itemRoute === '/dashboard/accounting.php' && $navArea === DashboardNavigation::AREA_ACCOUNTING);
            ?>
            <a
              href="<?= h((string) ($item['route'] ?? '#')) ?>"
              class="px-3 py-1.5 rounded-lg <?= $isActive ? 'bg-primary/10 text-primary font-bold' : 'text-text-muted hover:bg-surface-low hover:text-primary' ?>"
            >
              <?= h((string) ($item['label'] ?? '')) ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <?php require __DIR__ . '/../partials/notification-bell.php'; ?>
        <div class="hidden md:flex flex-col items-end">
          <span class="text-sm font-bold"><?= h($user['display_name_ar'] ?? '') ?></span>
          <span class="text-xs text-text-muted" data-dashboard-header-area><?= h($sidebarTitle) ?></span>
        </div>
        <a href="/dashboard/profile.php" data-dashboard-nav-link class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-low text-gray-600 transition" aria-label="حسابي" title="حسابي">
          <span class="material-symbols-outlined">person</span>
        </a>
        <a href="/logout.php" data-dashboard-no-nav class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-red-600 transition" aria-label="تسجيل الخروج">
          <span class="material-symbols-outlined">logout</span>
        </a>
      </div>
    </div>
  </header>

  <?php if ($hasAreaTabs): ?>
    <nav class="dashboard-area-tabs sticky z-40" aria-label="أقسام لوحة التحكم" data-dashboard-area-tabs>
      <?php foreach ($areaTabs as $tab): ?>
        <a
          href="<?= h((string) ($tab['route'] ?? '#')) ?>"
          class="dashboard-area-tab <?= !empty($tab['active']) ? 'is-active' : '' ?>"
          data-dashboard-nav-link
        >
          <span class="material-symbols-outlined" aria-hidden="true"><?= h((string) ($tab['icon'] ?? 'circle')) ?></span>
          <?= h((string) ($tab['label'] ?? '')) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div id="dashboard-drawer-backdrop" aria-hidden="true"></div>
  <nav id="dashboard-drawer" class="lg:hidden" aria-label="قائمة لوحة التحكم" aria-hidden="true">
    <div class="px-4 py-4 border-b border-border-subtle mb-3 flex items-center justify-between gap-3">
      <div data-dashboard-sidebar-meta>
        <h2 class="font-bold text-primary"><?= h($sidebarTitle) ?></h2>
        <p class="text-xs text-text-muted mt-0.5"><?= h($sidebarSubtitle) ?></p>
      </div>
      <button type="button" id="dashboard-drawer-close" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-low text-gray-600 shrink-0" aria-label="إغلاق القائمة">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto px-4 pb-4 space-y-4" data-dashboard-sidebar-nav>
      <?php foreach ($sidebarGroups as $groupTitle => $items): ?>
        <section>
          <h3 class="text-xs text-text-muted mb-2 px-2"><?= h($groupTitle) ?></h3>
          <div class="space-y-1">
            <?php foreach ($items as $item): ?>
              <?php $renderNavLink($item, $currentRoute, true); ?>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
    <div class="p-4 border-t border-border-subtle space-y-2" data-dashboard-sidebar-footer>
      <?php $renderSidebarFooter(); ?>
    </div>
  </nav>

  <div class="flex">
    <aside class="dashboard-desktop-sidebar hidden lg:flex fixed right-0 w-64 bg-surface-white border-l border-border-subtle flex-col p-4 z-40">
      <div class="px-2 py-4 border-b border-border-subtle mb-4" data-dashboard-sidebar-meta>
        <h2 class="font-bold text-primary"><?= h($sidebarTitle) ?></h2>
        <p class="text-xs text-text-muted mt-1"><?= h($sidebarSubtitle) ?></p>
      </div>
      <div class="flex-1 overflow-y-auto" data-dashboard-sidebar-nav>
        <?php foreach ($sidebarGroups as $groupTitle => $items): ?>
          <section class="mb-4">
            <h3 class="text-xs text-text-muted mb-2 px-2"><?= h($groupTitle) ?></h3>
            <div class="space-y-1">
              <?php foreach ($items as $item): ?>
                <?php $renderNavLink($item, $currentRoute); ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
      <div class="mt-auto pt-4 border-t border-border-subtle space-y-2" data-dashboard-sidebar-footer>
        <?php $renderSidebarFooter(); ?>
      </div>
    </aside>
    <main class="flex-1 lg:mr-64 p-3 sm:p-4 md:p-6 lg:p-8 min-w-0 w-full max-w-[1600px] dashboard-main-content" data-dashboard-main data-current-route="<?= h($currentRoute) ?>"<?php if (!empty($dashboardPageAssets ?? '')): ?> data-dashboard-page-assets="<?= h((string) $dashboardPageAssets) ?>"<?php endif; ?>>
      <?php if ($navArea === DashboardNavigation::AREA_SITE_CONTENT && $currentRoute !== '/dashboard/site-content.php'): ?>
        <nav class="mb-4">
          <a href="/dashboard/site-content.php" class="inline-flex items-center gap-1 text-sm font-bold text-text-muted hover:text-primary">
            <span class="material-symbols-outlined text-base">chevron_right</span>
            العودة إلى محتوى الموقع
          </a>
        </nav>
      <?php elseif ($navArea === DashboardNavigation::AREA_CONFIGURATION && $currentRoute !== '/dashboard/configuration.php'): ?>
        <nav class="mb-4">
          <a href="/dashboard/configuration.php" class="inline-flex items-center gap-1 text-sm font-bold text-text-muted hover:text-primary">
            <span class="material-symbols-outlined text-base">chevron_right</span>
            العودة إلى إدارة النظام
          </a>
        </nav>
      <?php elseif ($navArea === DashboardNavigation::AREA_ACCOUNTING && $currentRoute !== '/dashboard/accounting.php'): ?>
        <nav class="mb-4">
          <a href="/dashboard/accounting.php" class="inline-flex items-center gap-1 text-sm font-bold text-text-muted hover:text-primary">
            <span class="material-symbols-outlined text-base">chevron_right</span>
            العودة إلى لوحة أمين
          </a>
        </nav>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>

  <?php if ($bottomNavLinks !== []): ?>
    <div class="dashboard-bottom-nav-spacer lg:hidden" aria-hidden="true" data-dashboard-bottom-spacer></div>
    <nav id="dashboard-bottom-nav" class="lg:hidden" aria-label="تنقل سريع">
      <?php foreach ($bottomNavLinks as $link): ?>
        <?php $isActive = DashboardNavigation::isNavItemActive($currentRoute, $link); ?>
        <a href="<?= h((string) ($link['route'] ?? '#')) ?>" data-dashboard-route="<?= h((string) ($link['route'] ?? '')) ?>" class="<?= $isActive ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined"><?= h((string) ($link['icon'] ?? 'circle')) ?></span>
          <span><?= h((string) ($link['label'] ?? '')) ?></span>
        </a>
      <?php endforeach; ?>
      <button type="button" id="dashboard-bottom-menu-btn" aria-label="المزيد">
        <span class="material-symbols-outlined">apps</span>
        <span>المزيد</span>
      </button>
    </nav>
  <?php endif; ?>

  <div id="dashboard-page-loader" aria-hidden="true"><div class="dash-spinner" role="status" aria-label="جاري التحميل"></div></div>
  <div id="dashboard-toast-root" aria-live="polite"></div>
  <?php
  require_once __DIR__ . '/partials/media-picker.php';
  portal_render_media_picker_modal();
  require __DIR__ . '/../partials/store-image-lightbox.php';
  ?>
  <script src="/assets/deferred-images.js" defer></script>
  <script src="/assets/store-image-zoom.js" defer></script>
  <script src="<?= h(portal_asset_url('/assets/dashboard/material-images-link.js')) ?>" defer></script>
  <script src="<?= h(portal_asset_url('/assets/dashboard/dashboard.js')) ?>" defer></script>
  <script src="/assets/dashboard/media-picker.js" defer></script>
  <script src="/assets/dashboard/site-media-upload.js" defer></script>
  <script src="/assets/dashboard/token-picker.js" defer></script>
  <script src="/assets/dashboard/home-sections.js" defer></script>
  <script src="/assets/dashboard/special-offers.js" defer></script>
  <script src="/assets/dashboard/about-editor.js" defer></script>
  <script src="/assets/dashboard/accounting-statement.js" defer></script>
  <script src="/assets/dashboard/material-image-zip-download.js" defer></script>
  <script src="<?= h(portal_asset_url('/assets/notifications.js')) ?>" defer></script>
  <?php if (!empty($extraScripts ?? '')): ?>
    <?= $extraScripts ?>
  <?php endif; ?>
  <?php if (!empty($extraFooter ?? '')): ?>
    <?= $extraFooter ?>
  <?php endif; ?>
</body>
</html>
