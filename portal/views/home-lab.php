<?php

declare(strict_types=1);

use Portal\Services\PortalSettingsService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
/** @var array<string, string>|null $companyContext */
/** @var array<string, string>|null $embeddedProductStrips */
/** @var array{show_price: bool, show_quantity: bool, allow_cart: bool, allow_order: bool, show_images: bool, price_mode: string}|null $storeCatalogDisplay */

$ads ??= [];
$embeddedProductStrips = is_array($embeddedProductStrips ?? null) ? $embeddedProductStrips : [];
$sections = is_array($sections ?? null) ? $sections : [];
$storeCatalogDisplay = is_array($storeCatalogDisplay ?? null) ? $storeCatalogDisplay : [];

$company = is_array($companyContext ?? null) ? $companyContext : PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/', ' ', $aboutSnippet) ?? $aboutSnippet;
    if (strlen($aboutSnippet) > 200) {
        $aboutSnippet = substr($aboutSnippet, 0, 200) . '...';
    }
}
$homeShowGuestRegister = portal_show_guest_auth_links();

$sectionLabel = static function (array $section): string {
    $title = trim((string) ($section['title_ar'] ?? ''));

    return $title !== '' ? $title : 'قسم';
};
$sectionKey = static function (array $section): string {
    return (string) ($section['slug'] ?? $section['id'] ?? '');
};

$discover = [];
foreach ($sections as $section) {
    $label = $sectionLabel($section);
    $discover[] = [
        'kind' => 'section',
        'label' => $label,
        'hint' => !empty($section['is_offer_section']) ? 'عرض' : 'قسم',
        'href' => home_section_store_url($section),
    ];
}
$discoverJson = json_encode($discover, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="home-page hl">
  <aside class="hl-lab-note" aria-label="ملاحظة النسخة التجريبية">
    <p><strong>نسخة تجريبية.</strong> الصفحة الحالية لم تتغيّر، وهذه التجربة تستخدم هويتها مع بحث يتوقع الأقسام والمواد.</p>
    <a href="/index.php">الصفحة الحالية</a>
  </aside>

  <section class="home-hero home-hero--premium" aria-labelledby="hl-search-title">
    <div class="hl-hero-clip" aria-hidden="true">
      <div class="home-hero__glow home-hero__glow--left"></div>
      <div class="home-hero__glow home-hero__glow--right"></div>
    </div>
    <div class="home-hero__inner">
      <div class="home-hero__grid">
        <div class="home-hero__content">
          <p class="home-hero__kicker">
            <span class="home-hero__kicker-dot" aria-hidden="true"></span>
            مرحباً بكم في <?= h($siteName) ?>
            <?php if ($sections !== []): ?>
              <span class="hl-hero-count"><?= count($sections) ?> أقسام</span>
            <?php endif; ?>
          </p>
          <h1 id="hl-search-title" class="home-hero__title">
            <span class="home-hero__title-line">اعثر على المادة</span>
            <span class="home-hero__title-line">ثم أكمل طلب الجملة</span>
          </h1>
          <p class="home-hero__lead">
            <?= $aboutSnippet !== '' ? h($aboutSnippet) : 'تصفّح أحدث المواد بأسعار واضحة، أضف للسلة، وتابع طلبك خطوة بخطوة.' ?>
          </p>
          <p class="hl-search-hint">ابحث باسم المادة أو القسم أو القياس. الحقل يخمن الباقي قبل أن تكمل.</p>

          <form class="hl-search" action="/store.php" method="get" role="search" data-hl-search>
            <div class="hl-search__box">
              <span class="material-symbols-outlined hl-search__icon" aria-hidden="true">search</span>
              <div class="hl-search__field">
                <span class="hl-search__ghost" data-hl-ghost hidden></span>
                <input
                  id="hl-search-input"
                  type="search"
                  name="q"
                  placeholder="اسم مادة، قسم، أو قياس"
                  autocomplete="off"
                  autocorrect="off"
                  autocapitalize="off"
                  spellcheck="false"
                  enterkeyhint="search"
                  aria-autocomplete="list"
                  aria-controls="hl-suggest"
                  aria-expanded="false"
                  data-hl-search-input
                >
              </div>
              <button type="button" class="hl-search__accept" data-hl-accept hidden>
                <kbd>Tab</kbd>
                <span>إكمال</span>
              </button>
              <button type="submit" class="hl-search__submit">بحث</button>
            </div>
            <div id="hl-suggest" class="hl-suggest" role="listbox" hidden data-hl-suggest></div>
            <script type="application/json" id="hl-search-dictionary"><?= $discoverJson !== false ? $discoverJson : '[]' ?></script>
          </form>

          <?php if ($discover !== []): ?>
            <div class="hl-prompts" aria-label="اقتراحات للبدء">
              <span>جرّب</span>
              <?php foreach (array_slice($discover, 0, 6) as $item): ?>
                <button type="button" data-hl-prompt="<?= h($item['label']) ?>"><?= h($item['label']) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="home-hero__actions">
            <a href="/store.php" class="home-btn home-btn--light">
              <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
              تصفّح المتجر
            </a>
            <?php if ($homeShowGuestRegister): ?>
              <a href="/register.php" class="home-btn home-btn--ghost">
                <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
                حساب جديد
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div class="home-hero__visual" aria-hidden="true">
          <div class="home-hero__orbits">
            <svg class="home-hero__orbits-svg" viewBox="0 0 200 200" focusable="false">
              <g class="home-hero__orbits-layer home-hero__orbits-layer--a">
                <circle cx="100" cy="100" r="88" fill="none" stroke="currentColor" stroke-width="1.1" stroke-dasharray="175 378" stroke-linecap="round" opacity="0.22"></circle>
                <circle cx="100" cy="12" r="3.6" fill="currentColor" opacity="0.5"></circle>
              </g>
              <g class="home-hero__orbits-layer home-hero__orbits-layer--b">
                <circle cx="100" cy="100" r="62" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="120 270" stroke-linecap="round" opacity="0.18"></circle>
                <circle cx="162" cy="100" r="3" fill="currentColor" opacity="0.42"></circle>
              </g>
              <g class="home-hero__orbits-layer home-hero__orbits-layer--c">
                <circle cx="100" cy="100" r="38" fill="none" stroke="currentColor" stroke-width="0.9" stroke-dasharray="78 162" stroke-linecap="round" opacity="0.14"></circle>
                <circle cx="100" cy="138" r="2.6" fill="currentColor" opacity="0.36"></circle>
              </g>
            </svg>
            <span class="home-hero__orbit-core"></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($ads !== []): ?>
    <section class="home-ad-strip" aria-label="إعلانات" data-home-ad-carousel>
      <div class="home-ad-frame">
        <?php foreach ($ads as $i => $ad): ?>
          <?php
            $adAlt = trim((string) ($ad['title_ar'] ?? ''));
            if ($adAlt === '') {
                $adAlt = trim((string) ($ad['file_name'] ?? 'إعلان'));
            }
            $adSrc = portal_site_media_display_url((string) ($ad['url'] ?? ''), 1280);
          ?>
          <img
            src="<?= h($adSrc) ?>"
            alt="<?= h($adAlt) ?>"
            class="home-ad-slide<?= $i === 0 ? ' is-active' : '' ?>"
            loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
            decoding="async"
            <?= $i === 0 ? 'fetchpriority="high"' : '' ?>
            sizes="(max-width: 768px) 100vw, 1280px"
            aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"
            data-ad-index="<?= (int) $i ?>"
          >
        <?php endforeach; ?>
        <?php if (count($ads) > 1): ?>
          <button type="button" class="home-ad-nav home-ad-nav--prev" data-ad-prev aria-label="الإعلان السابق">
            <span class="material-symbols-outlined">chevron_right</span>
          </button>
          <button type="button" class="home-ad-nav home-ad-nav--next" data-ad-next aria-label="الإعلان التالي">
            <span class="material-symbols-outlined">chevron_left</span>
          </button>
        <?php endif; ?>
      </div>
      <?php if (count($ads) > 1): ?>
        <div class="home-ad-dots" role="tablist" aria-label="اختيار إعلان">
          <?php foreach ($ads as $i => $ad): ?>
            <button
              type="button"
              class="home-ad-dot<?= $i === 0 ? ' is-active' : '' ?>"
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
    <nav class="home-section-nav--sticky" aria-label="أقسام الصفحة">
      <?php foreach ($sections as $section): ?>
        <a class="home-section-nav__link" href="#<?= h($sectionKey($section)) ?>"><?= h($sectionLabel($section)) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="home-sections">
      <?php foreach ($sections as $section): ?>
        <?php
          $products = is_array($section['products'] ?? null) ? $section['products'] : [];
          $sectionId = $sectionKey($section);
          $embeddedStripHtml = trim((string) ($embeddedProductStrips[$sectionId] ?? ''));
          $isOfferSection = !empty($section['is_offer_section']);
        ?>
        <section class="home-section<?= $isOfferSection ? ' home-section--offer hl-section--feature' : '' ?>" id="<?= h($sectionId) ?>">
          <?php if (!empty($section['banner_image_url'])): ?>
            <div class="home-section__banner">
              <img
                src="<?= h(portal_site_media_display_url((string) $section['banner_image_url'], 1280)) ?>"
                alt=""
                loading="lazy"
                decoding="async"
                sizes="(max-width: 768px) 100vw, 1280px"
              >
            </div>
          <?php endif; ?>
          <div class="home-section__body">
            <header class="home-section__header">
              <div class="home-section__title-wrap">
                <?php if ($isOfferSection): ?>
                  <span class="home-section__badge">عرض خاص</span>
                <?php endif; ?>
                <h2 class="home-section__title"><?= h($sectionLabel($section)) ?></h2>
                <?php if (!empty($section['subtitle_ar'])): ?>
                  <p class="home-section__subtitle"><?= h((string) $section['subtitle_ar']) ?></p>
                <?php endif; ?>
                <?php if ($isOfferSection): ?>
                  <p class="hl-section-note">هذا القسم في المقدمة لأن فيه عرضاً. ابحث داخله أو افتحه في المتجر للمقاسات والشركات.</p>
                <?php endif; ?>
              </div>
              <div class="hl-section-actions">
                <button type="button" class="home-section__more" data-hl-prompt="<?= h($sectionLabel($section)) ?>">
                  ابحث هنا
                  <span class="material-symbols-outlined" aria-hidden="true">search</span>
                </button>
                <a href="<?= h(home_section_store_url($section)) ?>" class="home-section__more">
                  عرض المزيد
                  <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                </a>
              </div>
            </header>
            <?php if ($embeddedStripHtml !== ''): ?>
              <?= $embeddedStripHtml ?>
            <?php elseif ($products === []): ?>
              <div class="home-section__empty">لا توجد منتجات في هذا القسم حالياً.</div>
            <?php else: ?>
              <?php require __DIR__ . '/partials/home-section-product-strip.php'; ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <section class="home-section home-section--empty">
      <span class="material-symbols-outlined text-4xl text-gray-300" aria-hidden="true">storefront</span>
      <p>لا توجد أقسام نشطة حالياً.</p>
      <a href="/store.php" class="home-btn home-btn--primary">الذهاب للمتجر</a>
    </section>
  <?php endif; ?>

  <section class="home-cta" aria-label="متابعة التسوّق">
    <div class="home-cta__content">
      <h2>لم تظهر المادة التي تريدها؟</h2>
      <p>ارجع للبحث واكتب جزءاً من الاسم، أو افتح المتجر لتصفية القياس والشركة والمنشأ.</p>
    </div>
    <div class="home-cta__actions">
      <button type="button" class="home-btn home-btn--primary" data-hl-focus-search>
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        ارجع للبحث
      </button>
      <a href="/store.php" class="home-btn home-btn--ghost-dark">
        <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
        فتح المتجر
      </a>
      <a href="/about.php" class="home-btn home-btn--ghost-dark">
        <span class="material-symbols-outlined" aria-hidden="true">groups</span>
        من نحن
      </a>
    </div>
  </section>
</div>
