<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var array<string, mixed>|null $user */
/** @var string|null $currentRoute */

use Portal\Support\DashboardNavigation;

require_once __DIR__ . '/../helpers.php';

$user ??= null;
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/dashboard/index.php';
$requestQuery = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
$currentRoute ??= $requestQuery !== '' ? $requestPath . '?' . $requestQuery : $requestPath;
$navArea = $dashboardNavArea ?? DashboardNavigation::areaForRoute($currentRoute);
$areaMeta = DashboardNavigation::areaMeta($navArea);
$hasSiteContentAccess = DashboardNavigation::hasSiteContentAccess($user);
$hasConfigurationAccess = DashboardNavigation::hasConfigurationAccess($user);
$hasAccountingAccess = DashboardNavigation::canAccessAccounting($user);
$headerQuickLinks = DashboardNavigation::headerQuickLinks($user);
$areaTabs = DashboardNavigation::areaTabs($user, $navArea);
$sidebarGroups = DashboardNavigation::sidebarGroupsForArea($navArea, $user);
$sidebarTitle = $areaMeta['title'];
$sidebarSubtitle = $areaMeta['subtitle'];

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link href="/css/site-brand.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          colors: {
            primary: '#D81921',
            'surface-white': '#ffffff',
            'surface-low': '#f3f3f5',
            'surface-bg': '#f6f6f8',
            'border-subtle': '#E5E7EB',
            'text-muted': '#5d3f3c',
            'status-active': '#28A745',
            'status-rejected': '#EF4444',
            'status-pending': '#FFC107'
          }
        }
      }
    };
  </script>
  <link href="/assets/dashboard/dashboard.css" rel="stylesheet">
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
    #dashboardNavDrawer { transition: transform 0.25s ease; }
    #dashboardNavDrawer.is-open { transform: translateX(0); }
    #dashboardNavOverlay.is-open { opacity: 1; pointer-events: auto; }
    .dashboard-area-tabs {
      display: flex;
      gap: 0.35rem;
      overflow-x: auto;
      padding: 0.5rem 1rem;
      background: #ffffff;
      border-bottom: 1px solid #E5E7EB;
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
  </style>
  <?php if (!empty($extraHead ?? '')): ?>
    <?= $extraHead ?>
  <?php endif; ?>
</head>
<body class="min-h-screen text-slate-900">
  <header class="sticky top-0 z-50 h-16 bg-surface-white shadow-sm border-b border-border-subtle">
    <div class="h-full px-4 lg:px-10 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2 min-w-0">
        <button
          type="button"
          id="openDashboardNavBtn"
          class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl border border-border-subtle hover:bg-surface-low shrink-0"
          aria-controls="dashboardNavDrawer"
          aria-expanded="false"
          aria-label="فتح القائمة"
        >
          <span class="material-symbols-outlined">menu</span>
        </button>
        <a href="/dashboard/index.php" class="font-extrabold text-primary text-lg truncate">Jawish Trading</a>
        <nav class="hidden lg:flex items-center gap-1 text-sm mr-2">
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
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <?php if ($navArea === DashboardNavigation::AREA_OPERATIONS): ?>
          <?php if ($hasAccountingAccess): ?>
            <a href="/dashboard/accounting.php" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
              <span class="material-symbols-outlined text-base">account_balance</span>
              أمين
            </a>
          <?php endif; ?>
          <?php if ($hasSiteContentAccess): ?>
            <a href="/dashboard/site-content.php" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
              <span class="material-symbols-outlined text-base">web</span>
              <span class="hidden sm:inline">محتوى الموقع</span>
            </a>
          <?php endif; ?>
          <?php if ($hasConfigurationAccess): ?>
            <a href="/dashboard/configuration.php" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
              <span class="material-symbols-outlined text-base">tune</span>
              <span class="hidden sm:inline">إدارة النظام</span>
            </a>
          <?php endif; ?>
        <?php elseif ($navArea === DashboardNavigation::AREA_ACCOUNTING): ?>
          <a href="/dashboard/index.php" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
            <span class="material-symbols-outlined text-base">work</span>
            العمل اليومي
          </a>
        <?php elseif ($navArea === DashboardNavigation::AREA_SITE_CONTENT): ?>
          <a href="/dashboard/index.php" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
            <span class="material-symbols-outlined text-base">work</span>
            العمل اليومي
          </a>
          <?php if ($hasConfigurationAccess): ?>
            <a href="/dashboard/configuration.php" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
              <span class="material-symbols-outlined text-base">tune</span>
              إدارة النظام
            </a>
          <?php endif; ?>
        <?php else: ?>
          <a href="/dashboard/index.php" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
            <span class="material-symbols-outlined text-base">work</span>
            العمل اليومي
          </a>
          <?php if ($hasSiteContentAccess): ?>
            <a href="/dashboard/site-content.php" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-surface-low">
              <span class="material-symbols-outlined text-base">web</span>
              محتوى الموقع
            </a>
          <?php endif; ?>
        <?php endif; ?>
        <div class="hidden md:flex flex-col items-end">
          <span class="text-sm font-bold"><?= h($user['display_name_ar'] ?? '') ?></span>
          <span class="text-xs text-text-muted" data-dashboard-header-area><?= h($sidebarTitle) ?></span>
        </div>
        <a href="/logout.php" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-red-600 transition" aria-label="تسجيل الخروج">
          <span class="material-symbols-outlined">logout</span>
        </a>
      </div>
    </div>
  </header>

  <?php if (count($areaTabs) > 1): ?>
    <nav class="dashboard-area-tabs sticky top-16 z-40" aria-label="أقسام لوحة التحكم" data-dashboard-area-tabs>
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

  <div id="dashboardNavOverlay" class="lg:hidden fixed inset-0 bg-black/40 opacity-0 pointer-events-none z-40 transition" aria-hidden="true"></div>
  <aside
    id="dashboardNavDrawer"
    class="lg:hidden fixed top-16 right-0 h-[calc(100vh-64px)] w-72 max-w-[85vw] bg-surface-white border-l border-border-subtle z-50 flex flex-col p-4 translate-x-full"
    aria-hidden="true"
  >
    <div class="px-2 py-3 border-b border-border-subtle mb-3" data-dashboard-sidebar-meta>
      <h2 class="font-bold text-primary"><?= h($sidebarTitle) ?></h2>
      <p class="text-xs text-text-muted mt-1"><?= h($sidebarSubtitle) ?></p>
    </div>
    <div class="flex-1 overflow-y-auto space-y-4" data-dashboard-sidebar-nav>
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
    <div class="pt-4 border-t border-border-subtle space-y-2" data-dashboard-sidebar-footer>
      <?php if ($navArea === DashboardNavigation::AREA_OPERATIONS): ?>
        <?php if ($hasAccountingAccess): ?>
          <a href="/dashboard/accounting.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
            <span class="material-symbols-outlined">account_balance</span>
            أمين — المحاسبة
          </a>
        <?php endif; ?>
        <?php if ($hasSiteContentAccess): ?>
          <a href="/dashboard/site-content.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
            <span class="material-symbols-outlined">web</span>
            محتوى الموقع
          </a>
        <?php endif; ?>
        <?php if ($hasConfigurationAccess): ?>
          <a href="/dashboard/configuration.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
            <span class="material-symbols-outlined">tune</span>
            إدارة النظام
          </a>
        <?php endif; ?>
      <?php elseif ($navArea === DashboardNavigation::AREA_SITE_CONTENT): ?>
        <a href="/dashboard/index.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
          <span class="material-symbols-outlined">arrow_forward</span>
          العودة للعمل اليومي
        </a>
        <?php if ($hasConfigurationAccess): ?>
          <a href="/dashboard/configuration.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
            <span class="material-symbols-outlined">tune</span>
            إدارة النظام
          </a>
        <?php endif; ?>
      <?php else: ?>
        <a href="/dashboard/index.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
          <span class="material-symbols-outlined">arrow_forward</span>
          العودة للعمل اليومي
        </a>
        <?php if ($navArea === DashboardNavigation::AREA_CONFIGURATION && $hasSiteContentAccess): ?>
          <a href="/dashboard/site-content.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low" data-dashboard-nav-link>
            <span class="material-symbols-outlined">web</span>
            محتوى الموقع
          </a>
        <?php endif; ?>
      <?php endif; ?>
      <a href="/index.php" class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition" data-dashboard-nav-link>
        <span class="material-symbols-outlined">public</span>
        عرض الموقع
      </a>
    </div>
  </aside>

  <div class="flex">
    <aside class="hidden lg:flex fixed top-16 right-0 h-[calc(100vh-64px)] w-64 bg-surface-white border-l border-border-subtle flex-col p-4 z-40">
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
        <?php if ($navArea === DashboardNavigation::AREA_OPERATIONS): ?>
          <?php if ($hasAccountingAccess): ?>
            <a href="/dashboard/accounting.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
              <span class="material-symbols-outlined">account_balance</span>
              أمين — المحاسبة
            </a>
          <?php endif; ?>
          <?php if ($hasSiteContentAccess): ?>
            <a href="/dashboard/site-content.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
              <span class="material-symbols-outlined">web</span>
              محتوى الموقع
            </a>
          <?php endif; ?>
          <?php if ($hasConfigurationAccess): ?>
            <a href="/dashboard/configuration.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
              <span class="material-symbols-outlined">tune</span>
              إدارة النظام
            </a>
          <?php endif; ?>
        <?php elseif ($navArea === DashboardNavigation::AREA_SITE_CONTENT): ?>
          <a href="/dashboard/index.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
            <span class="material-symbols-outlined">arrow_forward</span>
            العودة للعمل اليومي
          </a>
          <?php if ($hasConfigurationAccess): ?>
            <a href="/dashboard/configuration.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
              <span class="material-symbols-outlined">tune</span>
              إدارة النظام
            </a>
          <?php endif; ?>
        <?php else: ?>
          <a href="/dashboard/index.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
            <span class="material-symbols-outlined">arrow_forward</span>
            العودة للعمل اليومي
          </a>
          <?php if ($navArea === DashboardNavigation::AREA_CONFIGURATION && $hasSiteContentAccess): ?>
            <a href="/dashboard/site-content.php" class="flex items-center justify-center gap-2 rounded-xl border border-border-subtle py-2.5 text-sm font-bold text-slate-700 hover:bg-surface-low transition">
              <span class="material-symbols-outlined">web</span>
              محتوى الموقع
            </a>
          <?php endif; ?>
        <?php endif; ?>
        <a href="/index.php" class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
          <span class="material-symbols-outlined">public</span>
          عرض الموقع
        </a>
      </div>
    </aside>
    <main class="flex-1 lg:mr-64 p-4 md:p-6 lg:p-8 min-w-0" data-dashboard-main data-current-route="<?= h($currentRoute) ?>">
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

  <script>
    (() => {
      const drawer = document.getElementById('dashboardNavDrawer');
      const overlay = document.getElementById('dashboardNavOverlay');
      const openBtn = document.getElementById('openDashboardNavBtn');
      if (!drawer || !overlay || !openBtn) return;
      const setOpen = (open) => {
        drawer.classList.toggle('is-open', open);
        overlay.classList.toggle('is-open', open);
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
      };
      openBtn.addEventListener('click', () => setOpen(true));
      overlay.addEventListener('click', () => setOpen(false));
      drawer.querySelectorAll('[data-dashboard-nav-link]').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
      });
    })();
  </script>
  <div id="dashboard-page-loader" aria-hidden="true"><div class="dash-spinner" role="status" aria-label="جاري التحميل"></div></div>
  <div id="dashboard-toast-root" aria-live="polite"></div>
  <?php
  require_once __DIR__ . '/partials/media-picker.php';
  portal_render_media_picker_modal();
  ?>
  <script src="/assets/dashboard/dashboard.js" defer></script>
  <script src="/assets/dashboard/media-picker.js" defer></script>
  <script src="/assets/dashboard/token-picker.js" defer></script>
  <script src="/assets/dashboard/home-sections.js" defer></script>
  <script src="/assets/dashboard/special-offers.js" defer></script>
  <script src="/assets/dashboard/about-editor.js" defer></script>
  <?php if (!empty($extraScripts ?? '')): ?>
    <?= $extraScripts ?>
  <?php endif; ?>
</body>
</html>
