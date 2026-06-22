<?php

declare(strict_types=1);

use Portal\Support\Text;

/**
 * @param string $paramName
 * @param string $title
 * @param list<array{value?: string, label?: string, count?: int|null, guid?: string, name?: string, code?: string}> $options
 * @param list<string> $selectedValues
 * @param string $groupId
 * @param int $searchThreshold
 * @param int $initialVisible
 */
$renderStoreFilterGroup = static function (
    string $paramName,
    string $title,
    array $options,
    array $selectedValues,
    string $groupId,
    int $searchThreshold = 8,
    int $initialVisible = 6
): void {
    $normalized = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $value = trim((string) ($option['value'] ?? $option['guid'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = trim((string) ($option['label'] ?? $option['name'] ?? ''));
        if ($label === '') {
            $code = trim((string) ($option['code'] ?? ''));
            $label = $code !== '' ? $code : $value;
        }
        $normalized[] = [
            'value' => $value,
            'label' => $label,
            'count' => $option['count'] ?? null,
        ];
    }
    if ($normalized === []) {
        return;
    }

    $total = count($normalized);
    $selectedMap = array_flip($selectedValues);
    $hasSelection = false;
    foreach ($normalized as $item) {
        if (isset($selectedMap[$item['value']])) {
            $hasSelection = true;
            break;
        }
    }
    $searchable = $total >= $searchThreshold;
    $collapsible = $total > $initialVisible;
    ?>
    <details class="store-filter-accordion" <?= $hasSelection ? 'open' : '' ?> data-filter-group="<?= h($groupId) ?>">
      <summary class="store-filter-accordion-summary">
        <span><?= h($title) ?></span>
        <?php if ($hasSelection): ?>
          <span class="store-filter-accordion-badge"><?= count(array_intersect(array_column($normalized, 'value'), $selectedValues)) ?></span>
        <?php endif; ?>
      </summary>
      <div class="store-filter-accordion-body">
        <?php if ($searchable): ?>
          <input
            type="search"
            class="store-filter-search"
            placeholder="ابحث في <?= h($title) ?>..."
            data-filter-search="<?= h($groupId) ?>"
            autocomplete="off"
          >
        <?php endif; ?>
        <div class="store-filter-options" data-filter-list="<?= h($groupId) ?>" data-initial-visible="<?= (int) $initialVisible ?>">
          <?php foreach ($normalized as $index => $item): ?>
            <?php
              $isChecked = in_array($item['value'], $selectedValues, true);
              $isHidden = $collapsible && $index >= $initialVisible && !$isChecked;
            ?>
            <label
              class="store-filter-option<?= $isHidden ? ' is-collapsed' : '' ?>"
              data-filter-label="<?= h(Text::lower($item['label'])) ?>"
            >
              <input
                type="checkbox"
                name="<?= h($paramName) ?>[]"
                value="<?= h($item['value']) ?>"
                <?= $isChecked ? 'checked' : '' ?>
              >
              <span class="store-filter-option-text"><?= h($item['label']) ?></span>
              <?php if ($item['count'] !== null): ?>
                <span class="store-filter-option-count"><?= (int) $item['count'] ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($collapsible): ?>
          <button type="button" class="store-filter-toggle-more" data-filter-toggle="<?= h($groupId) ?>">
            عرض المزيد
          </button>
        <?php endif; ?>
      </div>
    </details>
    <?php
};
