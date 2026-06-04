<?php

declare(strict_types=1);

if (!isset($renderTokenPicker)) {
    $renderTokenPicker = static function (
        string $title,
        string $inputName,
        array $optionItems,
        array $selectedItems,
        string $pickerId,
        bool $showAllButton = true
    ): void {
        $selectedNormalized = array_values(array_unique(array_filter(array_map('strval', $selectedItems), static fn ($value) => trim($value) !== '')));
        ?>
        <div class="token-picker space-y-2" data-picker-id="<?= h($pickerId) ?>" data-input-name="<?= h($inputName) ?>">
          <span class="text-text-muted block mb-1 text-sm"><?= h($title) ?></span>
          <div class="flex flex-wrap gap-2">
            <input type="text" data-role="search" class="h-10 min-w-[180px] flex-1 rounded-lg border border-border-subtle px-3 focus:border-primary focus:ring-primary" placeholder="ابحث ضمن الخيارات...">
            <button type="button" data-action="add" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">إضافة</button>
            <?php if ($showAllButton): ?>
              <button type="button" data-action="add-all" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">الكل</button>
            <?php endif; ?>
            <button type="button" data-action="clear" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">تفريغ</button>
          </div>
          <select data-role="options" multiple size="6" class="w-full rounded-lg border border-border-subtle px-3 py-2 text-sm focus:border-primary focus:ring-primary">
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
          <div data-role="chips" class="flex flex-wrap gap-2 min-h-[32px]"></div>
          <div data-role="hidden-inputs"></div>
          <script type="application/json" data-role="selected-values"><?= json_encode($selectedNormalized, JSON_UNESCAPED_UNICODE) ?></script>
        </div>
        <?php
    };
}

if (!defined('PORTAL_TOKEN_PICKER_SCRIPT')) {
    define('PORTAL_TOKEN_PICKER_SCRIPT', true);
    ?>
    <script>
    (() => {
      const normalize = (value) => (value || '').toString().trim();
      const initPicker = (picker) => {
        const inputName = picker.dataset.inputName;
        const searchInput = picker.querySelector('[data-role="search"]');
        const optionsSelect = picker.querySelector('[data-role="options"]');
        const chipsHost = picker.querySelector('[data-role="chips"]');
        const hiddenHost = picker.querySelector('[data-role="hidden-inputs"]');
        const selectedScript = picker.querySelector('script[data-role="selected-values"]');
        const addButton = picker.querySelector('[data-action="add"]');
        const addAllButton = picker.querySelector('[data-action="add-all"]');
        const clearButton = picker.querySelector('[data-action="clear"]');
        const allOptions = Array.from(optionsSelect.options).map((option) => ({
          value: normalize(option.value),
          label: normalize(option.textContent),
        })).filter((option) => option.value !== '');
        let selectedValues = [];
        try {
          const parsed = JSON.parse(selectedScript?.textContent || '[]');
          if (Array.isArray(parsed)) {
            selectedValues = parsed.map((value) => normalize(value)).filter((value) => value !== '');
          }
        } catch (_) {
          selectedValues = [];
        }
        selectedValues = Array.from(new Set(selectedValues));
        const renderOptions = () => {
          const search = normalize(searchInput?.value || '').toLowerCase();
          for (const option of Array.from(optionsSelect.options)) {
            const text = normalize(option.textContent).toLowerCase();
            const value = normalize(option.value).toLowerCase();
            option.hidden = search !== '' && !text.includes(search) && !value.includes(search);
          }
        };
        const renderSelected = () => {
          chipsHost.innerHTML = '';
          hiddenHost.innerHTML = '';
          for (const value of selectedValues) {
            const option = allOptions.find((item) => item.value === value);
            const label = option ? option.label : value;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs';
            chip.textContent = label + ' ×';
            chip.addEventListener('click', () => {
              selectedValues = selectedValues.filter((item) => item !== value);
              renderSelected();
            });
            chipsHost.appendChild(chip);
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = inputName;
            hiddenInput.value = value;
            hiddenHost.appendChild(hiddenInput);
          }
        };
        const addValues = (values) => {
          for (const value of values) {
            const normalized = normalize(value);
            if (normalized === '' || !allOptions.some((option) => option.value === normalized)) continue;
            if (!selectedValues.includes(normalized)) selectedValues.push(normalized);
          }
          renderSelected();
        };
        addButton?.addEventListener('click', () => addValues(Array.from(optionsSelect.selectedOptions).map((o) => o.value)));
        addAllButton?.addEventListener('click', () => addValues(allOptions.map((o) => o.value)));
        clearButton?.addEventListener('click', () => { selectedValues = []; renderSelected(); });
        searchInput?.addEventListener('input', renderOptions);
        optionsSelect?.addEventListener('dblclick', () => addValues(Array.from(optionsSelect.selectedOptions).map((o) => o.value)));
        renderOptions();
        renderSelected();
      };
      document.querySelectorAll('.token-picker').forEach((picker) => initPicker(picker));
    })();
    </script>
    <?php
}
