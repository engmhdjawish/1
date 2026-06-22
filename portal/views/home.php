<?php

declare(strict_types=1);

use Portal\Services\PortalSettingsService;
use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
$ads ??= [];

$company = PortalSettingsService::companySettings();
$companyLogoUrl = PortalSettingsService::companyLogoUrl($company);
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/', ' ', $aboutSnippet) ?? $aboutSnippet;
    if (strlen($aboutSnippet) > 180) {
        $aboutSnippet = substr($aboutSnippet, 0, 180) . '...';
    }
}
?>
<link href="/css/home-page.css" rel="stylesheet">

<div class="home-page space-y-10 md:space-y-12">
  <section class="home-hero home-hero-pattern relative overflow-hidden rounded-[2rem] shadow-xl">
    <div class="absolute -left-12 top-0 h-48 w-48 rounded-full bg-white/10 blur-3xl"></div>
    <div class="absolute -right-8 bottom-0 h-40 w-40 rounded-full bg-black/10 blur-2xl"></div>
    <div class="relative px-6 py-10 md:px-10 md:py-14 grid gap-8 lg:grid-cols-[1.2fr_0.8fr] items-center">
      <div>
        <p class="home-hero-kicker text-sm font-bold mb-3 opacity-90">مرحباً بكم في <?= h($siteName) ?></p>
        <h1 class="text-3xl md:text-5xl font-extrabold leading-tight">تسوّق بذكاء<br class="hidden sm:block"> واطلب بسهولة</h1>
        <p class="mt-4 text-base md:text-lg leading-8 opacity-95 max-w-2xl">
          <?= $aboutSnippet !== '' ? h($aboutSnippet) : 'تصفّح أحدث المواد، اطّلع على الأسعار حسب حسابك، واطلب مباشرة من المتجر.' ?>
        </p>
        <div class="flex flex-wrap gap-3 mt-7">
          <a href="/store.php" class="h-12 inline-flex items-center gap-2 rounded-xl bg-white text-primary px-6 font-extrabold shadow-md hover:brightness-105 transition">
            <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
            تصفّح المتجر
          </a>
          <a href="/about.php" class="h-12 inline-flex items-center gap-2 rounded-xl border border-white/45 px-6 font-bold hover:bg-white/10 transition">
            <span class="material-symbols-outlined" aria-hidden="true">info</span>
            من نحن
          </a>
        </div>
      </div>

      <div class="rounded-2xl border border-white/20 bg-white/10 backdrop-blur-md p-5 md:p-6">
        <?php if (!empty($companyLogoUrl)): ?>
          <?php
            $siteLogoVariant = 'hero-home';
            $siteLogoAlt = $siteName;
            require __DIR__ . '/partials/site-logo.php';
          ?>
        <?php endif; ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-3">
          <article class="home-feature-card rounded-xl px-4 py-4">
            <span class="material-symbols-outlined text-2xl" aria-hidden="true">inventory_2</span>
            <p class="font-bold mt-2">تشكيلة واسعة</p>
            <p class="text-sm opacity-85 mt-1">مواد متنوعة مع صور وأسعار</p>
          </article>
          <article class="home-feature-card rounded-xl px-4 py-4">
            <span class="material-symbols-outlined text-2xl" aria-hidden="true">verified_user</span>
            <p class="font-bold mt-2">حسابك يحدد العرض</p>
            <p class="text-sm opacity-85 mt-1">أسعار وصلاحيات حسب سياستك</p>
          </article>
          <article class="home-feature-card rounded-xl px-4 py-4 sm:col-span-3 lg:col-span-1">
            <span class="material-symbols-outlined text-2xl" aria-hidden="true">shopping_cart</span>
            <p class="font-bold mt-2">طلب سريع</p>
            <p class="text-sm opacity-85 mt-1">سلة وطلب من المتجر أو روابط المشاركة</p>
          </article>
        </div>
      </div>
    </div>
  </section>

  <?php if ($ads !== []): ?>
    <section class="home-ad-strip" aria-label="إعلانات" data-home-ad-carousel>
      <div class="relative rounded-[1.5rem] overflow-hidden border border-gray-200 shadow-md bg-gray-100 aspect-[21/9] md:aspect-[3/1] max-h-56 md:max-h-72">
        <?php foreach ($ads as $i => $ad): ?>
          <?php
            $adAlt = trim((string) ($ad['title_ar'] ?? ''));
            if ($adAlt === '') {
                $adAlt = trim((string) ($ad['file_name'] ?? 'إعلان'));
            }
          ?>
          <img
            src="<?= h((string) ($ad['url'] ?? '')) ?>"
            alt="<?= h($adAlt) ?>"
            class="home-ad-slide absolute inset-0 w-full h-full object-cover transition-opacity duration-700 ease-in-out <?= $i === 0 ? 'opacity-100 z-10' : 'opacity-0 z-0' ?>"
            loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
            decoding="async"
            data-ad-index="<?= (int) $i ?>"
          >
        <?php endforeach; ?>
      </div>
      <?php if (count($ads) > 1): ?>
        <div class="flex justify-center gap-2 mt-3" role="tablist" aria-label="اختيار إعلان">
          <?php foreach ($ads as $i => $ad): ?>
            <button
              type="button"
              class="home-ad-dot h-2 rounded-full transition-all duration-300 <?= $i === 0 ? 'w-6 bg-primary' : 'w-2 bg-gray-300' ?>"
              aria-label="إعلان <?= (int) $i + 1 ?>"
              aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
              data-ad-dot="<?= (int) $i ?>"
            ></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($sections !== []): ?>
    <nav class="home-section-nav flex gap-2 overflow-x-auto pb-1 -mx-1 px-1" aria-label="أقسام الرئيسية">
      <?php foreach ($sections as $section): ?>
        <?php $anchorId = (string) ($section['slug'] ?? $section['id'] ?? ''); ?>
        <?php if ($anchorId === '') continue; ?>
        <a
          href="#<?= h($anchorId) ?>"
          class="shrink-0 h-10 inline-flex items-center rounded-full border border-gray-200 bg-white px-4 text-sm font-bold text-gray-700 hover:border-primary hover:text-primary transition"
        >
          <?= h((string) ($section['title_ar'] ?? 'قسم')) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php foreach ($sections as $sectionIndex => $section): ?>
    <?php
      $products = is_array($section['products'] ?? null) ? $section['products'] : [];
      $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
      $displayOptions = is_array($section['display_options'] ?? null) ? $section['display_options'] : [];
      $showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
      $priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
      $showPriceSyp = $priceMode === 'both' || $priceMode === 'syp';
      $showPriceUsd = $priceMode === 'both' || $priceMode === 'usd';
      $showAnyPrice = $showPriceSyp || $showPriceUsd;
      $isOfferSection = !empty($section['is_offer_section']);
      $isAlt = $sectionIndex % 2 === 1;
    ?>
    <section
      class="home-section rounded-[1.75rem] border border-gray-200 shadow-sm overflow-hidden <?= $isAlt ? 'home-section--alt' : 'bg-white' ?> <?= $isOfferSection ? 'ring-1 ring-primary/10' : '' ?>"
      id="<?= h($sectionId) ?>"
    >
      <?php if (!empty($section['banner_image_url'])): ?>
        <div class="home-section-banner">
          <img src="<?= h((string) $section['banner_image_url']) ?>" alt="" class="w-full h-40 md:h-48 object-cover" loading="lazy">
        </div>
      <?php endif; ?>

      <div class="p-5 md:p-7">
        <div class="flex flex-wrap items-end justify-between gap-4 mb-5">
          <div class="flex items-start gap-3 min-w-0">
            <span class="home-section-header-accent shrink-0"></span>
            <div>
              <?php if ($isOfferSection): ?>
                <span class="inline-flex mb-2 rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-extrabold text-primary">عرض خاص</span>
              <?php endif; ?>
              <h2 class="text-xl md:text-2xl font-extrabold text-gray-900"><?= h((string) ($section['title_ar'] ?? '')) ?></h2>
              <?php if (!empty($section['subtitle_ar'])): ?>
                <p class="text-sm text-gray-500 mt-1 leading-relaxed"><?= h((string) $section['subtitle_ar']) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= h(home_section_store_url($section)) ?>" class="h-10 inline-flex items-center gap-1 rounded-xl border border-gray-200 bg-white px-4 text-sm font-bold text-primary hover:border-primary hover:bg-primary/5 transition">
            عرض المزيد
            <span class="material-symbols-outlined text-base" aria-hidden="true">chevron_left</span>
          </a>
        </div>

        <?php if ($products === []): ?>
          <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center">
            <p class="text-gray-500 text-sm">لا توجد منتجات في هذا القسم حالياً.</p>
          </div>
        <?php else: ?>
          <?php
            $sectionGuids = array_values(array_filter(array_map(
                static fn ($row): string => is_array($row) ? material_guid($row) : '',
                $products
            ), static fn (string $g): bool => $g !== ''));
            $sectionGuidsJson = json_encode($sectionGuids, JSON_UNESCAPED_UNICODE);
          ?>
          <div class="home-strip flex gap-4 overflow-x-auto pb-2 snap-x snap-mandatory scroll-smooth -mx-1 px-1">
            <?php foreach ($products as $item): ?>
              <?php
                if (!is_array($item)) continue;
                $guid = material_guid($item);
                $sectionSlug = trim((string) ($section['slug'] ?? ''));
                $contextOffer = $isOfferSection && $sectionSlug !== ''
                    ? SpecialOfferService::activeOfferBySlug($sectionSlug)
                    : null;
                if ($guid !== '') {
                  $overlay = SpecialOfferService::pricingOverlay($item, $contextOffer);
                  if (!empty($overlay['has_offer'])) {
                    $item = array_merge($item, $overlay);
                  }
                }
                $cardUrl = $guid !== ''
                    ? product_url(
                        $guid,
                        home_section_return_url($section),
                        $isOfferSection && $sectionSlug !== '' ? $sectionSlug : null
                    )
                    : home_section_store_url($section);
                $imageGuid = material_image_guid($item);
              ?>
              <a
                href="<?= h($cardUrl) ?>"
                class="home-product-card home-strip-card snap-start shrink-0 w-[15.5rem] border border-gray-200 rounded-2xl bg-white overflow-hidden flex flex-col no-underline text-inherit"
                <?php if ($guid !== ''): ?>
                  data-quick-view="1"
                  data-product-guid="<?= h($guid) ?>"
                  data-offer-slug="<?= h($isOfferSection ? $sectionSlug : '') ?>"
                  data-quick-view-guids="<?= h((string) $sectionGuidsJson) ?>"
                  data-return-url="<?= h(home_section_return_url($section)) ?>"
                <?php endif; ?>
              >
                <?php if ($showImages): ?>
                  <?php
                    $material = $item;
                    $variant = 'strip';
                    require __DIR__ . '/partials/material-image-frame.php';
                  ?>
                <?php endif; ?>
                <div class="p-3.5 flex flex-col flex-1">
                  <div class="font-bold text-sm line-clamp-2 min-h-[2.5rem] text-gray-900"><?= h((string) ($item['name'] ?? '-')) ?></div>
                  <?php if ($showAnyPrice): ?>
                    <?php
                      $showPriceSypBlock = $showPriceSyp;
                      $showPriceUsdBlock = $showPriceUsd;
                      require __DIR__ . '/partials/offer-price-block.php';
                    ?>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($sections === []): ?>
    <section class="rounded-[1.75rem] border border-dashed border-gray-300 bg-white p-10 text-center">
      <span class="material-symbols-outlined text-4xl text-gray-300" aria-hidden="true">storefront</span>
      <p class="text-gray-500 mt-3">لا توجد أقسام نشطة حالياً.</p>
      <a href="/store.php" class="inline-flex mt-5 h-11 items-center rounded-xl bg-primary text-white px-6 font-bold hover:brightness-110 transition">الذهاب للمتجر</a>
    </section>
  <?php endif; ?>

  <section class="home-cta-band rounded-[1.75rem] px-6 py-8 md:px-10 md:py-10 text-white flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
      <h2 class="text-xl md:text-2xl font-extrabold">جاهز للتسوّق؟</h2>
      <p class="text-sm md:text-base text-gray-300 mt-2 max-w-xl">استكشف المتجر كاملاً أو سجّل حسابك للحصول على أسعار وصلاحيات مخصصة.</p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="/store.php" class="h-11 inline-flex items-center gap-2 rounded-xl bg-primary px-5 font-extrabold hover:brightness-110 transition">
        <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
        فتح المتجر
      </a>
      <a href="/register.php" class="h-11 inline-flex items-center gap-2 rounded-xl border border-white/25 px-5 font-bold hover:bg-white/10 transition">
        <span class="material-symbols-outlined text-base" aria-hidden="true">person_add</span>
        تسجيل عميل
      </a>
    </div>
  </section>
</div>

<?php if (count($ads) > 1): ?>
<script>
  (() => {
    const root = document.querySelector('[data-home-ad-carousel]');
    if (!root) return;
    const slides = Array.from(root.querySelectorAll('.home-ad-slide'));
    if (slides.length <= 1) return;
    const dots = Array.from(root.querySelectorAll('[data-ad-dot]'));
    let index = 0;
    let timer = null;
    const intervalMs = 5000;

    const show = (next) => {
      index = (next + slides.length) % slides.length;
      slides.forEach((slide, i) => {
        const active = i === index;
        slide.classList.toggle('opacity-100', active);
        slide.classList.toggle('z-10', active);
        slide.classList.toggle('opacity-0', !active);
        slide.classList.toggle('z-0', !active);
      });
      dots.forEach((dot, i) => {
        const active = i === index;
        dot.classList.toggle('bg-primary', active);
        dot.classList.toggle('w-6', active);
        dot.classList.toggle('bg-gray-300', !active);
        dot.classList.toggle('w-2', !active);
        dot.setAttribute('aria-selected', active ? 'true' : 'false');
      });
    };

    const stop = () => {
      if (timer !== null) {
        clearInterval(timer);
        timer = null;
      }
    };

    const start = () => {
      stop();
      timer = setInterval(() => show(index + 1), intervalMs);
    };

    dots.forEach((dot) => {
      dot.addEventListener('click', () => {
        show(Number.parseInt(dot.getAttribute('data-ad-dot') || '0', 10));
        start();
      });
    });

    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', (event) => {
      if (!root.contains(event.relatedTarget)) start();
    });

    start();
  })();
</script>
<?php endif; ?>
