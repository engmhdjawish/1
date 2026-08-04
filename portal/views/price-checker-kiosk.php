<?php

declare(strict_types=1);

/** @var string $self */
/** @var string $siteName */
/** @var string $pageTitle */
/** @var string $logoUrl */
/** @var int $displaySec */
/** @var int $errorSec */
/** @var int $promoInterval */
/** @var bool $promoShowPrice */
/** @var bool $slideshowEnabled */
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
  <meta name="theme-color" content="#D81921" />
  <title><?= h($pageTitle) ?> — <?= h($siteName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { manrope: ['Manrope', 'sans-serif'] },
          colors: {
            primary: '#D81921', tertiary: '#005990',
            surface: '#f9f9fb', 'on-surface': '#1a1c1d',
            'on-surface-variant': '#5d3f3c',
            'surface-container': '#edeef0', 'surface-variant': '#e2e2e4',
          }
        }
      }
    };
  </script>
  <link rel="stylesheet" href="<?= h(portal_asset_url('/css/price-checker.css')) ?>" />
</head>
<body class="text-on-surface bg-surface">

  <div id="loading-overlay" class="loading-overlay is-hidden"><div class="spinner"></div></div>

  <section id="state-standby" class="state-screen">
    <div id="ss-screensaver"<?= $slideshowEnabled ? '' : ' class="no-promo"' ?>>
      <div class="ss-idle-pattern" aria-hidden="true"></div>
      <div id="ss-bg-a" class="ss-bg" aria-hidden="true"></div>
      <div id="ss-bg-b" class="ss-bg" aria-hidden="true"></div>
      <div class="ss-veil" aria-hidden="true"></div>

      <header class="ss-header">
        <div>
          <?php if ($logoUrl !== ''): ?>
            <img src="<?= h($logoUrl) ?>" alt="<?= h($siteName) ?>" class="logo-img w-auto object-contain" decoding="async" fetchpriority="high" onerror="this.style.display='none';this.nextElementSibling?.classList.remove('hidden');" />
            <span class="logo-text hidden"><?= h($siteName) ?></span>
          <?php else: ?>
            <span class="logo-text"><?= h($siteName) ?></span>
          <?php endif; ?>
        </div>
        <div id="clock" class="ss-clock tabular-nums"></div>
      </header>

      <main class="ss-stage">
        <div class="ss-idle-logo">
          <?php if ($logoUrl !== ''): ?>
            <img src="<?= h($logoUrl) ?>" alt="<?= h($siteName) ?>" class="logo-img w-auto object-contain drop-shadow-lg" decoding="async" onerror="this.style.display='none'" />
          <?php endif; ?>
          <p class="mt-4 text-lg md:text-xl font-bold text-on-surface-variant"><?= h($siteName) ?></p>
        </div>

        <?php if ($slideshowEnabled): ?>
        <article id="promo-card" aria-live="polite">
          <div id="promo-card-header">
            <h2 class="font-extrabold text-primary">عرض مميز</h2>
            <span id="promo-counter" class="font-bold text-zinc-400 bg-zinc-100 rounded-full tabular-nums">—</span>
          </div>
          <div id="promo-image-wrap">
            <span id="promo-badge-live" class="bg-primary text-white font-extrabold rounded-full shadow">متوفر</span>
            <img id="promo-image" src="" alt="" class="is-loading" loading="lazy" />
          </div>
          <div id="promo-body">
            <p id="promo-manufacturer" class="font-bold text-tertiary uppercase tracking-wider truncate"></p>
            <p id="promo-name" class="font-extrabold text-zinc-900 leading-snug line-clamp-2"></p>
            <div class="flex gap-2">
              <div id="promo-price-sp-box" class="hidden flex-1 bg-primary/10 border border-primary/15 text-center">
                <p class="text-primary font-bold">ل.س</p>
                <p id="promo-price-sp" class="font-extrabold text-primary tabular-nums leading-none"></p>
              </div>
              <div id="promo-price-usd-box" class="hidden flex-1 bg-tertiary/10 border border-tertiary/15 text-center">
                <p class="text-tertiary font-bold">USD</p>
                <p id="promo-price-usd" class="font-extrabold text-tertiary tabular-nums leading-none"></p>
              </div>
            </div>
          </div>
          <div id="promo-card-footer">
            <div id="promo-dots" class="flex items-center justify-center gap-1.5 min-h-[8px]"></div>
          </div>
        </article>
        <?php endif; ?>
      </main>

      <footer class="ss-scan-bar" aria-label="تعليمات المسح">
        <div class="ss-scan-inner">
          <div class="ss-scan-icon" aria-hidden="true">
            <svg class="w-6 h-6 text-white" viewBox="0 0 64 64" fill="none">
              <rect x="10" y="10" width="44" height="44" rx="6" stroke="currentColor" stroke-width="3" opacity=".9"/>
              <path d="M18 24V18H24M40 18H46V24M46 40V46H40M24 46H18V40" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
              <rect x="22" y="27" width="2" height="10" fill="currentColor"/>
              <rect x="30" y="24" width="3" height="16" fill="currentColor"/>
              <rect x="39" y="26" width="2" height="12" fill="currentColor"/>
            </svg>
          </div>
          <div class="ss-scan-text text-right">
            <h2>امسح الباركود لمعرفة السعر</h2>
            <p>ضع المنتج أمام الماسح الضوئي — النظام جاهز</p>
          </div>
        </div>
      </footer>

      <?php if ($slideshowEnabled): ?>
      <div id="ss-progress-wrap"><div id="promo-progress"></div></div>
      <?php endif; ?>
    </div>

    <div id="scan-preview" class="text-xs md:text-sm font-mono border px-4 py-2 rounded-full opacity-0 transition-all shadow-lg">...</div>
  </section>

  <section id="state-error" class="state-screen hidden-state is-hidden bg-slate-50 h-screen max-h-screen overflow-hidden">
    <main class="h-full flex items-center justify-center diagonal-stripes relative">
      <div class="text-center px-6 max-w-lg">
        <div class="w-44 h-44 mx-auto mb-8 bg-white border border-red-100 rounded-full flex items-center justify-center shadow-xl">
          <svg class="w-20 h-20 text-red-600" viewBox="0 0 24 24" fill="none">
            <rect x="10.5" y="3" width="3" height="18" rx="1.5" transform="rotate(45 12 12)" fill="currentColor"/>
            <rect x="10.5" y="3" width="3" height="18" rx="1.5" transform="rotate(-45 12 12)" fill="currentColor"/>
          </svg>
        </div>
        <h1 id="error-title" class="text-3xl font-extrabold text-red-600">الباركود خاطئ</h1>
        <p id="error-message" class="mt-3 text-slate-500 text-lg">يرجى المحاولة مرة أخرى</p>
        <p id="error-barcode" class="mt-4 font-mono text-sm text-slate-400 bg-white/70 inline-block px-4 py-1.5 rounded-full border"></p>
        <div class="mt-12 w-56 h-1 bg-slate-200 rounded-full overflow-hidden mx-auto">
          <div class="h-full bg-red-600 w-full animate-progress"></div>
        </div>
      </div>
    </main>
  </section>

  <section id="state-product" class="state-screen hidden-state is-hidden bg-surface h-screen max-h-screen flex flex-col overflow-hidden">
    <header id="product-header" class="pc-product-header bg-white border-b-4 border-primary/10 flex flex-row-reverse justify-between items-center px-6 md:px-10 h-24 md:h-28 relative shadow-md shrink-0">
      <div class="shrink-0 bg-white p-1.5 rounded-xl border shadow-sm">
        <?php if ($logoUrl !== ''): ?>
          <img src="<?= h($logoUrl) ?>" alt="<?= h($siteName) ?>" class="h-14 w-auto max-w-[120px] object-contain" decoding="async" onerror="this.style.display='none'" />
        <?php endif; ?>
      </div>
      <div class="flex flex-col items-start gap-0.5 flex-1 overflow-hidden ml-4 min-w-0">
        <h1 id="product-badge-name" class="text-zinc-900 font-extrabold text-2xl md:text-4xl lg:text-5xl leading-tight break-words line-clamp-2 w-full">اسم المنتج</h1>
        <p id="product-barcode" class="font-mono text-xs md:text-sm text-zinc-400 truncate w-full"></p>
      </div>
      <div class="absolute bottom-0 left-0 w-full h-1.5 bg-zinc-100">
        <div id="product-progress-bar" class="h-full bg-primary" style="width:100%"></div>
      </div>
    </header>
    <main id="product-main" class="pc-product-main flex-1 p-4 md:p-8 flex flex-col gap-4 md:gap-6 overflow-hidden bg-[#f0f2f5] min-h-0">
      <div id="product-prices-offer" class="pc-offer-board hidden" aria-live="polite">
        <div class="pc-offer-board__top">
          <div id="product-offer-discount" class="pc-offer-board__discount hidden">
            <span class="pc-offer-board__discount-value">-0%</span>
            <span class="pc-offer-board__discount-label">حسم</span>
          </div>
          <div class="pc-offer-board__headlines">
            <p class="pc-offer-board__eyebrow">عرض خاص على هذا المنتج</p>
            <p id="product-offer-badge" class="pc-offer-board__badge">عرض خاص</p>
            <p id="product-offer-title" class="pc-offer-board__title"></p>
          </div>
        </div>

        <div class="pc-offer-board__syp">
          <div id="offer-sp-unit-old-col" class="pc-offer-board__col pc-offer-board__col--before hidden">
            <span class="pc-offer-board__label">السعر قبل الحسم</span>
            <p id="offer-sp-unit-old" class="pc-offer-board__price-old">—</p>
            <span class="pc-offer-board__hint">ل.س للقطعة</span>
          </div>
          <div class="pc-offer-board__arrow" aria-hidden="true">←</div>
          <div class="pc-offer-board__col pc-offer-board__col--after">
            <span class="pc-offer-board__label pc-offer-board__label--hot">سعر العرض الآن</span>
            <p id="offer-sp-unit-new" class="pc-offer-board__price-new">0</p>
            <span class="pc-offer-board__hint">ل.س للقطعة</span>
          </div>
        </div>

        <div id="offer-sp-box-row" class="pc-offer-board__row hidden">
          <span class="pc-offer-board__row-label">سعر الطرد</span>
          <span id="offer-sp-box-old" class="pc-offer-board__row-old">—</span>
          <span class="pc-offer-board__row-arrow" aria-hidden="true">←</span>
          <span id="offer-sp-box-new" class="pc-offer-board__row-new">—</span>
        </div>

        <div id="offer-usd-block" class="pc-offer-board__usd hidden">
          <div class="pc-offer-board__usd-head">السعر بالدولار</div>
          <div class="pc-offer-board__usd-grid">
            <div id="offer-usd-unit-old-wrap" class="pc-offer-board__usd-col hidden">
              <span class="pc-offer-board__label">قبل</span>
              <p id="offer-usd-unit-old" class="pc-offer-board__usd-old">—</p>
            </div>
            <div class="pc-offer-board__usd-col">
              <span class="pc-offer-board__label pc-offer-board__label--hot">العرض</span>
              <p id="offer-usd-unit-new" class="pc-offer-board__usd-new">$0</p>
            </div>
          </div>
          <div id="offer-usd-box-row" class="pc-offer-board__usd-box hidden">
            <span>الطرد:</span>
            <span id="offer-usd-box-old" class="pc-offer-board__row-old">—</span>
            <span>←</span>
            <span id="offer-usd-box-new" class="pc-offer-board__row-new">—</span>
          </div>
        </div>
      </div>

      <div id="product-prices-normal" class="pc-prices-normal grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-8 flex-1 min-h-0">
        <div id="price-card-sp" class="pc-price-card pc-price-card--syp bg-white rounded-3xl shadow-xl border flex flex-col overflow-hidden">
          <div class="pc-price-card__body flex-1 flex flex-col items-center justify-center p-5 md:p-6 bg-gradient-to-br from-primary/5 to-transparent relative min-h-[180px]">
            <span class="pc-price-card__currency absolute top-4 right-6 bg-primary text-white px-5 py-1.5 font-extrabold text-lg rounded-full">SYP</span>
            <p class="text-zinc-500 text-lg md:text-xl font-bold mb-3">سعر القطعة</p>
            <div class="pc-price-compare w-full max-w-md">
              <div id="price-sp-unit-old-wrap" class="pc-price-compare__before hidden">
                <span class="pc-price-compare__tag">قبل الحسم</span>
                <p id="price-sp-unit-old" class="pc-price-compare__old"></p>
              </div>
              <div class="pc-price-compare__after">
                <span id="price-sp-unit-tag" class="pc-price-compare__tag pc-price-compare__tag--offer hidden">سعر العرض</span>
                <div id="price-sp-unit" class="pc-price-compare__new text-primary text-5xl md:text-7xl font-extrabold tabular-nums">0</div>
              </div>
            </div>
          </div>
          <div class="pc-price-card__footer bg-zinc-900 p-5 flex justify-between items-center gap-4">
            <span class="text-zinc-400 text-lg md:text-xl font-bold">سعر الطرد</span>
            <div class="pc-price-compare pc-price-compare--compact pc-price-compare--dark text-left">
              <div id="price-sp-box-old-wrap" class="pc-price-compare__before hidden">
                <span class="pc-price-compare__tag">قبل</span>
                <p id="price-sp-box-old" class="pc-price-compare__old pc-price-compare__old--dark"></p>
              </div>
              <div class="pc-price-compare__after">
                <span id="price-sp-box-tag" class="pc-price-compare__tag pc-price-compare__tag--offer hidden">العرض</span>
                <div id="price-sp-box" class="pc-price-compare__new text-white text-3xl md:text-5xl font-extrabold tabular-nums">0</div>
              </div>
            </div>
          </div>
        </div>
        <div id="price-card-usd" class="pc-price-card pc-price-card--usd bg-white rounded-3xl shadow-xl border flex flex-col overflow-hidden">
          <div class="pc-price-card__body flex-1 flex flex-col items-center justify-center p-5 md:p-6 bg-gradient-to-br from-tertiary/5 to-transparent relative min-h-[180px]">
            <span class="pc-price-card__currency absolute top-4 right-6 bg-tertiary text-white px-5 py-1.5 font-extrabold text-lg rounded-full">USD</span>
            <p class="text-zinc-500 text-lg md:text-xl font-bold mb-3">سعر القطعة</p>
            <div class="pc-price-compare w-full max-w-md">
              <div id="price-usd-unit-old-wrap" class="pc-price-compare__before hidden">
                <span class="pc-price-compare__tag">قبل الحسم</span>
                <p id="price-usd-unit-old" class="pc-price-compare__old"></p>
              </div>
              <div class="pc-price-compare__after">
                <span id="price-usd-unit-tag" class="pc-price-compare__tag pc-price-compare__tag--offer hidden">سعر العرض</span>
                <div id="price-usd-unit" class="pc-price-compare__new text-tertiary text-5xl md:text-7xl font-extrabold tabular-nums">$0.00</div>
              </div>
            </div>
          </div>
          <div class="pc-price-card__footer bg-zinc-900 p-5 flex justify-between items-center gap-4">
            <span class="text-zinc-500 text-lg md:text-xl font-bold">سعر الطرد</span>
            <div class="pc-price-compare pc-price-compare--compact pc-price-compare--dark text-left">
              <div id="price-usd-box-old-wrap" class="pc-price-compare__before hidden">
                <span class="pc-price-compare__tag">قبل</span>
                <p id="price-usd-box-old" class="pc-price-compare__old pc-price-compare__old--dark"></p>
              </div>
              <div class="pc-price-compare__after">
                <span id="price-usd-box-tag" class="pc-price-compare__tag pc-price-compare__tag--offer hidden">العرض</span>
                <div id="price-usd-box" class="pc-price-compare__new text-white text-3xl md:text-5xl font-extrabold tabular-nums">$0.00</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div id="product-meta-row" class="grid grid-cols-2 gap-3 md:gap-6 shrink-0">
        <div class="bg-white rounded-2xl shadow-lg border flex items-center px-4 md:px-8 py-4 gap-4">
          <div><p class="text-zinc-400 text-base md:text-xl font-bold">تعبئة الطرد</p><p id="pcs-per-box" class="text-2xl md:text-4xl font-extrabold">0</p></div>
        </div>
        <div class="bg-primary rounded-2xl shadow-lg flex items-center px-4 md:px-8 py-4 gap-4 text-white">
          <div><p class="text-white/70 text-base md:text-xl font-bold">المخزون</p><p id="available-qty" class="text-2xl md:text-4xl font-extrabold">0</p></div>
        </div>
      </div>
    </main>
  </section>

  <script>
    window.PRICE_CHECKER = {
      apiUrl: <?= json_encode($self . '?action=lookup&barcode=', JSON_UNESCAPED_UNICODE) ?>,
      warmupUrl: <?= json_encode($self . '?action=warmup', JSON_UNESCAPED_UNICODE) ?>,
      promoUrl: <?= json_encode($self . '?action=slideshow', JSON_UNESCAPED_UNICODE) ?>,
      displaySeconds: <?= (int) $displaySec ?>,
      errorSeconds: <?= (int) $errorSec ?>,
      promoInterval: <?= (int) $promoInterval ?>,
      promoShowPrice: <?= $promoShowPrice ? 'true' : 'false' ?>,
      slideshowEnabled: <?= $slideshowEnabled ? 'true' : 'false' ?>
    };
  </script>
  <script src="<?= h(portal_asset_url('/assets/price-checker.js')) ?>" defer></script>
</body>
</html>
