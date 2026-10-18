<?php

declare(strict_types=1);

use Portal\Services\AccessPolicyService;
use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;
use Portal\Support\StorePricePreference;

/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $displayOptions */
/** @var bool $isCustomer */
/** @var array{ok?: bool, message?: string}|string|null $cartNotice */

$catalog = is_array($catalog ?? null) ? $catalog : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$shareContext = null;
$shareToken = '';

$cartNoticeMessage = '';
$cartNoticeOk = true;
if (is_array($cartNotice ?? null)) {
    $cartNoticeMessage = (string) ($cartNotice['message'] ?? '');
    $cartNoticeOk = (bool) ($cartNotice['ok'] ?? false);
} elseif (isset($cartNotice) && is_string($cartNotice)) {
    $cartNoticeMessage = $cartNotice;
}

$filters = is_array($catalog['filters'] ?? null) ? $catalog['filters'] : [];
$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
$storeOptions = is_array($catalog['store_options'] ?? null) ? $catalog['store_options'] : [];
$filterOptions = is_array($catalog['filterOptions'] ?? null) ? $catalog['filterOptions'] : [];
$resultFilters = is_array($catalog['resultFilters'] ?? null) ? $catalog['resultFilters'] : [];
$payload = is_array($catalog['client_filters_payload'] ?? null) ? $catalog['client_filters_payload'] : [];
$payloadResultFilters = is_array($payload['resultFilters'] ?? null) ? $payload['resultFilters'] : [];
$payloadFilterOptions = is_array($payload['filterOptions'] ?? null) ? $payload['filterOptions'] : [];
$lockedClientFilters = array_map('strval', is_array($catalog['locked_client_filters'] ?? null) ? $catalog['locked_client_filters'] : []);
$products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
$totalCount = (int) ($catalog['totalCount'] ?? 0);

$visibleClientFilters = AccessPolicyService::resolvedVisibleClientFilters($storeOptions);
$isClientFilterVisible = static fn (string $code): bool => in_array($code, $visibleClientFilters, true)
    && !in_array($code, $lockedClientFilters, true);

$searchQuery = $isClientFilterVisible('search') ? trim((string) ($filters['q'] ?? '')) : '';
$sectionParam = trim((string) ($filters['section'] ?? ''));
$offerParam = trim((string) ($filters['offer'] ?? ''));
$availabilityValue = ($filters['isAvailable'] ?? null) === true ? '1' : (($filters['isAvailable'] ?? null) === false ? '0' : '');
$hasImageValue = ($filters['hasImage'] ?? null) === null ? '' : (!empty($filters['hasImage']) ? '1' : '0');
$activeSort = (string) ($filters['sort'] ?? 'number:asc');

$facetLabels = [
    'ageCategories' => 'الفئة',
    'materialTypes' => 'النوع',
    'sizeRanges' => 'القياس',
    'manufacturers' => 'الشركة',
    'countryOfOrigins' => 'المنشأ',
];
$facetIcons = [
    'ageCategories' => 'groups',
    'materialTypes' => 'category',
    'sizeRanges' => 'straighten',
    'manufacturers' => 'factory',
    'countryOfOrigins' => 'public',
];
$selectedFacets = [];
foreach (array_keys($facetLabels) as $param) {
    $selectedFacets[$param] = $isClientFilterVisible($param)
        ? array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), is_array($filters[$param] ?? null) ? $filters[$param] : []), static fn (string $v): bool => $v !== ''))
        : [];
}

$baseParams = array_filter([
    'q' => $searchQuery,
    'section' => $sectionParam,
    'offer' => $offerParam,
], static fn (string $v): bool => $v !== '');
$stateParams = $baseParams;
foreach ($selectedFacets as $param => $values) {
    if ($values !== []) {
        $stateParams[$param] = $values;
    }
}
if ($availabilityValue !== '') {
    $stateParams['isAvailable'] = $availabilityValue;
}
if ($hasImageValue !== '') {
    $stateParams['hasImage'] = $hasImageValue;
}
if (array_key_exists('sort', $_GET)) {
    $stateParams['sort'] = $activeSort;
}

$urlWithout = static function (string $param, ?string $value = null) use ($stateParams): string {
    $params = $stateParams;
    if ($value === null || !is_array($params[$param] ?? null)) {
        unset($params[$param]);
    } else {
        $params[$param] = array_values(array_filter($params[$param], static fn (string $v): bool => $v !== $value));
    }

    return store_lab_url($params);
};
$clearAllUrl = store_lab_url(array_diff_key($baseParams, ['q' => true]));

$tileFacet = store_easy_tile_facet($catalog);
$tileParam = $tileFacet['param'] ?? null;
$shopTiles = [];
$shopTilesTitle = '';
if ($tileFacet !== null) {
    $tileKeep = $baseParams;
    if ($availabilityValue !== '') {
        $tileKeep['isAvailable'] = $availabilityValue;
    }
    if (array_key_exists('sort', $_GET)) {
        $tileKeep['sort'] = $activeSort;
    }
    $shopTiles = store_easy_tiles(
        $tileFacet,
        $selectedFacets[$tileFacet['param']] ?? [],
        static fn (array $extra): string => store_lab_url(array_merge($tileKeep, $extra))
    );
    $shopTilesTitle = $tileFacet['title'];
}

$facetOptions = static function (string $param, array $selected) use ($resultFilters, $payloadResultFilters, $filterOptions, $payloadFilterOptions): array {
    $options = [];
    foreach ([$resultFilters[$param] ?? [], $payloadResultFilters[$param] ?? []] as $source) {
        if (!is_array($source) || $source === []) {
            continue;
        }
        foreach ($source as $row) {
            $value = trim((string) (is_array($row) ? ($row['value'] ?? '') : $row));
            if ($value === '' || isset($options[$value])) {
                continue;
            }
            $count = is_array($row) && isset($row['count']) && is_numeric($row['count']) ? (int) $row['count'] : null;
            $options[$value] = $count;
        }
        if ($options !== []) {
            break;
        }
    }
    if ($options === []) {
        foreach ([$filterOptions[$param] ?? [], $payloadFilterOptions[$param] ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $value) {
                $value = trim((string) (is_array($value) ? ($value['value'] ?? '') : $value));
                if ($value !== '' && !array_key_exists($value, $options)) {
                    $options[$value] = null;
                }
            }
            if ($options !== []) {
                break;
            }
        }
    }
    foreach ($selected as $value) {
        if (!array_key_exists($value, $options)) {
            $options = [$value => null] + $options;
        }
    }

    return $options;
};

$pillFacets = [];
$quickSizes = [];
foreach ($facetLabels as $param => $label) {
    if ($param === $tileParam || !$isClientFilterVisible($param)) {
        continue;
    }
    $options = $facetOptions($param, $selectedFacets[$param]);
    if ($options === []) {
        continue;
    }
    if ($param === 'sizeRanges') {
        uksort($options, static fn ($a, $b): int => strnatcasecmp((string) $a, (string) $b));
        if (count($options) <= 30) {
            foreach ($options as $value => $count) {
                $value = (string) $value;
                $isOn = in_array($value, $selectedFacets[$param], true);
                $next = $stateParams;
                $nextValues = $isOn
                    ? array_values(array_diff($selectedFacets[$param], [$value]))
                    : array_merge($selectedFacets[$param], [$value]);
                if ($nextValues === []) {
                    unset($next[$param]);
                } else {
                    $next[$param] = $nextValues;
                }
                $quickSizes[] = ['value' => $value, 'count' => $count, 'active' => $isOn, 'href' => store_lab_url($next)];
            }
            continue;
        }
    }
    $pillFacets[$param] = ['label' => $label, 'icon' => $facetIcons[$param], 'options' => $options];
}

$sortFieldLabels = [
    'number' => 'رقم المادة',
    'materialType' => 'النوع',
    'ageCategory' => 'الفئة',
    'manufacturer' => 'الشركة',
    'sizeRange' => 'القياس',
    'countryOfOrigin' => 'المنشأ',
];
$priceSortLabels = [
    'unitSalePriceSyp' => 'ل.س',
    'unitSalePriceUsd' => '$',
];
$sortOptions = [];
if ((bool) ($storeOptions['allow_sorting'] ?? true) && $isClientFilterVisible('sort')) {
    $clientSortFields = array_map('strval', $storeOptions['client_sort_fields'] ?? ['number', 'materialType', 'manufacturer']);
    foreach ($clientSortFields as $field) {
        if (isset($sortFieldLabels[$field])) {
            $sortOptions[$field . ':asc'] = $sortFieldLabels[$field];
        } elseif (isset($priceSortLabels[$field])) {
            $sortOptions[$field . ':asc'] = 'السعر من الأقل ' . $priceSortLabels[$field];
            $sortOptions[$field . ':desc'] = 'السعر من الأعلى ' . $priceSortLabels[$field];
        }
    }
    if ($sortOptions !== [] && !isset($sortOptions[$activeSort])) {
        [$activeField, $activeDir] = array_pad(explode(':', $activeSort, 2), 2, 'asc');
        if (isset($sortFieldLabels[$activeField])) {
            $sortOptions[$activeSort] = $sortFieldLabels[$activeField] . ($activeDir === 'desc' ? ' ↓' : '');
        }
    }
}
if (count($sortOptions) < 2) {
    $sortOptions = [];
}
$showAvailabilityToggle = $isClientFilterVisible('availability');

$chips = [];
if ($searchQuery !== '') {
    $chips[] = ['text' => $searchQuery, 'icon' => 'search', 'url' => $urlWithout('q')];
}
foreach ($selectedFacets as $param => $values) {
    foreach ($values as $value) {
        $chips[] = ['text' => $value, 'icon' => null, 'url' => $urlWithout($param, $value)];
    }
}
if ($availabilityValue !== '') {
    $chips[] = ['text' => $availabilityValue === '1' ? 'المتوفر فقط' : 'غير المتوفر', 'icon' => null, 'url' => $urlWithout('isAvailable')];
}

$hiddenFormParams = $baseParams;
if ($tileParam !== null && ($selectedFacets[$tileParam] ?? []) !== []) {
    $hiddenFormParams[$tileParam] = $selectedFacets[$tileParam];
}
if ($hasImageValue !== '') {
    $hiddenFormParams['hasImage'] = $hasImageValue;
}
if ($quickSizes !== [] && $selectedFacets['sizeRanges'] !== []) {
    $hiddenFormParams['sizeRanges'] = $selectedFacets['sizeRanges'];
}

$page = max(1, (int) ($catalog['page'] ?? 1));
$totalPages = max(1, (int) ($catalog['totalPages'] ?? 1));
$buildStoreUrl = static function (int $targetPage) use ($stateParams): string {
    $params = $stateParams;
    if ($targetPage > 1) {
        $params['page'] = $targetPage;
    }

    return store_lab_url($params);
};

$productReturnUrl = catalog_current_return_url();
$productOfferSlug = null;
if ($sectionContext !== null && !empty($sectionContext['is_offer_section'])) {
    $productOfferSlug = trim((string) ($sectionContext['slug'] ?? '')) ?: null;
}

try {
    $storeMaxPackages = StorePolicyService::maxPackagesPerMaterial();
} catch (Throwable) {
    $storeMaxPackages = null;
}
$storeShowPrice = (bool) ($displayOptions['show_price'] ?? false);
$baseDisplayOptions = $displayOptions;
?>
<div class="se store-lab">
  <?php require __DIR__ . '/partials/store-lab-search.php'; ?>

  <div
    class="se-live"
    data-se-live
    data-se-total="<?= $totalCount ?>"
    data-se-shown="<?= (int) ($catalog['rangeEnd'] ?? count($products)) ?>"
    data-se-next="<?= $page < $totalPages ? h($buildStoreUrl($page + 1)) : '' ?>"
  >

  <?php if ($sectionContext !== null): ?>
    <section class="se-section-banner">
      <div>
        <p class="se-section-banner__kicker"><?= !empty($sectionContext['is_offer_section']) ? 'عرض خاص' : 'قسم' ?></p>
        <h2 class="se-section-banner__title"><?= h((string) ($sectionContext['title_ar'] ?? '')) ?></h2>
      </div>
      <a href="/store-lab.php" class="se-section-banner__exit">
        كل المواد
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </a>
    </section>
  <?php endif; ?>

  <?php require __DIR__ . '/partials/shop-by-tiles.php'; ?>

  <?php if ($pillFacets !== [] || $quickSizes !== [] || $showAvailabilityToggle || $sortOptions !== []): ?>
    <form class="se-bar" action="/store-lab.php" method="get" data-se-bar>
      <?php foreach ($hiddenFormParams as $key => $value): ?>
        <?php if (is_array($value)): ?>
          <?php foreach ($value as $item): ?>
            <input type="hidden" name="<?= h($key) ?>[]" value="<?= h((string) $item) ?>">
          <?php endforeach; ?>
        <?php else: ?>
          <input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"<?= $key === 'q' ? ' data-hl-query-mirror' : '' ?>>
        <?php endif; ?>
      <?php endforeach; ?>

      <div class="se-bar__row">
        <?php foreach ($pillFacets as $param => $pill): ?>
          <?php
            $selected = $selectedFacets[$param];
            $selectedCount = count($selected);
            $popId = 'se-pop-' . $param;
          ?>
          <details class="se-pill<?= $selectedCount > 0 ? ' is-active' : '' ?>" data-se-pill data-se-param="<?= h($param) ?>">
            <summary class="se-pill__summary">
              <span class="material-symbols-outlined se-pill__icon" aria-hidden="true"><?= h($pill['icon']) ?></span>
              <span class="se-pill__label"><?= h($pill['label']) ?></span>
              <?php if ($selectedCount > 0): ?>
                <span class="se-pill__count"><?= $selectedCount ?></span>
              <?php endif; ?>
              <span class="material-symbols-outlined se-pill__chev" aria-hidden="true">expand_more</span>
            </summary>
            <div class="se-pop" id="<?= h($popId) ?>" role="group" aria-label="<?= h($pill['label']) ?>">
              <div class="se-pop__head">
                <strong><?= h($pill['label']) ?></strong>
                <button type="button" class="se-pop__close" data-se-close aria-label="إغلاق">
                  <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
              </div>
              <?php if (count($pill['options']) > 8): ?>
                <input type="search" class="se-pop__find" placeholder="<?= h($pill['label']) ?>" autocomplete="off" data-se-find aria-label="بحث في <?= h($pill['label']) ?>">
              <?php endif; ?>
              <div class="se-pop__list">
                <?php foreach ($pill['options'] as $value => $count): ?>
                  <?php $value = (string) $value; $checked = in_array($value, $selected, true); ?>
                  <label class="se-opt<?= $checked ? ' is-checked' : '' ?>" data-se-opt="<?= h($value) ?>">
                    <input type="checkbox" name="<?= h($param) ?>[]" value="<?= h($value) ?>"<?= $checked ? ' checked' : '' ?>>
                    <span class="se-opt__box" aria-hidden="true"></span>
                    <span class="se-opt__text"><?= h($value) ?></span>
                    <?php if ($count !== null): ?>
                      <span class="se-opt__count"><?= (int) $count ?></span>
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="se-pop__foot">
                <button type="submit" class="se-btn se-btn--primary" data-se-apply>عرض النتائج</button>
                <?php if ($selectedCount > 0): ?>
                  <a href="<?= h($urlWithout($param)) ?>" class="se-btn se-btn--ghost">مسح</a>
                <?php endif; ?>
              </div>
            </div>
          </details>
        <?php endforeach; ?>

        <?php if ($showAvailabilityToggle): ?>
          <label class="se-toggle<?= $availabilityValue === '1' ? ' is-on' : '' ?>">
            <input type="checkbox" name="isAvailable" value="1"<?= $availabilityValue === '1' ? ' checked' : '' ?> data-se-autosubmit>
            <span class="se-toggle__track" aria-hidden="true"><span class="se-toggle__thumb"></span></span>
            <span>المتوفر فقط</span>
          </label>
        <?php endif; ?>

        <?php if ($sortOptions !== []): ?>
          <label class="se-sort">
            <span class="material-symbols-outlined" aria-hidden="true">swap_vert</span>
            <span class="sr-only">ترتيب</span>
            <select name="sort" data-se-autosubmit>
              <?php foreach ($sortOptions as $value => $label): ?>
                <option value="<?= h($value) ?>"<?= $value === $activeSort ? ' selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
      </div>
      <?php if ($quickSizes !== []): ?>
        <div class="se-sizes" aria-label="القياس">
          <span class="se-sizes__label">
            <span class="material-symbols-outlined" aria-hidden="true">straighten</span>
            القياس
          </span>
          <div class="se-sizes__row">
            <?php foreach ($quickSizes as $size): ?>
              <a
                href="<?= h($size['href']) ?>"
                class="se-size<?= $size['active'] ? ' is-active' : '' ?>"
                aria-pressed="<?= $size['active'] ? 'true' : 'false' ?>"
              ><?= h($size['value']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <noscript><button type="submit" class="se-btn se-btn--primary">عرض النتائج</button></noscript>
    </form>
  <?php endif; ?>

  <?php if ($storeShowPrice): ?>
    <p class="store-syp-disclaimer" data-store-syp-disclaimer role="note"<?= StorePricePreference::current() !== StorePricePreference::SYP ? ' hidden' : '' ?>>
      <span class="material-symbols-outlined" aria-hidden="true">info</span>
      الأسعار بالليرة السورية تقريبية وقد تتغيّر حسب سعر الصرف وقت إتمام الطلب.
    </p>
  <?php endif; ?>

  <?php if ($cartNoticeMessage !== ''): ?>
    <p class="se-notice<?= $cartNoticeOk ? '' : ' se-notice--error' ?>"><?= h($cartNoticeMessage) ?></p>
  <?php endif; ?>

  <?php if (!empty($catalog['apiError']) && $products !== []): ?>
    <p class="se-notice se-notice--warn"><?= h((string) $catalog['apiError']) ?></p>
  <?php endif; ?>

  <div class="se-results-head">
    <p class="se-results-count">
      <?php if ($totalCount > 0 && $products !== []): ?>
        <strong><?= $totalCount ?></strong> مادة
      <?php endif; ?>
    </p>
    <?php if ($chips !== []): ?>
      <div class="se-chips" aria-label="الاختيارات">
        <?php foreach ($chips as $chip): ?>
          <a href="<?= h($chip['url']) ?>" class="se-chip" aria-label="إزالة <?= h($chip['text']) ?>">
            <?php if ($chip['icon'] !== null): ?>
              <span class="material-symbols-outlined se-chip__icon" aria-hidden="true"><?= h($chip['icon']) ?></span>
            <?php endif; ?>
            <span><?= h($chip['text']) ?></span>
            <span class="material-symbols-outlined se-chip__x" aria-hidden="true">close</span>
          </a>
        <?php endforeach; ?>
        <?php if (count($chips) > 1): ?>
          <a href="<?= h($clearAllUrl) ?>" class="se-chip se-chip--clear">مسح الكل</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($products !== []): ?>
      <div class="se-density" role="group" aria-label="طريقة العرض" data-se-density hidden>
        <button type="button" data-se-density-set="1" aria-label="صورة كبيرة">
          <span class="material-symbols-outlined" aria-hidden="true">view_agenda</span>
        </button>
        <button type="button" data-se-density-set="2" aria-label="صورتان">
          <span class="material-symbols-outlined" aria-hidden="true">grid_view</span>
        </button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($products === [] && !empty($catalog['apiError'])): ?>
    <div class="store-empty-state store-empty-state--maintenance" role="status">
      <span class="material-symbols-outlined store-empty-state__icon" aria-hidden="true">cloud_off</span>
      <strong class="store-empty-state__title">عطل مؤقت</strong>
      <p class="store-empty-state__text"><?= h((string) $catalog['apiError']) ?></p>
    </div>
  <?php elseif ($products === []): ?>
    <div class="se-empty" role="status">
      <span class="material-symbols-outlined se-empty__icon" aria-hidden="true">search_off</span>
      <strong class="se-empty__title">لا توجد مواد مطابقة</strong>
      <?php if ($chips !== []): ?>
        <a href="<?= h($clearAllUrl) ?>" class="se-btn se-btn--primary">مسح الكل</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="store-product-grid se-grid">
      <?php foreach ($products as $item): ?>
        <?php if (!is_array($item)) continue; ?>
        <?php
          $displayOptions = $baseDisplayOptions;
          $comingSoonCard = StoreCatalogService::materialIsUnavailable($item);
          $omitBlankSalePrice = true;
          if ($comingSoonCard) {
              $displayOptions['allow_cart'] = false;
              $displayOptions['show_quantity'] = false;
          }
          $useImagePreview = (bool) ($storeCatalogImagePreview ?? true);
          $useQuickView = false;
          $linkToDetail = true;
          $capturePrices = (bool) ($displayOptions['show_price'] ?? false);
          require __DIR__ . '/partials/product-card.php';
        ?>
      <?php endforeach; ?>
      <?php $displayOptions = $baseDisplayOptions; ?>
    </div>
  <?php endif; ?>

  <?php
    $buildUrl = static fn (int $targetPage): string => $buildStoreUrl($targetPage);
    require __DIR__ . '/partials/catalog-pagination.php';
    $rangeEnd = (int) ($catalog['rangeEnd'] ?? count($products));
    $buildPreviewPageUrl = static function (int $targetPage, string $previewEdge) use ($buildStoreUrl): string {
        $url = $buildStoreUrl($targetPage);

        return $url . (str_contains($url, '?') ? '&' : '?') . 'preview=' . rawurlencode($previewEdge);
    };
  ?>
  <script type="application/json" data-store-preview-paging><?= json_encode([
      'page' => $page,
      'totalPages' => $totalPages,
      'prevPageUrl' => $page > 1 ? $buildPreviewPageUrl($page - 1, 'last') : null,
      'nextPageUrl' => $page < $totalPages ? $buildPreviewPageUrl($page + 1, 'first') : null,
  ], JSON_UNESCAPED_UNICODE) ?></script>
  <?php if ($page < $totalPages): ?>
    <div class="se-more" data-se-more hidden>
      <p class="se-more__meta"><span data-se-more-shown><?= $rangeEnd ?></span> من <?= $totalCount ?></p>
      <div class="se-more__bar" aria-hidden="true"><span style="width: <?= $totalCount > 0 ? (int) round(min(100, $rangeEnd * 100 / $totalCount)) : 0 ?>%" data-se-more-fill></span></div>
      <button type="button" class="se-btn se-btn--outline" data-se-more-btn>
        عرض المزيد
        <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
      </button>
    </div>
  <?php endif; ?>
  </div>

  <button type="button" class="se-top" data-se-top aria-label="إلى الأعلى" hidden>
    <span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span>
  </button>
</div>

<?php if (empty($GLOBALS['storeCatalogPreviewRendered'])): ?>
  <?php $GLOBALS['storeCatalogPreviewRendered'] = true; ?>
  <?php $GLOBALS['storeProductPreviewRendered'] = true; ?>
  <?php require __DIR__ . '/partials/store-product-preview.php'; ?>
<?php endif; ?>
<?php $GLOBALS['storeProductPreviewScriptLoaded'] = true; ?>
<script src="<?= h(portal_asset_url('/assets/store-product-preview.js')) ?>" defer></script>
<?= portal_defer_script('/assets/store-easy.js') ?>
