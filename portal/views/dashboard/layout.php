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
$roleLabel = (string) ($user['role_label'] ?? 'موظف');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — لوحة التحكم</title>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
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
    #mobileNavDrawer { transition: transform 0.25s ease; }
    #mobileNavDrawer.is-open { transform: translateX(0); }
    #mobileNavOverlay.is-open { opacity: 1; pointer-events: auto; }
  </style>
</head>
<body class="min-h-screen text-slate-900">
  <header class="sticky top-0 z-50 h-16 bg-surface-white shadow-sm border-b border-border-subtle">
    <div class="h-full px-4 lg:px-10 flex items-center justify-between gap-3">
      <div class="flex items-center gap-2 sm:gap-4 min-w-0">
        <button
          type="button"
          id="openMobileNavBtn"
          class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl border border-border-subtle hover:bg-surface-low transition shrink-0"
          aria-controls="mobileNavDrawer"
          aria-expanded="false"
          aria-label="فتح القائمة"
        >
          <span class="material-symbols-outlined">menu</span>
        </button>
        <span class="font-extrabold text-primary text-base sm:text-lg truncate">Jawish Trading</span>
        <?php if ($quickLinks !== []): ?>
          <nav class="hidden lg:flex items-center gap-4 text-sm">
            <?php foreach ($quickLinks as $link): ?>
              <a
                href="<?= h($link['route']) ?>"
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
        <a href="/logout.php" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-red-600 transition" title="تسجيل الخروج">
          <span class="material-symbols-outlined">logout</span>
        </a>
      </div>
    </div>
  </header>

  <div
    id="mobileNavOverlay"
    class="lg:hidden fixed inset-0 z-[60] bg-black/40 opacity-0 pointer-events-none transition"
    aria-hidden="true"
  ></div>
  <aside
    id="mobileNavDrawer"
    class="lg:hidden fixed top-0 right-0 z-[70] h-full w-[min(88vw,320px)] max-w-full bg-surface-white border-l border-border-subtle shadow-2xl flex flex-col translate-x-full"
    aria-hidden="true"
  >
    <div class="flex items-center justify-between gap-3 px-4 py-4 border-b border-border-subtle">
      <div>
        <h2 class="font-bold text-primary">القائمة</h2>
        <p class="text-xs text-text-muted mt-0.5"><?= h($roleLabel) ?></p>
      </div>
      <button
        type="button"
        id="closeMobileNavBtn"
        class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-gray-600"
        aria-label="إغلاق القائمة"
      >
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto p-4">
      <?php if ($navigation === []): ?>
        <p class="text-sm text-text-muted px-2">لا توجد صفحات متاحة لحسابك.</p>
      <?php else: ?>
        <?php require __DIR__ . '/partials/sidebar-nav.php'; ?>
      <?php endif; ?>
    </div>
    <div class="p-4 border-t border-border-subtle">
      <a href="/index.php" class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
        <span class="material-symbols-outlined">public</span>
        عرض الموقع
      </a>
    </div>
  </aside>

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
        <a href="/index.php" class="flex items-center justify-center gap-2 bg-primary text-white rounded-xl py-2.5 font-bold hover:brightness-110 transition">
          <span class="material-symbols-outlined">public</span>
          عرض الموقع
        </a>
      </div>
    </aside>
    <main class="flex-1 lg:mr-72 p-4 md:p-6 lg:p-8 min-w-0 pb-24 lg:pb-8"><?= $content ?></main>
  </div>

  <?php if ($navigation !== []): ?>
    <nav class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-surface-white border-t border-border-subtle shadow-[0_-4px_16px_rgba(0,0,0,0.06)] px-2 py-1.5" aria-label="اختصارات سريعة">
      <div class="grid gap-1" style="grid-template-columns: repeat(<?= min(4, count($quickLinks)) ?>, minmax(0, 1fr));">
        <?php foreach (array_slice($quickLinks, 0, 4) as $link): ?>
          <?php $isActive = $currentRoute === $link['route']; ?>
          <a
            href="<?= h($link['route']) ?>"
            class="flex flex-col items-center justify-center gap-0.5 rounded-xl px-1 py-2 text-[11px] transition <?= $isActive ? 'text-primary font-bold bg-primary/5' : 'text-text-muted' ?>"
          >
            <span class="material-symbols-outlined text-[20px] <?= $isActive ? 'fill' : '' ?>"><?= h($link['icon']) ?></span>
            <span class="truncate w-full text-center"><?= h($link['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </nav>
  <?php endif; ?>

  <script>
    (() => {
      const drawer = document.getElementById('mobileNavDrawer');
      const overlay = document.getElementById('mobileNavOverlay');
      const openBtn = document.getElementById('openMobileNavBtn');
      const closeBtn = document.getElementById('closeMobileNavBtn');
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
      drawer.querySelectorAll('[data-nav-link]').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
      });
    })();
  </script>
</body>
</html>
