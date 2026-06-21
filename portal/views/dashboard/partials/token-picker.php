<?php

declare(strict_types=1);

if (!isset($renderTokenPicker)) {
    $renderTokenPicker = static function (
        string $title,
        string $inputName,
        array $optionItems,
        array $selectedItems,
        string $pickerId,
        bool $showAllButton = true,
        bool $allowDynamicOptions = false,
        bool $chipsOnly = false,
        int $optionsSize = 6
    ): void {
        $optionsSize = max(3, min(12, $optionsSize));
        $selectedNormalized = array_values(array_unique(array_filter(array_map('strval', $selectedItems), static fn ($value) => trim($value) !== '')));
        $optionLabels = [];
        foreach ($optionItems as $option) {
            $value = trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $optionLabels[] = [
                'value' => $value,
                'label' => trim((string) ($option['label'] ?? $value)),
            ];
        }
        $optionLabelsJson = json_encode($optionLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $optionLabelsJson = str_replace('</', '<\/', $optionLabelsJson);
        ?>
        <div
          class="token-picker space-y-2<?= $chipsOnly ? ' token-picker-chips-only' : '' ?>"
          data-picker-id="<?= h($pickerId) ?>"
          data-input-name="<?= h($inputName) ?>"
          <?= $allowDynamicOptions ? 'data-allow-dynamic="1"' : '' ?>
          <?= $chipsOnly ? 'data-chips-only="1"' : '' ?>
        >
          <span class="text-text-muted block mb-1 text-sm"><?= h($title) ?></span>
          <?php if (!$chipsOnly): ?>
          <div class="flex flex-wrap gap-2">
            <input type="text" data-role="search" class="h-10 min-w-[180px] flex-1 rounded-lg border border-border-subtle px-3 focus:border-primary focus:ring-primary" placeholder="ابحث ضمن الخيارات...">
            <button type="button" data-action="add" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">إضافة</button>
            <?php if ($showAllButton): ?>
              <button type="button" data-action="add-all" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">الكل</button>
            <?php endif; ?>
            <button type="button" data-action="clear" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">تفريغ</button>
          </div>
          <select data-role="options" multiple size="<?= (int) $optionsSize ?>" class="w-full rounded-lg border border-border-subtle px-3 py-1.5 text-sm focus:border-primary focus:ring-primary">
            <?php foreach ($optionItems as $option): ?>
              <?php
                $value = trim((string) ($option['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $label = trim((string) ($option['label'] ?? $value));
              ?>
              <option value="<?= h($value) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <div data-role="chips" class="flex flex-wrap gap-2 min-h-[32px]"></div>
          <div data-role="hidden-inputs"></div>
          <?php
            $selectedJson = json_encode($selectedNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            $selectedJson = str_replace('</', '<\/', $selectedJson);
          ?>
          <script type="application/json" data-role="option-labels"><?= $optionLabelsJson ?></script>
          <script type="application/json" data-role="selected-values"><?= $selectedJson ?></script>
        </div>
        <?php
    };
}

if (!function_exists('portal_render_token_picker_script')) {
    function portal_render_token_picker_script(): void
    {
        // Loaded globally from /assets/dashboard/token-picker.js
    }
}
