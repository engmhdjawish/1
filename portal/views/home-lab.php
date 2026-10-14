<?php

declare(strict_types=1);

use Portal\Services\PortalSettingsService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
/** @var array<string, string>|null $companyContext */
/** @var array<string, string>|null $embeddedProductStrips */

$ads ??= [];
$embeddedProductStrips = is_array($embeddedProductStrips ?? null) ? $embeddedProductStrips : [];
$sections = is_array($sections ?? null) ? $sections : [];

$company = is_array($companyContext ?? null) ? $companyContext : PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/u', ' ', $aboutSnippet) ?? $aboutSnippet;
    $aboutLength = function_exists('mb_strlen') ? mb_strlen($aboutSnippet) : strlen($aboutSnippet);
    if ($aboutLength > 160) {
        $aboutSnippet = function_exists('mb_substr')
            ? mb_substr($aboutSnippet, 0, 160) . '...'
            : substr($aboutSnippet, 0, 160) . '...';
    }
}
$homeShowGuestRegister = portal_show_guest_auth_links();

$deptIcons = ['category', 'checkroom', 'steps', 'inventory_2', 'toys', 'sell', 'shopping_bag', 'star', 'redeem', 'dry_cleaning', 'new_releases', 'local_mall'];
$deptTones = [
    ['#fff1f2', '#9f1239'],
    ['#eff6ff', '#1d4ed8'],
    ['#f0fdf4', '#166534'],
    ['#fff7ed', '#c2410c'],
    ['#f5f3ff', '#6d28d9'],
    ['#f0fdfa', '#0f766e'],
    ['#fefce8', '#a16207'],
    ['#fdf2f8', '#9d174d'],
];

$offerSection = null;
foreach ($sections as $candidate) {
    if (!empty($candidate['is_offer_section'])) {
        $offerSection = $candidate;
        break;
    }
}
$offerHref = is_array($offerSection)
    ? '#' . (string) ($offerSection['slug'] ?? $offerSection['id'] ?? '')
    : '/store.php';

$shortcuts = [
    [
        'href' => '/store.php',
        'icon' => 'storefront',
        'title' => 'كل المواد',
        'text' => 'افتح الكتالوج وتصفّح بالفلاتر',
        'tone' => 'red',
    ],
    [
        'href' => $offerHref,
        'icon' => 'local_offer',
        'title' => is_array($offerSection) ? 'العروض الحالية' : 'أقسام المتجر',
        'text' => is_array($offerSection) ? (string) ($offerSection['title_ar'] ?? 'انتقل إلى العروض') : 'ابدأ من أقسام الصفحة',
        'tone' => 'amber',
    ],
    [
        'href' => $homeShowGuestRegister ? '/register.php' : '/track-order.php',
        'icon' => $homeShowGuestRegister ? 'person_add' : 'local_shipping',
        'title' => $homeShowGuestRegister ? 'حساب جملة' : 'تتبّع الطلب',
        'text' => $homeShowGuestRegister ? 'اطلب حساباً لرؤية أسعارك' : 'راجع حالة الطلبات السابقة',
        'tone' => 'ink',
    ],
];

$regularLayoutIndex = 0;
?>
<div class="hl">
  <aside class="hl-lab-note" aria-label="ملاحظة النسخة التجريبية">
    <span class="material-symbols-outlined" aria-hidden="true">science</span>
    <p>
      <strong>نسخة تجريبية.</strong>
      الصفحة الرئيسية المعتمدة لم تتغيّر. هذه الصفحة تجرب ترتيباً أقرب للمتاجر: إعلان كبير، اختصارات، أقسام، ثم منتجات بأشكال مختلفة.
    </p>
    <a href="/index.php">الصفحة الحالية</a>
  </aside>

  <section class="hl-hero" aria-label="بداية التسوق">
    <div class="hl-hero__main">
      <?php if ($ads !== []): ?>
        <div class="home-ad-strip" data-home-ad-carousel>
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
                sizes="(max-width: 1024px) 100vw, 900px"
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
        </div>
      <?php else: ?>
        <div class="hl-welcome">
          <p class="hl-welcome__kicker">مرحباً بكم في <?= h($siteName) ?></p>
          <h1>
            <span>أقسام أوضح</span>
            <span>ومنتجات أقرب للطلب</span>
          </h1>
          <p><?= $aboutSnippet !== '' ? h($aboutSnippet) : 'تصفّح المواد حسب القسم، وانتقل مباشرة إلى ما تحتاجه دون المرور على ترحيب طويل.' ?></p>
          <a href="/store.php" class="home-btn home-btn--light">
            <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
            تصفّح المتجر
          </a>
        </div>
      <?php endif; ?>
    </div>

    <div class="hl-hero__side">
      <?php foreach ($shortcuts as $shortcut): ?>
        <a class="hl-shortcut hl-shortcut--<?= h($shortcut['tone']) ?>" href="<?= h($shortcut['href']) ?>">
          <span class="hl-shortcut__icon material-symbols-outlined" aria-hidden="true"><?= h($shortcut['icon']) ?></span>
          <span class="hl-shortcut__copy">
            <span class="hl-shortcut__title"><?= h($shortcut['title']) ?></span>
            <span class="hl-shortcut__text"><?= h($shortcut['text']) ?></span>
          </span>
          <span class="hl-shortcut__go material-symbols-outlined" aria-hidden="true">arrow_back</span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <ul class="hl-trust" aria-label="ما يوفّره المتجر">
    <li>
      <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
      <div>
        <strong>مواد بالجملة</strong>
        <span>أقسام جاهزة حسب نوع المادة</span>
      </div>
    </li>
    <li>
      <span class="material-symbols-outlined" aria-hidden="true">photo_library</span>
      <div>
        <strong>معاينة سريعة</strong>
        <span>الصورة والسعر من البطاقة</span>
      </div>
    </li>
    <li>
      <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
      <div>
        <strong>سلة وطلب</strong>
        <span>حسب صلاحيات حسابك</span>
      </div>
    </li>
    <li>
      <span class="material-symbols-outlined" aria-hidden="true">manage_search</span>
      <div>
        <strong>فلاتر المتجر</strong>
        <span>من القسم إلى القائمة الكاملة</span>
      </div>
    </li>
  </ul>

  <?php if ($sections !== []): ?>
    <section class="hl-depts" aria-labelledby="hl-depts-title">
      <header class="hl-depts__head">
        <div>
          <h2 id="hl-depts-title">تسوّق حسب القسم</h2>
          <p>اختصارات لأقسام الصفحة نفسها. اختر قسماً للوصول إلى منتجاته.</p>
        </div>
        <a href="/store.php">كل المواد</a>
      </header>
      <div class="hl-depts__grid">
        <?php foreach ($sections as $deptIndex => $section): ?>
          <?php
            $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
            $title = trim((string) ($section['title_ar'] ?? ''));
            if ($title === '') {
                $title = 'قسم';
            }
            $subtitle = trim((string) ($section['subtitle_ar'] ?? ''));
            if ($subtitle === '') {
                $subtitle = !empty($section['is_offer_section']) ? 'عرض خاص' : 'عرض المنتجات';
            }
            $tone = $deptTones[$deptIndex % count($deptTones)];
            $icon = $deptIcons[$deptIndex % count($deptIcons)];
            $banner = trim((string) ($section['banner_image_url'] ?? ''));
          ?>
          <a class="hl-dept<?= $banner !== '' ? ' hl-dept--photo' : '' ?>" href="#<?= h($sectionId) ?>">
            <?php if ($banner !== ''): ?>
              <img src="<?= h(portal_site_media_display_url($banner, 640)) ?>" alt="" loading="lazy" decoding="async">
              <span class="hl-dept__shade" aria-hidden="true"></span>
            <?php else: ?>
              <span class="hl-dept__mark" style="background: <?= h($tone[0]) ?>; color: <?= h($tone[1]) ?>">
                <span class="material-symbols-outlined" aria-hidden="true"><?= h($icon) ?></span>
              </span>
            <?php endif; ?>
            <span class="hl-dept__copy">
              <?php if (!empty($section['is_offer_section'])): ?>
                <span class="hl-dept__badge">عرض</span>
              <?php endif; ?>
              <span class="hl-dept__title"><?= h($title) ?></span>
              <span class="hl-dept__sub"><?= h($subtitle) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <nav class="hl-jump home-section-nav--sticky" aria-label="الانتقال بين الأقسام">
      <?php foreach ($sections as $section): ?>
        <?php
          $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
          $title = trim((string) ($section['title_ar'] ?? ''));
          if ($title === '') {
              $title = 'قسم';
          }
        ?>
        <a class="home-section-nav__link" href="#<?= h($sectionId) ?>"><?= h($title) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="hl-catalog">
      <?php foreach ($sections as $section): ?>
        <?php
          $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
          $embeddedStripHtml = trim((string) ($embeddedProductStrips[$sectionId] ?? ''));
          $isOfferSection = !empty($section['is_offer_section']);
          if ($isOfferSection) {
              $layout = 'deal';
          } else {
              $layout = ['rail', 'feature', 'grid'][$regularLayoutIndex % 3];
              $regularLayoutIndex++;
          }
          $title = trim((string) ($section['title_ar'] ?? ''));
          $banner = trim((string) ($section['banner_image_url'] ?? ''));
        ?>
        <section class="hl-block hl-block--<?= h($layout) ?>" id="<?= h($sectionId) ?>">
          <?php if ($layout === 'feature'): ?>
            <div class="hl-feature">
              <aside class="hl-feature__intro<?= $banner !== '' ? ' hl-feature__intro--photo' : '' ?>">
                <?php if ($banner !== ''): ?>
                  <img src="<?= h(portal_site_media_display_url($banner, 960)) ?>" alt="" loading="lazy" decoding="async">
                <?php endif; ?>
                <div class="hl-feature__copy">
                  <?php if ($isOfferSection): ?>
                    <span class="hl-block__badge">عرض خاص</span>
                  <?php else: ?>
                    <span class="hl-block__badge hl-block__badge--quiet">قسم مختار</span>
                  <?php endif; ?>
                  <h2><?= h($title) ?></h2>
                  <?php if (!empty($section['subtitle_ar'])): ?>
                    <p><?= h((string) $section['subtitle_ar']) ?></p>
                  <?php endif; ?>
                  <a href="<?= h(home_section_store_url($section)) ?>" class="hl-more hl-more--light">
                    عرض الكل
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                  </a>
                </div>
              </aside>
              <div class="hl-feature__products">
                <div class="hl-rail" data-hl-rail>
                  <button type="button" class="hl-rail__nav hl-rail__nav--prev" data-hl-prev aria-label="المنتجات السابقة">
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                  </button>
                  <div class="hl-rail__scroller">
                    <?php require __DIR__ . '/partials/home-lab-products.php'; ?>
                  </div>
                  <button type="button" class="hl-rail__nav hl-rail__nav--next" data-hl-next aria-label="المنتجات التالية">
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                  </button>
                </div>
              </div>
            </div>
          <?php else: ?>
            <?php if ($banner !== ''): ?>
              <div class="hl-block__banner">
                <img src="<?= h(portal_site_media_display_url($banner, 1280)) ?>" alt="" loading="lazy" decoding="async">
              </div>
            <?php endif; ?>
            <header class="hl-block__head">
              <div>
                <?php if ($isOfferSection): ?>
                  <span class="hl-block__badge">عرض خاص</span>
                <?php endif; ?>
                <h2><?= h($title) ?></h2>
                <?php if (!empty($section['subtitle_ar'])): ?>
                  <p><?= h((string) $section['subtitle_ar']) ?></p>
                <?php endif; ?>
              </div>
              <a href="<?= h(home_section_store_url($section)) ?>" class="hl-more">
                عرض الكل
                <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
              </a>
            </header>
            <?php if ($layout === 'rail'): ?>
              <div class="hl-rail" data-hl-rail>
                <button type="button" class="hl-rail__nav hl-rail__nav--prev" data-hl-prev aria-label="المنتجات السابقة">
                  <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                </button>
                <div class="hl-rail__scroller">
                  <?php require __DIR__ . '/partials/home-lab-products.php'; ?>
                </div>
                <button type="button" class="hl-rail__nav hl-rail__nav--next" data-hl-next aria-label="المنتجات التالية">
                  <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                </button>
              </div>
            <?php else: ?>
              <div class="hl-grid">
                <?php require __DIR__ . '/partials/home-lab-products.php'; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <section class="hl-block hl-block--empty">
      <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
      <p>لا توجد أقسام نشطة حالياً.</p>
      <a href="/store.php" class="home-btn home-btn--primary">الذهاب للمتجر</a>
    </section>
  <?php endif; ?>

  <section class="hl-services" aria-label="متابعة">
    <a href="/store.php">
      <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
      <strong>المتجر الكامل</strong>
      <span>كل المواد والفلاتر في صفحة واحدة</span>
    </a>
    <a href="/about.php">
      <span class="material-symbols-outlined" aria-hidden="true">groups</span>
      <strong>من نحن</strong>
      <span>نبذة عن <?= h($siteName) ?></span>
    </a>
    <a href="/track-order.php">
      <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
      <strong>تتبّع الطلب</strong>
      <span>راجع حالة طلب سابق</span>
    </a>
  </section>
</div>
