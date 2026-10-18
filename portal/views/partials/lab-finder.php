<?php

declare(strict_types=1);

$finderFields = [];
foreach ([
    'ageCategories' => 'الفئة',
    'materialTypes' => 'النوع',
    'sizeRanges' => 'القياس',
    'manufacturers' => 'الشركة',
] as $param => $label) {
    $values = store_easy_facet_values($param) ?? [];
    if (count($values) >= 2) {
        $finderFields[$param] = ['label' => $label, 'values' => $values];
    }
}
$finderAvailability = store_easy_client_filter_visible('availability');

$finderShortcuts = [];
if (isset($finderFields['sizeRanges']) && count($finderFields['sizeRanges']['values']) <= 30) {
    $finderShortcuts[] = ['param' => 'sizeRanges', 'label' => 'القياس', 'icon' => 'straighten', 'values' => $finderFields['sizeRanges']['values'], 'ltr' => true];
}
if (isset($finderFields['manufacturers'])) {
    $finderShortcuts[] = ['param' => 'manufacturers', 'label' => 'الشركة', 'icon' => 'factory', 'values' => array_slice($finderFields['manufacturers']['values'], 0, 12), 'ltr' => false];
}

if ($finderFields === [] && $finderShortcuts === []) {
    return;
}
?>
<section class="lab-finder" aria-labelledby="lab-finder-title">
  <?php if ($finderFields !== []): ?>
    <form class="lab-finder__form" action="/store-lab.php" method="get">
      <h2 id="lab-finder-title" class="lab-finder__title">
        <span class="material-symbols-outlined" aria-hidden="true">tune</span>
        اعثر بسرعة
      </h2>
      <div class="lab-finder__fields">
        <?php foreach ($finderFields as $param => $field): ?>
          <label class="lab-finder__field">
            <span><?= h($field['label']) ?></span>
            <select name="<?= h($param) ?>[]">
              <option value="">الكل</option>
              <?php foreach ($field['values'] as $value): ?>
                <option value="<?= h($value) ?>"><?= h($value) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="lab-finder__actions">
        <?php if ($finderAvailability): ?>
          <label class="lab-finder__check">
            <input type="checkbox" name="isAvailable" value="1">
            <span>المتوفر فقط</span>
          </label>
        <?php endif; ?>
        <button type="submit" class="lab-finder__submit">
          عرض المواد
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        </button>
      </div>
    </form>
  <?php else: ?>
    <h2 id="lab-finder-title" class="sr-only">اعثر بسرعة</h2>
  <?php endif; ?>

  <?php foreach ($finderShortcuts as $group): ?>
    <div class="lab-finder__group">
      <span class="lab-finder__group-label">
        <span class="material-symbols-outlined" aria-hidden="true"><?= h($group['icon']) ?></span>
        <?= h($group['label']) ?>
      </span>
      <div class="lab-finder__chips">
        <?php foreach ($group['values'] as $value): ?>
          <a
            href="<?= h(store_lab_url([$group['param'] => [$value]])) ?>"
            class="lab-finder__chip"
            <?= $group['ltr'] ? 'dir="ltr"' : '' ?>
          ><?= h($value) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
