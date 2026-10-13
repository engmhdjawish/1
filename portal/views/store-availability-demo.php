<?php

declare(strict_types=1);

/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $displayOptions */

$catalog = is_array($catalog ?? null) ? $catalog : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
$view = trim((string) ($catalog['demo_view'] ?? 'all'));
if (!in_array($view, ['all', 'available', 'unavailable'], true)) {
    $view = 'all';
}
$stats = is_array($catalog['demo_stats'] ?? null) ? $catalog['demo_stats'] : [];
$availableOnPage = (int) ($stats['available_on_page'] ?? 0);
$unavailableOnPage = (int) ($stats['unavailable_on_page'] ?? 0);
$search = trim((string) ($catalog['filters']['search'] ?? ''));
$page = max(1, (int) ($catalog['page'] ?? 1));
$totalPages = max(1, (int) ($catalog['totalPages'] ?? 1));

$buildDemoUrl = static function (array $overrides = []) use ($view, $search, $page): string {
    $params = array_filter([
        'view' => $overrides['view'] ?? $view,
        'q' => $overrides['q'] ?? $search,
        'page' => $overrides['page'] ?? $page,
    ], static fn ($v): bool => $v !== null && $v !== '' && $v !== 0);

    return store_availability_demo_url($params);
};

$viewLabels = [
    'all' => 'الكل (متاح أولاً)',
    'available' => 'متوفر للطلب',
    'unavailable' => 'غير متوفر',
];
?>
<div class="store-availability-demo">
  <header class="store-availability-demo__hero">
    <div class="store-availability-demo__hero-inner">
      <p class="store-availability-demo__eyebrow">
        <span class="material-symbols-outlined text-base" aria-hidden="true">science</span>
        صفحة معاينة — ليست المتجر الرسمي بعد
      </p>
      <h1 class="store-availability-demo__title">كيف نعرض المواد بدون رصيد ونُقدّم المتاح؟</h1>
      <p class="store-availability-demo__lead">
        هذا نموذج للاقتراح الهجين: نفس الكatalog مع <strong>ترتيب المتاح أولاً</strong>، تمييز بصري للنافد، وزر «تفاصيل» بدل السلة.
        غيّر وضع العرض أدناه وقارن مع <a href="/store.php">المتجر الحالي</a>.
      </p>
      <div class="store-availability-demo__legend" aria-label="دليل الألوان">
        <span class="store-availability-demo__legend-item store-availability-demo__legend-item--ok">متاح</span>
        <span class="store-availability-demo__legend-item store-availability-demo__legend-item--low">آخر كمية</span>
        <span class="store-availability-demo__legend-item store-availability-demo__legend-item--out">غير متوفر</span>
      </div>
    </div>
  </header>

  <section class="store-availability-demo__home-mock" aria-label="مقترح للصفحة الرئيسية">
    <div class="store-availability-demo__home-mock-inner">
      <h2 class="store-availability-demo__section-title">مقترح الرئيسية (مختصر)</h2>
      <p class="store-availability-demo__section-note">شرائط المنتجات تبقى «متوفر فقط»؛ رابط اختياري لقائمة النافد في الأسفل.</p>
      <div class="store-availability-demo__home-strip">
        <div class="store-availability-demo__home-strip-head">
          <span>وصل حديثاً — متاح للطلب</span>
          <span class="store-availability-demo__pill store-availability-demo__pill--ok">متوفر فقط</span>
        </div>
        <div class="store-availability-demo__home-strip-body">
          <?php
            $homeStrip = array_values(array_filter($products, static function ($item): bool {
                return is_array($item) && packages_available_display($item) > 0;
            }));
            $homeStrip = array_slice($homeStrip, 0, 4);
          ?>
          <?php if ($homeStrip === []): ?>
            <p class="store-availability-demo__home-empty">لا توجد مواد متاحة في هذه الصفحة للعرض — جرّب وضع «الكل» أو انتقل لصفحة أخرى.</p>
          <?php else: ?>
            <div class="store-product-grid store-product-grid--compact">
              <?php foreach ($homeStrip as $item): ?>
                <?php
                  $availabilityPreview = false;
                  $useImagePreview = false;
                  $useQuickView = false;
                  $linkToDetail = true;
                  require __DIR__ . '/partials/product-card.php';
                ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <a class="store-availability-demo__home-link" href="<?= h($buildDemoUrl(['view' => 'unavailable', 'page' => 1])) ?>">
          مواد غير متوفرة حالياً — للاطلاع
          <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_back</span>
        </a>
      </div>
    </div>
  </section>

  <section class="store-availability-demo__controls" aria-label="تحكم المعاينة">
    <form method="get" class="store-availability-demo__toolbar">
      <div class="store-availability-demo__view-tabs" role="tablist" aria-label="وضع العرض">
        <?php foreach (['all' => $viewLabels['all'], 'available' => $viewLabels['available'], 'unavailable' => $viewLabels['unavailable']] as $value => $label): ?>
          <?php $active = $view === $value; ?>
          <button
            type="submit"
            name="view"
            value="<?= h($value) ?>"
            class="store-availability-demo__view-tab<?= $active ? ' is-active' : '' ?>"
            <?= $active ? 'aria-current="page"' : '' ?>
          ><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>
      <label class="store-availability-demo__search">
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="بحث في المعاينة…" autocomplete="off">
      </label>
      <input type="hidden" name="page" value="1">
    </form>

    <?php if ($view === 'all' && ($availableOnPage > 0 || $unavailableOnPage > 0)): ?>
      <p class="store-availability-demo__page-stats">
        في هذه الصفحة:
        <span class="store-num" dir="ltr"><?= (int) $availableOnPage ?></span> متاح
        <?php if ($unavailableOnPage > 0): ?>
          · <span class="store-num" dir="ltr"><?= (int) $unavailableOnPage ?></span> غير متوفر (في الأسفل بعد الترتيب)
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>

  <section class="store-availability-demo__catalog" aria-label="شبكة المعاينة">
    <?php if ($products === [] && !empty($catalog['apiError'])): ?>
      <div class="store-empty-state store-empty-state--maintenance">
        <span class="material-symbols-outlined store-empty-state__icon" aria-hidden="true">cloud_off</span>
        <strong class="store-empty-state__title">تعذر جلب المواد للمعاينة</strong>
        <p class="store-empty-state__text"><?= h((string) $catalog['apiError']) ?></p>
        <p class="store-empty-state__hint">عند تشغيل API الأمين محلياً ستظهر بطاقات حقيقية مع التمييز البصري.</p>
      </div>
    <?php elseif ($products === []): ?>
      <div class="store-empty-state">لا توجد نتائج لهذا الوضع.</div>
    <?php else: ?>
      <?php if ($view === 'all' && $unavailableOnPage > 0 && $availableOnPage > 0): ?>
        <p class="store-availability-demo__divider-label" data-first-unavailable>▼ يبدأ قسم غير المتوفر (مرتّب تلقائياً في الأسفل)</p>
      <?php endif; ?>
      <div class="store-product-grid">
        <?php
          $markedDivider = false;
          foreach ($products as $item):
            if (!is_array($item)) {
                continue;
            }
            $isOut = packages_available_display($item) <= 0;
            if ($view === 'all' && !$markedDivider && $isOut && $availableOnPage > 0):
                $markedDivider = true;
        ?>
          <div class="store-availability-demo__grid-divider" aria-hidden="true"></div>
        <?php
            endif;
            $availabilityPreview = true;
            $useImagePreview = true;
            $useQuickView = false;
            $linkToDetail = true;
            require __DIR__ . '/partials/product-card.php';
          endforeach;
        ?>
      </div>
    <?php endif; ?>

    <?php if ((int) ($catalog['totalCount'] ?? 0) > 0): ?>
      <nav class="store-availability-demo__pager" aria-label="صفحات">
        <?php if ($page > 1): ?>
          <a href="<?= h($buildDemoUrl(['page' => $page - 1])) ?>" class="store-availability-demo__pager-btn">السابق</a>
        <?php endif; ?>
        <span class="store-availability-demo__pager-meta">صفحة <?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="<?= h($buildDemoUrl(['page' => $page + 1])) ?>" class="store-availability-demo__pager-btn">التالي</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</div>
