<?php

declare(strict_types=1);

use Portal\Services\AccessPolicyService;

/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $comingSoon */
/** @var array<string, mixed> $displayOptions */
/** @var list<array<string, mixed>> $discoverSections */
/** @var bool $isCustomer */

$catalog = is_array($catalog ?? null) ? $catalog : [];
$comingSoon = is_array($comingSoon ?? null) ? $comingSoon : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$discoverSections = is_array($discoverSections ?? null) ? $discoverSections : [];
$isCustomer = (bool) ($isCustomer ?? false);

$filters = is_array($catalog['filters'] ?? null) ? $catalog['filters'] : [];
$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
$storeOptions = is_array($catalog['store_options'] ?? null) ? $catalog['store_options'] : [];
$filterOptions = is_array($catalog['filterOptions'] ?? null) ? $catalog['filterOptions'] : ['stores' => [], 'groups' => []];
$resultFilters = is_array($catalog['resultFilters'] ?? null) ? $catalog['resultFilters'] : [];
$clientFiltersPayload = is_array($catalog['client_filters_payload'] ?? null) ? $catalog['client_filters_payload'] : null;
if ($clientFiltersPayload !== null) {
    if ($resultFilters === [] && is_array($clientFiltersPayload['resultFilters'] ?? null)) {
        $resultFilters = $clientFiltersPayload['resultFilters'];
    }
    if (($filterOptions['stores'] ?? []) === [] && is_array($clientFiltersPayload['filterOptions'] ?? null)) {
        $filterOptions = $clientFiltersPayload['filterOptions'];
    }
}
$lockedClientFilters = array_map('strval', is_array($catalog['locked_client_filters'] ?? null) ? $catalog['locked_client_filters'] : []);
$visibleClientFilters = AccessPolicyService::resolvedVisibleClientFilters($storeOptions);
$allowClientFilters = (bool) ($catalog['allow_client_filters'] ?? false);
$allowSorting = (bool) ($storeOptions['allow_sorting'] ?? true);
$isClientFilterVisible = static function (string $code) use ($visibleClientFilters, $lockedClientFilters, $allowClientFilters): bool {
    if (!$allowClientFilters || !in_array($code, $visibleClientFilters, true)) {
        return false;
    }

    return !in_array($code, $lockedClientFilters, true);
};

$products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
$soonProducts = is_array($comingSoon['products'] ?? null) ? $comingSoon['products'] : [];
$stockTotal = (int) ($catalog['totalCount'] ?? count($products));
$soonTotal = (int) ($comingSoon['totalCount'] ?? count($soonProducts));
$stockPage = max(1, (int) ($catalog['page'] ?? 1));
$stockPages = max(1, (int) ($catalog['totalPages'] ?? 1));
$soonPage = max(1, (int) ($comingSoon['page'] ?? 1));
$soonPages = max(1, (int) ($comingSoon['totalPages'] ?? 1));
$searchQuery = trim((string) ($filters['q'] ?? $_GET['q'] ?? ''));
$activeSort = (string) ($filters['sort'] ?? 'number:asc');
$selectedGroupBy = (string) ($filters['groupBy'] ?? 'none');

$baseParams = $_GET;
unset($baseParams['page'], $baseParams['soon'], $baseParams['isAvailable']);

$toggleUrl = static function (string $param, string $value) use ($baseParams): string {
    $params = $baseParams;
    $current = $params[$param] ?? [];
    if (!is_array($current)) {
        $current = trim((string) $current) === '' ? [] : array_map('trim', explode(',', (string) $current));
    }
    $current = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $current), static fn (string $item): bool => $item !== ''));
    $exists = in_array($value, $current, true);
    $current = $exists
        ? array_values(array_filter($current, static fn (string $item): bool => $item !== $value))
        : array_merge($current, [$value]);
    if ($current === []) {
        unset($params[$param]);
    } else {
        $params[$param] = $current;
    }

    return store_lab_url($params);
};
$withoutUrl = static function (array $keys) use ($baseParams): string {
    $params = $baseParams;
    foreach ($keys as $key) {
        unset($params[$key]);
    }

    return store_lab_url($params);
};
$pageUrl = static function (int $page) use ($baseParams): string {
    $params = $baseParams;
    if ($page > 1) {
        $params['page'] = $page;
    } else {
        unset($params['page']);
    }

    return store_lab_url($params) . '#available';
};
$soonUrl = static function (int $page) use ($baseParams): string {
    $params = $baseParams;
    if ($page > 1) {
        $params['soon'] = $page;
    } else {
        unset($params['soon']);
    }

    return store_lab_url($params) . '#coming-soon';
};

$selectedLists = [
    'materialTypes' => is_array($filters['materialTypes'] ?? null) ? $filters['materialTypes'] : [],
    'manufacturers' => is_array($filters['manufacturers'] ?? null) ? $filters['manufacturers'] : [],
    'ageCategories' => is_array($filters['ageCategories'] ?? null) ? $filters['ageCategories'] : [],
    'sizeRanges' => is_array($filters['sizeRanges'] ?? null) ? $filters['sizeRanges'] : [],
    'countryOfOrigins' => is_array($filters['countryOfOrigins'] ?? null) ? $filters['countryOfOrigins'] : [],
    'storeGuids' => is_array($filters['storeGuids'] ?? null) ? $filters['storeGuids'] : [],
    'groupGuids' => is_array($filters['groupGuids'] ?? null) ? $filters['groupGuids'] : [],
];

$facetConfig = [
    'materialTypes' => ['param' => 'materialTypes', 'label' => 'النوع'],
    'ageCategories' => ['param' => 'ageCategories', 'label' => 'الفئة'],
    'manufacturers' => ['param' => 'manufacturers', 'label' => 'الشركة'],
    'sizeRanges' => ['param' => 'sizeRanges', 'label' => 'القياس'],
    'countryOfOrigins' => ['param' => 'countryOfOrigins', 'label' => 'المنشأ'],
];

$storeLabels = [];
foreach ($filterOptions['stores'] ?? [] as $storeRow) {
    if (!is_array($storeRow)) {
        continue;
    }
    $guid = trim((string) ($storeRow['guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $storeLabels[$guid] = trim((string) ($storeRow['name'] ?? '')) ?: (trim((string) ($storeRow['code'] ?? '')) ?: $guid);
}
$groupLabels = [];
foreach ($filterOptions['groups'] ?? [] as $groupRow) {
    if (!is_array($groupRow)) {
        continue;
    }
    $guid = trim((string) ($groupRow['guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $groupLabels[$guid] = trim((string) ($groupRow['name'] ?? '')) ?: (trim((string) ($groupRow['code'] ?? '')) ?: $guid);
}

$sortFieldLabels = [
    'number' => 'رقم المادة',
    'materialType' => 'نوع المادة',
    'ageCategory' => 'الفئة',
    'manufacturer' => 'الشركة',
    'sizeRange' => 'القياس',
    'countryOfOrigin' => 'المنشأ',
    'unitSalePriceSyp' => 'السعر ل.س',
    'unitSalePriceUsd' => 'السعر $',
];
$clientSortFields = array_values(array_filter(
    array_map('strval', $storeOptions['client_sort_fields'] ?? ['number', 'materialType', 'manufacturer']),
    static fn (string $field): bool => isset($sortFieldLabels[$field])
));
if ($clientSortFields === []) {
    $clientSortFields = ['number'];
}

$discover = [];
foreach ($discoverSections as $section) {
    if (!is_array($section)) {
        continue;
    }
    $label = trim((string) ($section['title_ar'] ?? ''));
    if ($label === '') {
        continue;
    }
    $discover[] = [
        'kind' => 'section',
        'label' => $label,
        'hint' => !empty($section['is_offer_section']) ? 'عرض' : 'قسم',
        'href' => store_lab_section_url($section),
    ];
}
$discoverJson = json_encode($discover, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$productReturnUrl = catalog_current_return_url();
$returnUrl = $productReturnUrl;
$storeSectionSlug = '';
$storeOfferSlug = '';
if ($sectionContext !== null) {
    $sectionSlug = trim((string) ($sectionContext['slug'] ?? ''));
    if (!empty($sectionContext['is_offer_section'])) {
        $storeOfferSlug = $sectionSlug;
    } else {
        $storeSectionSlug = $sectionSlug;
    }
}
$groupByForSoon = $selectedGroupBy !== 'none' ? $selectedGroupBy : 'materialType';
$soonGroups = store_lab_group_products($soonProducts, $groupByForSoon);
$stockGroups = $selectedGroupBy !== 'none' ? store_lab_group_products($products, $selectedGroupBy) : [];

$renderHidden = static function (array $params, array $skip = []) : void {
    foreach ($params as $key => $value) {
        if (in_array((string) $key, $skip, true) || $value === null) {
            continue;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $text = trim((string) $item);
                if ($text === '') {
                    continue;
                }
                echo '<input type="hidden" name="' . h((string) $key) . '[]" value="' . h($text) . '">';
            }
            continue;
        }
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }
        echo '<input type="hidden" name="' . h((string) $key) . '" value="' . h($text) . '">';
    }
};

$renderPager = static function (int $page, int $totalPages, callable $buildUrl) : void {
    if ($totalPages <= 1) {
        return;
    }
    echo '<nav class="sl-pager" aria-label="صفحات">';
    if ($page > 1) {
        echo '<a href="' . h($buildUrl($page - 1)) . '">السابق</a>';
    }
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($number = $start; $number <= $end; $number++) {
        $class = $number === $page ? ' class="is-current"' : '';
        $current = $number === $page ? ' aria-current="page"' : '';
        echo '<a href="' . h($buildUrl($number)) . '"' . $class . $current . '>' . (int) $number . '</a>';
    }
    if ($page < $totalPages) {
        echo '<a href="' . h($buildUrl($page + 1)) . '">التالي</a>';
    }
    echo '</nav>';
};

$renderCards = static function (array $items, bool $isSoon) use ($displayOptions, $productReturnUrl, $returnUrl, $storeSectionSlug, $storeOfferSlug): void {
    echo '<div class="sl-grid' . ($isSoon ? ' sl-grid--quiet' : '') . '">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        require __DIR__ . '/partials/store-lab-card.php';
    }
    echo '</div>';
};
?>
<div class="home-page hl sl">
  <aside class="hl-lab-note" aria-label="ملاحظة النسخة التجريبية">
    <p><strong>نسخة تجريبية للمتجر.</strong> المتجر الحالي لم يتغيّر. المواد غير المتوفرة تظهر هنا في قسم منظم، وتبقى ظاهرة في البحث.</p>
    <div class="hl-lab-note__links">
      <a href="/home-lab.php">تجربة الرئيسية</a>
      <a href="/store.php">المتجر الحالي</a>
    </div>
  </aside>

  <section class="hl-search-panel" aria-labelledby="sl-search-title">
    <div class="hl-search-panel__copy">
      <h2 id="sl-search-title" class="hl-search-panel__title">ابحث في المواد</h2>
      <p class="hl-search-hint">يشمل البحث المتوفر الآن وما سيتوفر قريباً. اكتب جزءاً من الاسم أو الكود أو القياس.</p>
    </div>
    <form class="hl-search" action="/store-lab.php" method="get" role="search" data-hl-search data-hl-surface="lab">
      <?php $renderHidden($baseParams, ['q']); ?>
      <div class="hl-search__box">
        <span class="material-symbols-outlined hl-search__icon" aria-hidden="true">search</span>
        <div class="hl-search__field">
          <span class="hl-search__ghost" data-hl-ghost hidden></span>
          <input
            id="sl-search-input"
            type="search"
            name="q"
            value="<?= h($searchQuery) ?>"
            placeholder="اسم مادة، قسم، أو قياس"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            enterkeyhint="search"
            aria-autocomplete="list"
            aria-controls="sl-suggest"
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
      <div id="sl-suggest" class="hl-suggest" role="listbox" hidden data-hl-suggest></div>
      <script type="application/json" id="hl-search-dictionary"><?= $discoverJson !== false ? $discoverJson : '[]' ?></script>
    </form>
    <?php if ($discover !== []): ?>
      <div class="hl-prompts" aria-label="أقسام للبدء">
        <span>أقسام</span>
        <?php foreach (array_slice($discover, 0, 6) as $item): ?>
          <a href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="home-hero home-hero--premium" aria-labelledby="sl-hero-title">
    <div class="hl-hero-clip" aria-hidden="true">
      <div class="home-hero__glow home-hero__glow--left"></div>
      <div class="home-hero__glow home-hero__glow--right"></div>
    </div>
    <div class="home-hero__inner">
      <div class="home-hero__grid">
        <div class="home-hero__content">
          <p class="home-hero__kicker">
            <span class="home-hero__kicker-dot" aria-hidden="true"></span>
            تجربة المتجر
            <?php if ($sectionContext !== null && trim((string) ($sectionContext['title_ar'] ?? '')) !== ''): ?>
              <span class="hl-hero-count"><?= h((string) $sectionContext['title_ar']) ?></span>
            <?php endif; ?>
          </p>
          <h1 id="sl-hero-title" class="home-hero__title">
            <span class="home-hero__title-line"><?= $searchQuery !== '' ? 'نتائج البحث' : 'المتوفر أولاً' ?></span>
            <span class="home-hero__title-line"><?= $searchQuery !== '' ? '«' . h($searchQuery) . '»' : 'وما نفد في قسمه' ?></span>
          </h1>
          <p class="home-hero__lead">
            المواد الجاهزة للطلب تبقى في المقدمة. المواد غير المتوفرة لا تختلط بها، وتظهر في قسم «يتوفر قريباً» حتى تعرف أنها تُباع لدينا.
          </p>
          <ul class="sl-stats">
            <li><strong><?= (int) $stockTotal ?></strong><span>متوفر الآن</span></li>
            <li><strong><?= (int) $soonTotal ?></strong><span>يتوفر قريباً</span></li>
          </ul>
          <div class="home-hero__actions">
            <a href="#available" class="home-btn home-btn--light">المتوفر الآن</a>
            <a href="#coming-soon" class="home-btn home-btn--ghost">يتوفر قريباً</a>
            <a href="/store.php" class="home-btn home-btn--ghost">المتجر الحالي</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($discover !== []): ?>
    <nav class="sl-sections" aria-label="أقسام المتجر">
      <?php foreach (array_slice($discover, 0, 10) as $item): ?>
        <?php $isCurrent = $sectionContext !== null && trim((string) ($sectionContext['title_ar'] ?? '')) === $item['label']; ?>
        <a href="<?= h($item['href']) ?>"<?= $isCurrent ? ' class="is-current"' : '' ?>><?= h($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if (!empty($catalog['apiError'])): ?>
    <p class="sl-alert" role="alert"><?= h((string) $catalog['apiError']) ?></p>
  <?php endif; ?>

  <?php if ($allowClientFilters): ?>
    <section class="sl-filters" aria-labelledby="sl-filters-title">
      <header class="sl-filters__head">
        <h2 id="sl-filters-title">ضيّق النتائج</h2>
        <p>نفس فلاتر المتجر. غير المتوفر لا يختلط بالشبكة، ويبقى في قسمه.</p>
        <?php
          $hasActiveFilter = $searchQuery !== '';
          foreach ($selectedLists as $selectedList) {
              if ($selectedList !== []) {
                  $hasActiveFilter = true;
                  break;
              }
          }
          $clearParams = [];
          if (trim((string) ($_GET['section'] ?? '')) !== '') {
              $clearParams['section'] = (string) $_GET['section'];
          }
          if (trim((string) ($_GET['offer'] ?? '')) !== '') {
              $clearParams['offer'] = (string) $_GET['offer'];
          }
        ?>
        <?php if ($hasActiveFilter): ?>
          <a href="<?= h(store_lab_url($clearParams)) ?>" class="sl-filters__clear">مسح التصفية</a>
        <?php endif; ?>
      </header>
      <?php
        $activeChips = [];
        if ($searchQuery !== '') {
            $activeChips[] = ['text' => $searchQuery, 'url' => $withoutUrl(['q'])];
        }
        foreach ($facetConfig as $code => $config) {
            foreach ($selectedLists[$code] as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $activeChips[] = ['text' => $value, 'url' => $toggleUrl((string) $config['param'], $value)];
            }
        }
        foreach ($selectedLists['storeGuids'] as $guid) {
            $guid = trim((string) $guid);
            if ($guid === '') {
                continue;
            }
            $activeChips[] = ['text' => $storeLabels[$guid] ?? 'مخزن', 'url' => $toggleUrl('storeGuids', $guid)];
        }
        foreach ($selectedLists['groupGuids'] as $guid) {
            $guid = trim((string) $guid);
            if ($guid === '') {
                continue;
            }
            $activeChips[] = ['text' => $groupLabels[$guid] ?? 'مجموعة', 'url' => $toggleUrl('groupGuids', $guid)];
        }
      ?>
      <?php if ($activeChips !== []): ?>
        <div class="sl-active" aria-label="التصفية الحالية">
          <?php foreach ($activeChips as $chip): ?>
            <a href="<?= h($chip['url']) ?>"><?= h($chip['text']) ?> <span aria-hidden="true">×</span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($isClientFilterVisible('sort') && $allowSorting): ?>
        <form class="sl-sort" method="get" action="/store-lab.php">
          <?php $renderHidden($baseParams, ['sort']); ?>
          <label for="sl-sort">الترتيب</label>
          <select id="sl-sort" name="sort" onchange="this.form.submit()">
            <?php foreach ($clientSortFields as $field): ?>
              <option value="<?= h($field . ':asc') ?>"<?= $activeSort === $field . ':asc' ? ' selected' : '' ?>><?= h($sortFieldLabels[$field]) ?> تصاعدي</option>
              <option value="<?= h($field . ':desc') ?>"<?= $activeSort === $field . ':desc' || $activeSort === '-' . $field ? ' selected' : '' ?>><?= h($sortFieldLabels[$field]) ?> تنازلي</option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>

      <?php foreach ($facetConfig as $code => $config): ?>
        <?php
          if (!$isClientFilterVisible($code)) {
              continue;
          }
          $options = [];
          $facets = is_array($resultFilters[$code] ?? null) ? $resultFilters[$code] : [];
          foreach ($facets as $facet) {
              if (!is_array($facet)) {
                  continue;
              }
              $value = trim((string) ($facet['value'] ?? ''));
              if ($value === '') {
                  continue;
              }
              $options[] = $value;
          }
          if ($options === [] && is_array($filterOptions[$code] ?? null)) {
              foreach ($filterOptions[$code] as $optionValue) {
                  $optionValue = trim((string) $optionValue);
                  if ($optionValue !== '') {
                      $options[] = $optionValue;
                  }
              }
          }
          foreach ($selectedLists[$code] as $selectedValue) {
              $selectedValue = trim((string) $selectedValue);
              if ($selectedValue !== '' && !in_array($selectedValue, $options, true)) {
                  array_unshift($options, $selectedValue);
              }
          }
          $options = array_values(array_unique($options));
          if ($options === []) {
              continue;
          }
          $visibleOptions = array_slice($options, 0, 8);
          foreach ($selectedLists[$code] as $selectedValue) {
              $selectedValue = trim((string) $selectedValue);
              if ($selectedValue !== '' && !in_array($selectedValue, $visibleOptions, true)) {
                  $visibleOptions[] = $selectedValue;
              }
          }
        ?>
        <div class="sl-facet">
          <h3><?= h($config['label']) ?></h3>
          <div class="sl-chips">
            <?php foreach ($visibleOptions as $value): ?>
              <?php $selected = in_array($value, $selectedLists[$code], true); ?>
              <a href="<?= h($toggleUrl((string) $config['param'], $value)) ?>"<?= $selected ? ' class="is-on"' : '' ?>><?= h($value) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($isClientFilterVisible('stores') && $storeLabels !== []): ?>
        <div class="sl-facet">
          <h3>المخازن</h3>
          <div class="sl-chips">
            <?php foreach (array_slice($storeLabels, 0, 8, true) as $guid => $label): ?>
              <?php $selected = in_array((string) $guid, $selectedLists['storeGuids'], true); ?>
              <a href="<?= h($toggleUrl('storeGuids', (string) $guid)) ?>"<?= $selected ? ' class="is-on"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($isClientFilterVisible('groups') && $groupLabels !== []): ?>
        <div class="sl-facet">
          <h3>المجموعات</h3>
          <div class="sl-chips">
            <?php foreach (array_slice($groupLabels, 0, 8, true) as $guid => $label): ?>
              <?php $selected = in_array((string) $guid, $selectedLists['groupGuids'], true); ?>
              <a href="<?= h($toggleUrl('groupGuids', (string) $guid)) ?>"<?= $selected ? ' class="is-on"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <nav class="home-section-nav--sticky" aria-label="تنظيم المتجر">
    <a class="home-section-nav__link" href="#available">متوفر الآن</a>
    <a class="home-section-nav__link" href="#coming-soon">يتوفر قريباً</a>
  </nav>

  <div class="home-sections">
    <section class="home-section sl-stock" id="available">
      <div class="home-section__body">
        <header class="home-section__header">
          <div>
            <span class="home-section__badge">للطلب الآن</span>
            <h2 class="home-section__title">متوفر الآن</h2>
            <p class="home-section__subtitle"><?= $searchQuery !== '' ? 'المواد المتوفرة المطابقة لبحثك.' : 'هذه الشبكة هي المواد الجاهزة. ما نفد مخزونه لا يظهر بينها.' ?></p>
          </div>
          <p class="sl-count"><?= (int) $stockTotal ?> مادة</p>
        </header>
        <?php if ($products === []): ?>
          <div class="home-section__empty">
            <?php if ($soonTotal > 0): ?>
              لا توجد مواد متوفرة الآن ضمن هذه التصفية.
              <a href="#coming-soon">قد تجدها في «يتوفر قريباً»</a>
            <?php else: ?>
              لا توجد مواد متوفرة مطابقة الآن.
            <?php endif; ?>
          </div>
        <?php elseif ($stockGroups !== []): ?>
          <?php foreach ($stockGroups as $label => $groupItems): ?>
            <h3 class="sl-group-title"><?= h((string) $label) ?> <span><?= count($groupItems) ?></span></h3>
            <?php $renderCards($groupItems, false); ?>
          <?php endforeach; ?>
        <?php else: ?>
          <?php $renderCards($products, false); ?>
        <?php endif; ?>
        <?php $renderPager($stockPage, $stockPages, $pageUrl); ?>
      </div>
    </section>

    <section class="home-section sl-soon" id="coming-soon">
      <div class="home-section__body">
        <header class="home-section__header">
          <div>
            <span class="home-section__badge">غير متوفر حالياً</span>
            <h2 class="home-section__title">يتوفر قريباً</h2>
            <p class="home-section__subtitle">مواد نبيعها، ومخزونها غير متاح الآن. مرتبة حسب النوع حتى لا تظهر بشكل عشوائي، ومن دون زر إضافة للسلة.</p>
          </div>
          <p class="sl-count"><?= (int) $soonTotal ?> مادة</p>
        </header>
        <?php if (!empty($comingSoon['apiError'])): ?>
          <p class="sl-alert"><?= h((string) $comingSoon['apiError']) ?></p>
        <?php endif; ?>
        <?php if ($soonProducts === []): ?>
          <div class="home-section__empty">لا توجد مواد غير متوفرة ضمن هذا البحث.</div>
        <?php else: ?>
          <?php foreach ($soonGroups as $label => $groupItems): ?>
            <?php if (count($soonGroups) > 1 || $label !== 'غير مصنّف'): ?>
              <h3 class="sl-group-title sl-group-title--quiet"><?= h((string) $label) ?> <span><?= count($groupItems) ?></span></h3>
            <?php endif; ?>
            <?php $renderCards($groupItems, true); ?>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php $renderPager($soonPage, $soonPages, $soonUrl); ?>
      </div>
    </section>
  </div>
</div>
