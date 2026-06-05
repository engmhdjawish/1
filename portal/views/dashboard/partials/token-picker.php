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
        bool $allowDynamicOptions = false
    ): void {
        $selectedNormalized = array_values(array_unique(array_filter(array_map('strval', $selectedItems), static fn ($value) => trim($value) !== '')));
        ?>
        <div
          class="token-picker space-y-2"
          data-picker-id="<?= h($pickerId) ?>"
          data-input-name="<?= h($inputName) ?>"
          <?= $allowDynamicOptions ? 'data-allow-dynamic="1"' : '' ?>
        >
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
          <?php
            $selectedJson = json_encode($selectedNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            $selectedJson = str_replace('</', '<\/', $selectedJson);
          ?>
          <script type="application/json" data-role="selected-values"><?= $selectedJson ?></script>
        </div>
        <?php
    };
}

if (!function_exists('portal_render_token_picker_script')) {
    function portal_render_token_picker_script(): void
    {
        if (defined('PORTAL_TOKEN_PICKER_SCRIPT')) {
            return;
        }
        define('PORTAL_TOKEN_PICKER_SCRIPT', true);
        ?>
    <script>
    (() => {
      const normalize = (value) => (value || '').toString().trim();
      const pickerRegistry = new Map();

      const ensureOption = (pickerState, value, label) => {
        const normalized = normalize(value);
        if (normalized === '') return;
        const existing = pickerState.allOptions.find((item) => item.value === normalized);
        if (existing) {
          if (label && existing.label !== label) {
            existing.label = label;
            const optionEl = Array.from(pickerState.optionsSelect.options).find((o) => normalize(o.value) === normalized);
            if (optionEl) optionEl.textContent = label;
          }
          return;
        }
        const entry = { value: normalized, label: label || normalized };
        pickerState.allOptions.push(entry);
        const optionEl = document.createElement('option');
        optionEl.value = normalized;
        optionEl.textContent = entry.label;
        pickerState.optionsSelect.appendChild(optionEl);
      };

      const initPicker = (picker) => {
        if (picker.dataset.initialized === '1') {
          return;
        }
        picker.dataset.initialized = '1';

        const inputName = picker.dataset.inputName;
        const pickerId = picker.dataset.pickerId || '';
        const allowDynamic = picker.dataset.allowDynamic === '1';
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

        const state = {
          picker,
          pickerId,
          inputName,
          allowDynamic,
          searchInput,
          optionsSelect,
          chipsHost,
          hiddenHost,
          allOptions,
          selectedValues,
          renderOptions: null,
          renderSelected: null,
        };

        for (const value of selectedValues) {
          if (!state.allOptions.some((item) => item.value === value)) {
            const fromSelect = Array.from(optionsSelect.options).find((o) => normalize(o.value) === value);
            const label = fromSelect ? normalize(fromSelect.textContent) : value;
            ensureOption(state, value, label);
          }
        }

        const renderOptions = () => {
          const search = normalize(searchInput?.value || '').toLowerCase();
          for (const option of Array.from(optionsSelect.options)) {
            const text = normalize(option.textContent).toLowerCase();
            const value = normalize(option.value).toLowerCase();
            option.hidden = search !== '' && !text.includes(search) && !value.includes(search);
          }
        };
        state.renderOptions = renderOptions;

        const renderSelected = () => {
          chipsHost.innerHTML = '';
          hiddenHost.innerHTML = '';
          for (const value of selectedValues) {
            const option = state.allOptions.find((item) => item.value === value);
            const label = option ? option.label : value;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs';
            chip.textContent = label;
            chip.title = 'حذف';
            const remove = document.createElement('span');
            remove.className = 'text-slate-500';
            remove.textContent = '×';
            chip.appendChild(remove);
            chip.addEventListener('click', () => {
              selectedValues = selectedValues.filter((item) => item !== value);
              state.selectedValues = selectedValues;
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
        state.renderSelected = renderSelected;

        const addValues = (values, labelsByValue = {}) => {
          for (const value of values) {
            const normalized = normalize(value);
            if (normalized === '') continue;
            const label = labelsByValue[normalized] || '';
            if (allowDynamic) {
              ensureOption(state, normalized, label);
            } else if (!state.allOptions.some((option) => option.value === normalized)) {
              continue;
            }
            if (!selectedValues.includes(normalized)) {
              selectedValues.push(normalized);
            }
          }
          state.selectedValues = selectedValues;
          renderSelected();
        };

        addButton?.addEventListener('click', () => {
          addValues(Array.from(optionsSelect.selectedOptions).map((o) => o.value));
        });
        addAllButton?.addEventListener('click', () => addValues(state.allOptions.map((o) => o.value)));
        clearButton?.addEventListener('click', () => {
          selectedValues = [];
          state.selectedValues = selectedValues;
          renderSelected();
        });
        searchInput?.addEventListener('input', renderOptions);
        searchInput?.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter') return;
          event.preventDefault();
          event.stopPropagation();
          const visible = Array.from(optionsSelect.options).filter((option) => !option.hidden && normalize(option.value) !== '');
          if (visible.length === 1) {
            addValues([visible[0].value], { [normalize(visible[0].value)]: normalize(visible[0].textContent) });
            return;
          }
          const picked = Array.from(optionsSelect.selectedOptions).map((o) => o.value);
          if (picked.length > 0) {
            addValues(picked);
          }
        });
        optionsSelect?.addEventListener('dblclick', (event) => {
          const target = event.target;
          if (target && target.tagName === 'OPTION') {
            addValues([target.value], { [normalize(target.value)]: normalize(target.textContent) });
            return;
          }
          addValues(Array.from(optionsSelect.selectedOptions).map((o) => o.value));
        });

        if (pickerId !== '') {
          pickerRegistry.set(pickerId, state);
        }

        renderOptions();
        renderSelected();
      };

      const initAllPickers = () => {
        document.querySelectorAll('.token-picker').forEach((picker) => initPicker(picker));
      };

      window.portalTokenPickerAddOptions = (pickerId, items) => {
        const state = pickerRegistry.get(pickerId);
        if (!state || !Array.isArray(items)) return;
        const values = [];
        const labels = {};
        for (const item of items) {
          const value = normalize(item?.value ?? item?.guid ?? '');
          if (value === '') continue;
          const label = normalize(item?.label ?? item?.name ?? value);
          ensureOption(state, value, label);
          values.push(value);
          labels[value] = label;
        }
        for (const v of values) {
          const normalized = normalize(v);
          if (normalized === '') continue;
          if (!state.selectedValues.includes(normalized)) {
            state.selectedValues.push(normalized);
          }
        }
        state.renderSelected?.();
        state.renderOptions?.();
      };

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllPickers);
      } else {
        initAllPickers();
      }
    })();
    </script>
        <?php
    }
}
