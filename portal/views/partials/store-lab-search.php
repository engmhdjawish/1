<?php

declare(strict_types=1);

use Portal\Services\HomePageService;

/** @var array<string, mixed> $filters */

$filters = is_array($filters ?? null) ? $filters : [];
$searchQuery = trim((string) ($filters['q'] ?? ''));
$discover = [];
try {
    $sections = HomePageService::mergedSectionShells();
} catch (Throwable) {
    $sections = [];
}
foreach ($sections as $section) {
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
        'href' => store_lab_section_url($section),
    ];
}
$discoverJson = json_encode(
    $discover,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$carriedQuery = $_GET;
foreach (['q', 'page', 'soon', 'storeGuids', 'minWarehouseQuantity', 'maxWarehouseQuantity'] as $dropKey) {
    unset($carriedQuery[$dropKey]);
}
?>
<section class="hl hl-search-panel store-lab-search" aria-labelledby="sl-search-title">
  <div class="hl-search-panel__copy">
    <h2 id="sl-search-title" class="hl-search-panel__title">ابحث في المواد</h2>
  </div>
  <form class="hl-search" action="/store-lab.php" method="get" role="search" data-hl-search data-hl-surface="lab">
    <?php foreach ($carriedQuery as $key => $value): ?>
      <?php if (!is_string($key) || $key === '') continue; ?>
      <?php if (is_array($value)): ?>
        <?php foreach ($value as $item): ?>
          <?php if (!is_scalar($item) || trim((string) $item) === '') continue; ?>
          <input type="hidden" name="<?= h($key) ?>[]" value="<?= h((string) $item) ?>">
        <?php endforeach; ?>
      <?php elseif (is_scalar($value) && trim((string) $value) !== ''): ?>
        <input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="hl-search__box">
      <span class="material-symbols-outlined hl-search__icon" aria-hidden="true">search</span>
      <div class="hl-search__field">
        <span class="hl-search__ghost" data-hl-ghost hidden></span>
        <input
          id="sl-search-input"
          type="search"
          name="q"
          value="<?= h($searchQuery) ?>"
          placeholder="اسم المادة"
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
    <div class="hl-prompts" aria-label="أقسام">
      <?php foreach (array_slice($discover, 0, 6) as $item): ?>
        <button type="button" data-hl-prompt="<?= h($item['label']) ?>"><?= h($item['label']) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php require __DIR__ . '/lab-recent-searches.php'; ?>
</section>
<?= portal_defer_script('/assets/home-lab.js') ?>
