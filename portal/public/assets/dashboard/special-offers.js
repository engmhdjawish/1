/**
 * Special offers editor — selection mode toggle, discount fields, manual material search.
 */
(function () {
  'use strict';

  const boundRoots = new WeakSet();

  window.portalSpecialOffersInit = (root = document) => {
    const form = root.querySelector('#special-offer-form');
    if (!form || boundRoots.has(form)) {
      return;
    }
    boundRoots.add(form);

    const modeSelect = root.querySelector('#selection_mode');
    const filterPanel = root.querySelector('#filter-mode-panel');
    const manualPanel = root.querySelector('#manual-mode-panel');
    const pricingScope = root.querySelector('#pricing_scope');
    const perMaterialPanel = root.querySelector('#per-material-pricing-panel');
    const perMaterialRows = root.querySelector('#per-material-pricing-rows');

    const readJson = (id, fallback) => {
      const node = root.querySelector(id);
      if (!node) return fallback;
      try {
        return JSON.parse(node.textContent || '') ?? fallback;
      } catch (_) {
        return fallback;
      }
    };

    let overridesState = readJson('#so-product-overrides-json', {});
    if (!overridesState || typeof overridesState !== 'object') overridesState = {};
    const labelMap = readJson('#so-manual-product-labels-json', {});

    const syncPanels = () => {
      if (pricingScope?.value === 'per_material' && modeSelect && modeSelect.value !== 'manual') {
        modeSelect.value = 'manual';
      }
      const isManual = modeSelect?.value === 'manual';
      if (!isManual && pricingScope?.value === 'per_material') {
        pricingScope.value = 'offer';
      }
      filterPanel?.classList.toggle('hidden', isManual);
      manualPanel?.classList.toggle('hidden', !isManual);
      const perMaterial = pricingScope?.value === 'per_material' && isManual;
      root.querySelector('#selection-mode-article')?.classList.toggle('hidden', perMaterial);
      root.querySelector('#manual-mode-heading')?.classList.toggle('hidden', perMaterial);
      root.querySelector('[data-picker-id="so-manual-materials"]')?.classList.toggle('hidden', perMaterial);
      root.querySelectorAll('.so-unified-discount').forEach((el) => {
        if (perMaterial) {
          el.classList.add('hidden');
        }
      });
      if (perMaterial) {
        ['#field-percent', '#field-amount-syp', '#field-amount-usd', '#field-syp', '#field-usd', '#offer-discount-type-wrap'].forEach((sel) => {
          root.querySelector(sel)?.classList.add('hidden');
        });
      } else {
        syncDiscount();
      }
      syncPricingScope();
    };
    modeSelect?.addEventListener('change', syncPanels);

    const discountType = root.querySelector('#discount_type');
    const syncDiscount = () => {
      const type = discountType?.value || 'percent';
      root.querySelector('#field-percent')?.classList.toggle('hidden', type !== 'percent');
      root.querySelector('#field-amount-syp')?.classList.toggle('hidden', type !== 'fixed_amount');
      root.querySelector('#field-amount-usd')?.classList.toggle('hidden', type !== 'fixed_amount');
      root.querySelector('#field-syp')?.classList.toggle('hidden', type !== 'fixed_price');
      root.querySelector('#field-usd')?.classList.toggle('hidden', type !== 'fixed_price');
    };
    discountType?.addEventListener('change', syncDiscount);
    syncDiscount();

    const captureOverrideInputs = () => {
      if (!perMaterialRows) return;
      perMaterialRows.querySelectorAll('[data-override-guid]').forEach((row) => {
        const guid = row.getAttribute('data-override-guid');
        if (!guid) return;
        const type = row.querySelector('[data-field="discount_type"]')?.value || 'percent';
        overridesState[guid] = {
          discount_type: type,
          discount_percent: row.querySelector('[data-field="discount_percent"]')?.value || '',
          fixed_price_syp: row.querySelector('[data-field="fixed_price_syp"]')?.value || '',
          fixed_price_usd: row.querySelector('[data-field="fixed_price_usd"]')?.value || '',
          fixed_amount_syp: row.querySelector('[data-field="fixed_amount_syp"]')?.value || '',
          fixed_amount_usd: row.querySelector('[data-field="fixed_amount_usd"]')?.value || '',
        };
      });
    };

    const renderOverrideRow = (guid, label) => {
      const current = overridesState[guid] || {};
      const type = ['percent', 'fixed_amount', 'fixed_price'].includes(current.discount_type)
        ? current.discount_type
        : 'percent';
      const tr = document.createElement('tr');
      tr.className = 'border-t border-border-subtle';
      tr.setAttribute('data-override-guid', guid);

      const materialCell = document.createElement('td');
      materialCell.className = 'px-3 py-2 font-bold align-middle';
      materialCell.textContent = label || guid;
      tr.appendChild(materialCell);

      const typeCell = document.createElement('td');
      typeCell.className = 'px-3 py-2 align-middle';
      const typeSelect = document.createElement('select');
      typeSelect.name = `material_override[${guid}][discount_type]`;
      typeSelect.setAttribute('data-field', 'discount_type');
      typeSelect.className = 'h-8 w-full rounded-lg border border-border-subtle px-2 text-xs bg-white';
      [
        ['percent', 'نسبة %'],
        ['fixed_amount', 'مبلغ مقطوع'],
        ['fixed_price', 'سعر طرد جديد'],
      ].forEach(([value, text]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;
        option.selected = type === value;
        typeSelect.appendChild(option);
      });
      typeCell.appendChild(typeSelect);
      tr.appendChild(typeCell);

      const valueCell = document.createElement('td');
      valueCell.className = 'px-3 py-2 align-middle';
      const valueWrap = document.createElement('div');
      valueWrap.className = 'flex flex-wrap gap-2';
      const fields = [
        ['percent', 'discount_percent', '%', '100', current.discount_percent],
        ['fixed_amount', 'fixed_amount_syp', 'ل.س', '', current.fixed_amount_syp],
        ['fixed_amount', 'fixed_amount_usd', '$', '', current.fixed_amount_usd],
        ['fixed_price', 'fixed_price_syp', 'سعر ل.س', '', current.fixed_price_syp],
        ['fixed_price', 'fixed_price_usd', 'سعر $', '', current.fixed_price_usd],
      ];
      fields.forEach(([showFor, field, caption, max, value]) => {
        const box = document.createElement('label');
        box.className = 'inline-flex items-center gap-1 text-[11px]' + (showFor === type ? '' : ' hidden');
        box.setAttribute('data-show-for', showFor);
        const captionEl = document.createElement('span');
        captionEl.className = 'text-text-muted whitespace-nowrap';
        captionEl.textContent = caption;
        const input = document.createElement('input');
        input.type = 'number';
        input.step = '0.01';
        input.min = '0';
        input.required = true;
        if (max !== '') input.max = max;
        input.name = `material_override[${guid}][${field}]`;
        input.setAttribute('data-field', field);
        input.value = value ?? '';
        input.className = 'h-8 w-24 rounded-lg border border-border-subtle px-2 text-xs bg-white';
        box.appendChild(captionEl);
        box.appendChild(input);
        valueWrap.appendChild(box);
      });
      valueCell.appendChild(valueWrap);
      tr.appendChild(valueCell);

      const actionCell = document.createElement('td');
      actionCell.className = 'px-3 py-2 align-middle text-left';
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'h-8 px-2 rounded-lg border border-red-200 text-red-700 text-xs font-bold';
      removeBtn.textContent = 'حذف';
      removeBtn.addEventListener('click', () => {
        tr.remove();
        delete overridesState[guid];
        if (typeof window.portalTokenPickerRemove === 'function') {
          window.portalTokenPickerRemove('so-manual-materials', guid);
        }
        syncPerMaterialRows();
      });
      actionCell.appendChild(removeBtn);
      tr.appendChild(actionCell);

      const syncRowFields = () => {
        const selected = typeSelect.value || 'percent';
        tr.querySelectorAll('[data-show-for]').forEach((el) => {
          const visible = el.getAttribute('data-show-for') === selected;
          el.classList.toggle('hidden', !visible);
          el.querySelector('input')?.toggleAttribute('required', visible);
        });
      };
      typeSelect.addEventListener('change', syncRowFields);
      syncRowFields();
      return tr;
    };

    const syncPerMaterialRows = () => {
      if (!perMaterialRows || !perMaterialPanel) return;
      captureOverrideInputs();
      const selected = typeof window.portalTokenPickerGetSelected === 'function'
        ? window.portalTokenPickerGetSelected('so-manual-materials')
        : [];
      perMaterialRows.innerHTML = '';
      if (!selected.length) {
        const empty = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 4;
        cell.className = 'px-3 py-4 text-xs text-text-muted text-center';
        cell.textContent = 'أضف مادة واحدة على الأقل من البحث أعلاه.';
        empty.appendChild(cell);
        perMaterialRows.appendChild(empty);
        return;
      }
      selected.forEach((guid) => {
        const meta = labelMap[guid] || {};
        const label = [meta.code, meta.name].filter(Boolean).join(' — ') || guid;
        perMaterialRows.appendChild(renderOverrideRow(guid, label));
      });
    };

    const syncPricingScope = () => {
      const isPerMaterial = pricingScope?.value === 'per_material' && modeSelect?.value === 'manual';
      perMaterialPanel?.classList.toggle('hidden', !isPerMaterial);
      if (isPerMaterial) {
        syncPerMaterialRows();
      }
    };
    pricingScope?.addEventListener('change', () => {
      if (pricingScope.value === 'per_material' && modeSelect && modeSelect.value !== 'manual') {
        modeSelect.value = 'manual';
      }
      syncPanels();
    });

    // Keep per-material rows in sync when token chips change.
    const picker = root.querySelector('[data-picker-id="so-manual-materials"]');
    const hiddenHost = picker?.querySelector('[data-role="hidden-inputs"]');
    if (hiddenHost && typeof MutationObserver !== 'undefined') {
      const observer = new MutationObserver(() => {
        if (pricingScope?.value === 'per_material') {
          syncPerMaterialRows();
        }
      });
      observer.observe(hiddenHost, { childList: true });
    }

    syncPanels();
    syncPricingScope();

    form.addEventListener('submit', (event) => {
      if (pricingScope?.value !== 'per_material') return;
      const selected = typeof window.portalTokenPickerGetSelected === 'function'
        ? window.portalTokenPickerGetSelected('so-manual-materials')
        : [];
      if (selected.length > 0) return;
      event.preventDefault();
      const status = root.querySelector('#so-material-search-status');
      if (status) status.textContent = 'سعر لكل مادة يتطلب اختيار مادة واحدة على الأقل في الجدول.';
    });

    const searchInput = root.querySelector('#so-material-search');
    const resultsEl = root.querySelector('#so-material-search-results');
    const searchWrap = root.querySelector('#so-material-search-wrap');
    const statusEl = root.querySelector('#so-material-search-status');
    if (!searchInput) {
      return;
    }

    const MANUAL_PICKER_ID = 'so-manual-materials';
    const PAGE_SIZE = 24;

    let searchItems = [];
    let activeResultIndex = -1;
    let searchPage = 1;
    let searchTotal = 0;
    let searchHasMore = false;
    let searchLoading = false;
    let searchTimer = null;
    let searchRequestId = 0;

    const getSelectedMaterialIds = () => {
      if (typeof window.portalTokenPickerGetSelected === 'function') {
        return new Set(window.portalTokenPickerGetSelected(MANUAL_PICKER_ID));
      }
      return new Set();
    };

    const hideResults = () => {
      if (!resultsEl) return;
      resultsEl.classList.add('hidden');
      resultsEl.innerHTML = '';
      searchInput.setAttribute('aria-expanded', 'false');
      activeResultIndex = -1;
      searchItems = [];
      searchPage = 1;
      searchTotal = 0;
      searchHasMore = false;
      searchLoading = false;
    };

    const updateStatus = () => {
      if (!statusEl) return;
      if (searchItems.length === 0) {
        statusEl.textContent = 'لا توجد نتائج.';
        return;
      }
      const shown = searchItems.length;
      const totalText = searchTotal > 0 ? ' من ' + searchTotal : '';
      statusEl.textContent = shown + totalText + ' — اختر من القائمة (↑↓ Enter) — مرّر للأسفل للمزيد';
    };

    const highlightResult = () => {
      if (!resultsEl) return;
      const activeNode = resultsEl.querySelector('[data-result-index="' + activeResultIndex + '"]');
      Array.from(resultsEl.querySelectorAll('[data-result-index]')).forEach((node) => {
        const index = Number(node.getAttribute('data-result-index'));
        node.classList.toggle('bg-primary/10', index === activeResultIndex);
        node.classList.toggle('font-bold', index === activeResultIndex);
      });
      activeNode?.scrollIntoView({ block: 'nearest' });
    };

    const appendResultRow = (item, index) => {
      if (!resultsEl || !item) return;
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('data-result-index', String(index));
      li.className = 'px-3 py-2.5 cursor-pointer hover:bg-surface-low text-right';
      li.textContent = item.label || item.value || '';
      li.addEventListener('mousedown', (event) => {
        event.preventDefault();
        addMaterialItem(item);
      });
      resultsEl.appendChild(li);
    };

    const addMaterialItem = (item) => {
      if (!item || typeof window.portalTokenPickerAdd !== 'function') return;
      const added = window.portalTokenPickerAdd(MANUAL_PICKER_ID, [item]);
      if (added > 0 && statusEl) {
        statusEl.textContent = 'تمت إضافة: ' + (item.label || item.value);
      }
      if (item.value) {
        labelMap[item.value] = {
          guid: item.value,
          name: item.label || item.value,
          code: item.code || '',
        };
      }
      if (pricingScope?.value === 'per_material') {
        syncPerMaterialRows();
        perMaterialRows?.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
      searchInput.value = '';
      hideResults();
    };

    const renderResultList = (items, append) => {
      if (!resultsEl) return;
      if (!append) {
        resultsEl.innerHTML = '';
        searchItems = [];
      }
      const selected = getSelectedMaterialIds();
      items.forEach((item) => {
        if (!item || !item.value || selected.has(item.value)) return;
        if (searchItems.some((row) => row.value === item.value)) return;
        searchItems.push(item);
        appendResultRow(item, searchItems.length - 1);
      });

      if (searchItems.length === 0) {
        hideResults();
        if (statusEl) statusEl.textContent = 'لا توجد نتائج جديدة.';
        return;
      }

      resultsEl.classList.remove('hidden');
      searchInput.setAttribute('aria-expanded', 'true');
      if (activeResultIndex < 0) activeResultIndex = 0;
      highlightResult();
      updateStatus();

      const sentinel = resultsEl.querySelector('[data-role="load-sentinel"]');
      if (sentinel) sentinel.remove();
      if (searchHasMore) {
        const loadingRow = document.createElement('li');
        loadingRow.setAttribute('data-role', 'load-sentinel');
        loadingRow.className = 'px-3 py-2 text-center text-xs text-text-muted';
        loadingRow.textContent = searchLoading ? 'جاري تحميل المزيد...' : 'مرّر للأسفل للمزيد';
        resultsEl.appendChild(loadingRow);
      }
    };

    const fetchMaterialPage = async (page, append) => {
      const q = (searchInput.value || '').trim();
      if (q === '') {
        hideResults();
        if (statusEl) statusEl.textContent = '';
        return;
      }
      if (searchLoading) return;

      const requestId = ++searchRequestId;
      searchLoading = true;
      if (!append && statusEl) statusEl.textContent = 'جاري البحث...';

      try {
        const url =
          '/dashboard/home-sections-api.php?q=' +
          encodeURIComponent(q) +
          '&page=' +
          encodeURIComponent(String(page)) +
          '&pageSize=' +
          encodeURIComponent(String(PAGE_SIZE));
        const response = await fetch(url, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (requestId !== searchRequestId) return;
        const data = await response.json();
        if (!data.ok) {
          if (!append) hideResults();
          if (statusEl) statusEl.textContent = data.message || 'تعذر البحث.';
          return;
        }

        searchPage = Number(data.page) || page;
        searchTotal = Number(data.total) || 0;
        searchHasMore = !!data.hasMore;
        const items = Array.isArray(data.items) ? data.items : [];
        renderResultList(items, append);
      } catch (_) {
        if (requestId !== searchRequestId) return;
        if (!append) hideResults();
        if (statusEl) statusEl.textContent = 'تعذر الاتصال بالخادم.';
      } finally {
        if (requestId === searchRequestId) {
          searchLoading = false;
        }
      }
    };

    const runMaterialSearch = (append = false) => {
      if (append) {
        if (!searchHasMore || searchLoading) return;
        fetchMaterialPage(searchPage + 1, true);
        return;
      }
      searchPage = 1;
      searchHasMore = false;
      searchTotal = 0;
      fetchMaterialPage(1, false);
    };

    const scheduleMaterialSearch = (delayMs = 280) => {
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        searchTimer = null;
        runMaterialSearch(false);
      }, delayMs);
    };

    searchInput.addEventListener('input', () => {
      const q = (searchInput.value || '').trim();
      if (q === '') {
        if (searchTimer) clearTimeout(searchTimer);
        hideResults();
        if (statusEl) statusEl.textContent = '';
        return;
      }
      scheduleMaterialSearch();
    });

    resultsEl?.addEventListener('scroll', () => {
      if (!searchHasMore || searchLoading) return;
      const nearBottom = resultsEl.scrollTop + resultsEl.clientHeight >= resultsEl.scrollHeight - 48;
      if (nearBottom) {
        runMaterialSearch(true);
      }
    });

    searchInput.addEventListener('keydown', (event) => {
      const hasList = resultsEl && !resultsEl.classList.contains('hidden') && searchItems.length > 0;
      if (event.key === 'ArrowDown' && hasList) {
        event.preventDefault();
        activeResultIndex = Math.min(activeResultIndex + 1, searchItems.length - 1);
        highlightResult();
        return;
      }
      if (event.key === 'ArrowUp' && hasList) {
        event.preventDefault();
        activeResultIndex = Math.max(activeResultIndex - 1, 0);
        highlightResult();
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        event.stopPropagation();
        if (searchTimer) clearTimeout(searchTimer);
        if (hasList && activeResultIndex >= 0 && searchItems[activeResultIndex]) {
          addMaterialItem(searchItems[activeResultIndex]);
          return;
        }
        runMaterialSearch(false);
        return;
      }
      if (event.key === 'Escape') {
        hideResults();
      }
    });

    document.addEventListener('click', (event) => {
      if (!searchWrap || searchWrap.contains(event.target)) return;
      hideResults();
    });
  };
})();
