<?php

declare(strict_types=1);

use Portal\Auth\WebSession;
use Portal\Support\DashboardNavigation;

/** @var string $title */
/** @var string $content */
/** @var array<string, mixed>|null $user */
/** @var string|null $currentRoute */

require_once dirname(__DIR__) . '/helpers.php';

$currentRoute ??= parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/dashboard/index.php';
$user ??= WebSession::user();
$navigation = DashboardNavigation::forUser($user);
$quickLinks = DashboardNavigation::quickLinks(4);
$bottomNav = array_slice($quickLinks, 0, 3);
$roleLabel = (string) ($user['role_label'] ?? 'موظف');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/dashboard/dashboard.css">
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
    #dashboard-drawer { transition: transform 0.25s ease; }
    #dashboard-drawer.is-open { transform: translateX(0); }
    #dashboard-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }
  </style>
</head>
<body class="min-h-screen text-slate-900 dashboard-app">
  <div id="dashboard-page-loader" aria-hidden="true"><div class="dash-spinner"></div></div>
  <div id="dashboard-toast-root" aria-live="polite"></div>

  <header class="sticky top-0 z-50 h-16 bg-surface-white shadow-sm border-b border-border-subtle">
    <div class="h-full px-3 sm:px-4 lg:px-10 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2 sm:gap-4 min-w-0">
        <button
          type="button"
          id="dashboard-menu-btn"
          class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl border border-border-subtle hover:bg-surface-low text-slate-700 shrink-0"
          aria-controls="dashboard-drawer"
          aria-expanded="false"
          aria-label="فتح القائمة"
        >
          <span class="material-symbols-outlined">menu</span>
        </button>
        <a href="/dashboard/index.php" data-dashboard-route="/dashboard/index.php" class="font-extrabold text-primary text-base sm:text-lg truncate">Jawish Trading</a>
        <?php if ($quickLinks !== []): ?>
          <nav class="hidden lg:flex items-center gap-4 text-sm shrink-0">
            <?php foreach ($quickLinks as $link): ?>
              <a
                href="<?= h($link['route']) ?>"
                data-dashboard-route="<?= h($link['route']) ?>"
                class="<?= $currentRoute === $link['route'] ? 'text-primary font-bold border-b-2 border-primary pb-1' : 'text-text-muted hover:text-primary' ?>"
              >
                <?= h($link['label']) ?>
              </a>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-2 sm:gap-3 shrink-0">
        <div class="hidden md:flex flex-col items-end">
          <span class="text-sm font-bold"><?= h($user['display_name_ar'] ?? '') ?></span>
          <span class="text-xs text-text-muted"><?= h($roleLabel) ?></span>
        </div>
        <a href="/logout.php" data-dashboard-no-nav class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-red-600 transition" title="تسجيل الخروج">
          <span class="material-symbols-outlined">logout</span>
        </a>
      </div>
    </div>
  </header>

  <div id="dashboard-drawer-backdrop" aria-hidden="true"></div>
  <nav id="dashboard-drawer" aria-label="قائمة لوحة التحكم">
    <div class="px-4 py-4 border-b border-border-subtle mb-3 flex items-center justify-between gap-3">
      <div>
        <h2 class="font-bold text-primary">القائمة</h2>
        <p class="text-xs text-text-muted mt-0.5"><?= h($roleLabel) ?></p>
      </div>
      <button type="button" id="dashboard-drawer-close" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-low text-gray-600" aria-label="إغلاق القائمة">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto px-4 pb-4">
      <?php if ($navigation === []): ?>
        <p class="text-sm text-text-muted px-2">لا توجد صفحات متاحة لحسابك.</p>
      <?php else: ?>
        <?php require __DIR__ . '/partials/sidebar-nav.php'; ?>
      <?php endif; ?>
    </div>
    <div class="p-4 border-t border-border-subtle">
      <a href="/index.php" data-dashboard-no-nav class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
        <span class="material-symbols-outlined">public</span>
        عرض الموقع
      </a>
    </div>
  </nav>

  <div class="flex">
    <aside class="hidden lg:flex fixed top-16 right-0 h-[calc(100vh-64px)] w-72 bg-surface-white border-l border-border-subtle flex-col z-40">
      <div class="px-4 py-4 border-b border-border-subtle">
        <h2 class="font-bold text-primary">لوحة التحكم</h2>
        <p class="text-xs text-text-muted mt-1">نظام إدارة البوابة</p>
      </div>
      <div class="flex-1 overflow-y-auto p-4">
        <?php if ($navigation === []): ?>
          <p class="text-sm text-text-muted px-2">لا توجد صفحات متاحة لحسابك.</p>
        <?php else: ?>
          <?php require __DIR__ . '/partials/sidebar-nav.php'; ?>
        <?php endif; ?>
      </div>
      <div class="p-4 border-t border-border-subtle">
        <a href="/index.php" data-dashboard-no-nav class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
          <span class="material-symbols-outlined">public</span>
          عرض الموقع
        </a>
      </div>
    </aside>

    <main
      id="dashboard-main"
      data-dashboard-main
      data-current-route="<?= h($currentRoute) ?>"
      class="flex-1 lg:mr-72 p-3 sm:p-4 md:p-6 lg:p-8 min-w-0 w-full max-w-[1600px] pb-24 lg:pb-8"
    ><?= $content ?></main>
  </div>

  <?php if ($bottomNav !== []): ?>
    <nav id="dashboard-bottom-nav" class="lg:hidden" aria-label="تنقل سريع">
      <?php foreach ($bottomNav as $link): ?>
        <?php $isActive = $currentRoute === $link['route']; ?>
        <a href="<?= h($link['route']) ?>" data-dashboard-route="<?= h($link['route']) ?>" class="<?= $isActive ? 'is-active' : '' ?>">
          <span class="material-symbols-outlined"><?= h($link['icon']) ?></span>
          <span><?= h($link['label']) ?></span>
        </a>
      <?php endforeach; ?>
      <button type="button" id="dashboard-bottom-menu-btn" aria-label="المزيد">
        <span class="material-symbols-outlined">apps</span>
        <span>المزيد</span>
      </button>
    </nav>
  <?php endif; ?>

  <script src="/assets/dashboard/dashboard.js" defer></script>
</body>
</html>
