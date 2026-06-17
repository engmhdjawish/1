/**
 * Token picker — multi-select chips with searchable option list.
 * Call portalTokenPickerInit(root) after AJAX page swaps.
 */
(function () {
  'use strict';

  const normalize = (value) => (value || '').toString().trim();
  const pickerRegistry = new Map();

  const readJsonScript = (picker, role, fallback) => {
    const node = picker.querySelector(`script[data-role="${role}"]`);
    if (!node) return fallback;
    try {
      const parsed = JSON.parse(node.textContent || '');
      return parsed ?? fallback;
    } catch (_) {
      return fallback;
    }
  };

  const ensureOption = (pickerState, value, label) => {
    const normalized = normalize(value);
    if (normalized === '') return;
    const existing = pickerState.allOptions.find((item) => item.value === normalized);
    if (existing) {
      if (label && existing.label !== label) {
        existing.label = label;
        if (pickerState.optionsSelect) {
          const optionEl = Array.from(pickerState.optionsSelect.options).find(
            (o) => normalize(o.value) === normalized
          );
          if (optionEl) optionEl.textContent = label;
        }
      }
      return;
    }
    const entry = { value: normalized, label: label || normalized };
    pickerState.allOptions.push(entry);
    if (pickerState.optionsSelect) {
      const optionEl = document.createElement('option');
      optionEl.value = normalized;
      optionEl.textContent = entry.label;
      pickerState.optionsSelect.appendChild(optionEl);
    }
  };

  const initPicker = (picker) => {
    if (picker.dataset.initialized === '1') {
      return;
    }
    picker.dataset.initialized = '1';

    const inputName = picker.dataset.inputName;
    const pickerId = picker.dataset.pickerId || '';
    const allowDynamic = picker.dataset.allowDynamic === '1';
    const chipsOnly = picker.dataset.chipsOnly === '1';
    const searchInput = picker.querySelector('[data-role="search"]');
    const optionsSelect = picker.querySelector('[data-role="options"]');
    const chipsHost = picker.querySelector('[data-role="chips"]');
    const hiddenHost = picker.querySelector('[data-role="hidden-inputs"]');
    const addButton = picker.querySelector('[data-action="add"]');
    const addAllButton = picker.querySelector('[data-action="add-all"]');
    const clearButton = picker.querySelector('[data-action="clear"]');

    const allOptions = optionsSelect
      ? Array.from(optionsSelect.options)
          .map((option) => ({
            value: normalize(option.value),
            label: normalize(option.textContent),
          }))
          .filter((option) => option.value !== '')
      : [];

    const presetLabels = readJsonScript(picker, 'option-labels', []);
    if (Array.isArray(presetLabels)) {
      for (const option of presetLabels) {
        if (!option || typeof option !== 'object') continue;
        ensureOption({ allOptions, optionsSelect }, option.value, option.label);
      }
    }

    let selectedValues = readJsonScript(picker, 'selected-values', []);
    if (!Array.isArray(selectedValues)) {
      selectedValues = [];
    }
    selectedValues = Array.from(
      new Set(selectedValues.map((value) => normalize(value)).filter((value) => value !== ''))
    );

    const state = {
      picker,
      pickerId,
      inputName,
      allowDynamic,
      chipsOnly,
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
        ensureOption(state, value, value);
      }
    }

    const renderOptions = () => {
      if (!optionsSelect) return;
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
        chip.title = 'حذف';
        chip.appendChild(document.createTextNode(label + ' '));
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
        if (allowDynamic || chipsOnly) {
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
      if (!optionsSelect) return;
      const visible = Array.from(optionsSelect.options).filter(
        (option) => !option.hidden && normalize(option.value) !== ''
      );
      if (visible.length === 1) {
        addValues([visible[0].value], {
          [normalize(visible[0].value)]: normalize(visible[0].textContent),
        });
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

  window.portalTokenPickerInit = (root = document) => {
    root.querySelectorAll('.token-picker').forEach((picker) => initPicker(picker));
  };

  window.portalTokenPickerGetSelected = (pickerId) => {
    const state = pickerRegistry.get(pickerId);
    return state ? [...state.selectedValues] : [];
  };

  window.portalTokenPickerAdd = (pickerId, items) => {
    const state = pickerRegistry.get(pickerId);
    if (!state || !Array.isArray(items)) return 0;
    let added = 0;
    for (const item of items) {
      const value = normalize(item?.value ?? item?.guid ?? '');
      if (value === '') continue;
      const label = normalize(item?.label ?? item?.name ?? value);
      ensureOption(state, value, label);
      if (!state.selectedValues.includes(value)) {
        state.selectedValues.push(value);
        added += 1;
      }
    }
    state.renderSelected?.();
    state.renderOptions?.();
    return added;
  };

  window.portalTokenPickerAddOptions = (pickerId, items) => {
    window.portalTokenPickerAdd(pickerId, items);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalTokenPickerInit(document));
  } else {
    window.portalTokenPickerInit(document);
  }
})();
