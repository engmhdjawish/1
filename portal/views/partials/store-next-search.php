<?php

declare(strict_types=1);

use Portal\Services\HomeLayoutService;

/** @var array<string, mixed> $filters */

$filters = is_array($filters ?? null) ? $filters : [];
$searchQuery = trim((string) ($filters['q'] ?? ''));
try {
    $searchPlaceholder = (string) (HomeLayoutService::config()['general']['search_placeholder'] ?? '');
} catch (Throwable) {
    $searchPlaceholder = '';
}
if ($searchPlaceholder === '') {
    $searchPlaceholder = 'ابحث عن مادة أو شركة';
}
$carriedQuery = $_GET;
foreach (['q', 'page', 'soon', 'storeGuids', 'minWarehouseQuantity', 'maxWarehouseQuantity'] as $dropKey) {
    unset($carriedQuery[$dropKey]);
}
?>
<section class="se-search" aria-label="البحث" data-guide="store-search">
  <form class="hn-search" action="/store.php" method="get" role="search" data-hn-search data-hl-search data-store-search>
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
    <label class="hn-search__box">
      <span class="material-symbols-outlined hn-search__icon" aria-hidden="true">search</span>
      <span class="hn-search__spinner" aria-hidden="true"></span>
      <span class="sr-only">ابحث في المواد</span>
      <input
        id="sl-search-input"
        type="search"
        name="q"
        value="<?= h($searchQuery) ?>"
        class="hn-search__input"
        placeholder="<?= h($searchPlaceholder) ?>"
        autocomplete="off"
        autocorrect="off"
        spellcheck="false"
        enterkeyhint="search"
        role="combobox"
        aria-controls="se-suggest"
        aria-expanded="false"
        aria-autocomplete="list"
        data-hl-search-input
        data-hn-search-input
      >
    </label>
    <button type="button" class="hn-search__voice" data-hn-voice hidden aria-label="بحث بالصوت" aria-pressed="false">
      <span class="material-symbols-outlined" aria-hidden="true">mic</span>
    </button>
    <button type="submit" class="hn-search__go">بحث</button>
    <div class="hn-suggest" id="se-suggest" role="listbox" aria-label="نتائج البحث" hidden data-hn-suggest></div>
  </form>
  <?php require __DIR__ . '/lab-recent-searches.php'; ?>
</section>
<?= portal_defer_script('/assets/hn-search.js') ?>
