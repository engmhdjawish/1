<?php

declare(strict_types=1);

/**
 * @param string $paramName
 * @param list<array{value?: string, label?: string, count?: int|null, guid?: string, name?: string, code?: string}> $options
 * @param list<string> $selectedValues
 */
$renderStoreFilterChips = static function (string $paramName, array $options, array $selectedValues): void {
    if ($options === []) {
        return;
    }

    echo '<div class="flex flex-wrap gap-2 mt-2">';
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
        $count = $option['count'] ?? null;
        $isChecked = in_array($value, $selectedValues, true);
        echo '<label class="cursor-pointer">';
        echo '<input type="checkbox" class="peer sr-only" name="' . h($paramName) . '[]" value="' . h($value) . '"' . ($isChecked ? ' checked' : '') . '>';
        echo '<span class="store-filter-chip">';
        echo h($label);
        if ($count !== null) {
            echo '<span class="store-filter-count">(' . (int) $count . ')</span>';
        }
        echo '</span></label>';
    }
    echo '</div>';
};
