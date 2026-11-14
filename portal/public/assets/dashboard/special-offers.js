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
    // Normalize override keys to lowercase for GUID matching.
    const normalizedOverrides = {};
    Object.keys(overridesState).forEach((key) => {
      normalizedOverrides[String(key).toLowerCase()] = overridesState[key];
    });
    overridesState = normalizedOverrides;

    const labelMapRaw = readJson('#so-manual-product-labels-json', {});
    const labelMap = {};
    Object.keys(labelMapRaw || {}).forEach((key) => {
      const lower = String(key).toLowerCase();
      const meta = labelMapRaw[key] || {};
      labelMap[lower] = { ...meta, guid: lower };
    });
    const seedGuids = (readJson('#so-manual-material-guids-json', []) || [])
      .map((g) => String(g).toLowerCase())
      .filter(Boolean);

    const roundUpTo5 = (amount) => {
      if (!(amount > 0)) return 0;
      return Math.ceil(amount / 5) * 5;
    };
    const parseLoose = (value) => {
      const text = String(value ?? '').trim().replace(',', '.');
      if (text === '' || text === '.' || text === '-') return null;
      const n = Number(text);
      return Number.isFinite(n) ? n : null;
    };
    const formatUsd = (n) => (Math.round(n * 100) / 100).toFixed(2);
    const formatSyp = (n) => Math.round(n).toLocaleString('en-US');
    const inferRate = (syp, usd, currencyVal) => {
      if (currencyVal !== null && currencyVal > 1) return currencyVal;
      if (usd !== null && usd > 0 && syp !== null && syp > 0) return syp / usd;
      return 0;
    };

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
        const guid = String(row.getAttribute('data-override-guid') || '').toLowerCase();
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

    const ensureSeededSelection = () => {
      const fromPicker = typeof window.portalTokenPickerGetSelected === 'function'
        ? window.portalTokenPickerGetSelected('so-manual-materials').map((g) => String(g).toLowerCase())
        : [];
      let selected = fromPicker.filter(Boolean);
      if (selected.length === 0) {
        selected = seedGuids.slice();
        if (selected.length === 0) {
          selected = Object.keys(overridesState);
        }
        if (selected.length > 0 && typeof window.portalTokenPickerAdd === 'function') {
          const items = selected.map((guid) => {
            const meta = labelMap[guid] || {};
            return {
              value: guid,
              label: meta.name || meta.label || guid,
              name: meta.name || meta.label || guid,
              code: meta.code || '',
              image: meta.image || '',
            };
          });
          window.portalTokenPickerAdd('so-manual-materials', items);
          selected = typeof window.portalTokenPickerGetSelected === 'function'
            ? window.portalTokenPickerGetSelected('so-manual-materials').map((g) => String(g).toLowerCase())
            : selected;
        }
      }
      return Array.from(new Set(selected.filter(Boolean)));
    };

    const renderOverrideRow = (guid, meta, index) => {
      guid = String(guid).toLowerCase();
      const current = overridesState[guid] || {};
      const type = ['percent', 'fixed_amount', 'fixed_price'].includes(current.discount_type)
        ? current.discount_type
        : 'percent';
      const name = meta.name || meta.label || guid;
      const code = meta.code || '';
      const saleUsd = parseLoose(meta.unitSalePriceUsd);
      const saleSyp = parseLoose(meta.unitSalePriceSyp);
      const purchaseUsd = parseLoose(meta.unitPurchasePriceUsd);
      const currencyVal = parseLoose(meta.currencyVal);
      const rate = inferRate(saleSyp, saleUsd, currencyVal);

      const tr = document.createElement('tr');
      tr.className = 'so-invoice__row';
      tr.setAttribute('data-override-guid', guid);
      tr.setAttribute('data-sale-usd', saleUsd !== null ? String(saleUsd) : '');
      tr.setAttribute('data-purchase-usd', purchaseUsd !== null ? String(purchaseUsd) : '');
      tr.setAttribute('data-rate', rate > 0 ? String(rate) : '');

      const numCell = document.createElement('td');
      numCell.className = 'so-invoice__num';
      numCell.textContent = String(index + 1);
      tr.appendChild(numCell);

      const materialCell = document.createElement('td');
      materialCell.className = 'so-invoice__material';
      const material = document.createElement('div');
      material.className = 'so-invoice__material-inner';
      const thumbWrap = document.createElement('span');
      thumbWrap.className = 'so-invoice__thumb';
      if (meta.image) {
        const img = document.createElement('img');
        img.src = meta.image;
        img.alt = '';
        img.loading = 'lazy';
        thumbWrap.appendChild(img);
      } else {
        thumbWrap.classList.add('is-empty');
        thumbWrap.textContent = 'صورة';
      }
      const textWrap = document.createElement('span');
      textWrap.className = 'so-invoice__material-text';
      const nameEl = document.createElement('span');
      nameEl.className = 'so-invoice__name';
      nameEl.textContent = name;
      textWrap.appendChild(nameEl);
      if (code) {
        const codeEl = document.createElement('span');
        codeEl.className = 'so-invoice__code';
        codeEl.textContent = code;
        textWrap.appendChild(codeEl);
      }
      material.append(thumbWrap, textWrap);
      materialCell.appendChild(material);
      tr.appendChild(materialCell);

      const saleCell = document.createElement('td');
      saleCell.className = 'tabular-nums font-bold';
      saleCell.dir = 'ltr';
      saleCell.textContent = saleUsd !== null ? formatUsd(saleUsd) : '—';
      tr.appendChild(saleCell);

      const purchaseCell = document.createElement('td');
      purchaseCell.className = 'tabular-nums text-slate-600';
      purchaseCell.dir = 'ltr';
      purchaseCell.textContent = purchaseUsd !== null ? formatUsd(purchaseUsd) : '—';
      tr.appendChild(purchaseCell);

      const typeCell = document.createElement('td');
      typeCell.className = 'so-invoice__type';
      const typeSelect = document.createElement('select');
      typeSelect.name = `material_override[${guid}][discount_type]`;
      typeSelect.setAttribute('data-field', 'discount_type');
      typeSelect.className = 'so-invoice__select';
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
      valueCell.className = 'so-invoice__value';
      const valueWrap = document.createElement('div');
      valueWrap.className = 'so-invoice__values';

      let pctValue = current.discount_percent ?? '';
      let afterUsdValue = '';
      if (type === 'percent' && saleUsd !== null && saleUsd > 0) {
        const pct = parseLoose(pctValue);
        if (pct !== null) {
          afterUsdValue = formatUsd(Math.max(0, saleUsd * (1 - Math.max(0, Math.min(95, pct)) / 100)));
        }
      } else if (type === 'fixed_price') {
        afterUsdValue = current.fixed_price_usd ?? '';
      }

      const fields = [
        ['percent', 'discount_percent', '%', '95', pctValue],
        ['percent', 'sale_after_usd', 'بعد $', '', afterUsdValue],
        ['fixed_amount', 'fixed_amount_syp', 'ل.س', '', current.fixed_amount_syp],
        ['fixed_amount', 'fixed_amount_usd', '$', '', current.fixed_amount_usd],
        ['fixed_price', 'fixed_price_syp', 'سعر ل.س', '', current.fixed_price_syp],
        ['fixed_price', 'fixed_price_usd', 'سعر $', '', current.fixed_price_usd],
      ];
      fields.forEach(([showFor, field, caption, max, value]) => {
        const box = document.createElement('label');
        box.className = 'so-invoice__value-field' + (showFor === type ? '' : ' hidden');
        box.setAttribute('data-show-for', showFor);
        const captionEl = document.createElement('span');
        captionEl.className = 'text-text-muted whitespace-nowrap';
        captionEl.textContent = caption;
        const input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        if (field !== 'sale_after_usd') {
          input.required = true;
          input.name = `material_override[${guid}][${field}]`;
        }
        if (max !== '') input.setAttribute('data-max', max);
        input.setAttribute('data-field', field);
        input.value = value ?? '';
        input.className = 'so-invoice__input';
        box.appendChild(captionEl);
        box.appendChild(input);
        valueWrap.appendChild(box);
      });
      valueCell.appendChild(valueWrap);
      tr.appendChild(valueCell);

      const sypCell = document.createElement('td');
      sypCell.className = 'tabular-nums font-extrabold text-emerald-800';
      sypCell.dir = 'ltr';
      sypCell.setAttribute('data-after-syp', '');
      sypCell.textContent = '—';
      tr.appendChild(sypCell);

      const actionCell = document.createElement('td');
      actionCell.className = 'so-invoice__action';
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'so-invoice__remove';
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

      const refreshDerived = () => {
        const selected = typeSelect.value || 'percent';
        const pctInput = tr.querySelector('[data-field="discount_percent"]');
        const afterInput = tr.querySelector('[data-field="sale_after_usd"]');
        const fixedUsdInput = tr.querySelector('[data-field="fixed_price_usd"]');
        const fixedSypInput = tr.querySelector('[data-field="fixed_price_syp"]');
        let afterUsd = null;
        if (selected === 'percent' && saleUsd !== null && saleUsd > 0 && pctInput) {
          const pct = Math.max(0, Math.min(95, parseLoose(pctInput.value) ?? 0));
          afterUsd = Math.max(0, saleUsd * (1 - pct / 100));
          if (afterInput && document.activeElement !== afterInput) {
            afterInput.value = formatUsd(afterUsd);
          }
        } else if (selected === 'fixed_price' && fixedUsdInput) {
          afterUsd = parseLoose(fixedUsdInput.value);
          if (afterUsd !== null && rate > 0 && fixedSypInput && document.activeElement !== fixedSypInput) {
            fixedSypInput.value = String(roundUpTo5(afterUsd * rate));
          }
        } else if (selected === 'fixed_amount' && saleUsd !== null) {
          const amountUsd = parseLoose(tr.querySelector('[data-field="fixed_amount_usd"]')?.value);
          afterUsd = amountUsd !== null ? Math.max(0, saleUsd - amountUsd) : saleUsd;
        }
        if (afterUsd !== null && rate > 0) {
          const syp = roundUpTo5(afterUsd * rate);
          sypCell.textContent = formatSyp(syp);
          const below = purchaseUsd !== null && purchaseUsd > 0 && afterUsd < purchaseUsd;
          sypCell.classList.toggle('is-below-cost', below);
          sypCell.classList.toggle('text-emerald-800', !below);
          afterInput?.classList.toggle('is-below-cost', below);
        } else {
          sypCell.textContent = '—';
          sypCell.classList.remove('is-below-cost');
        }
      };

      const syncRowFields = () => {
        const selected = typeSelect.value || 'percent';
        tr.querySelectorAll('[data-show-for]').forEach((el) => {
          const visible = el.getAttribute('data-show-for') === selected;
          el.classList.toggle('hidden', !visible);
          const input = el.querySelector('input');
          if (input && input.getAttribute('data-field') !== 'sale_after_usd') {
            input.toggleAttribute('required', visible);
          }
        });
        refreshDerived();
      };
      typeSelect.addEventListener('change', syncRowFields);

      const pctInput = tr.querySelector('[data-field="discount_percent"]');
      const afterInput = tr.querySelector('[data-field="sale_after_usd"]');
      pctInput?.addEventListener('input', () => {
        if (!(saleUsd > 0) || !afterInput) return;
        const pct = parseLoose(pctInput.value);
        if (pct === null) return;
        afterInput.value = formatUsd(Math.max(0, saleUsd * (1 - Math.max(0, Math.min(95, pct)) / 100)));
        refreshDerived();
      });
      afterInput?.addEventListener('input', () => {
        if (!(saleUsd > 0) || !pctInput) return;
        const after = parseLoose(afterInput.value);
        if (after === null) return;
        const clamped = Math.max(0, Math.min(saleUsd, after));
        const pct = Math.max(0, Math.min(95, Math.round((1 - clamped / saleUsd) * 10000) / 100));
        pctInput.value = String(pct);
        refreshDerived();
      });
      tr.querySelectorAll('input[data-field]').forEach((input) => {
        input.addEventListener('input', refreshDerived);
        input.addEventListener('change', refreshDerived);
      });

      syncRowFields();
      return tr;
    };

    const syncPerMaterialRows = () => {
      if (!perMaterialRows || !perMaterialPanel) return;
      captureOverrideInputs();
      const selected = ensureSeededSelection();
      perMaterialRows.innerHTML = '';
      const countEl = root.querySelector('#so-invoice-count');
      const countLabel = selected.length === 0
        ? 'لا مواد'
        : (selected.length === 1 ? 'مادة واحدة' : selected.length + ' مواد');
      if (countEl) countEl.textContent = countLabel;
      if (!selected.length) {
        const empty = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 8;
        cell.className = 'so-invoice__empty';
        cell.textContent = 'أضف مادة واحدة على الأقل من البحث أعلاه.';
        empty.appendChild(cell);
        perMaterialRows.appendChild(empty);
        return;
      }
      selected.forEach((guid, index) => {
        const key = String(guid).toLowerCase();
        const meta = labelMap[key] || { name: key };
        perMaterialRows.appendChild(renderOverrideRow(key, meta, index));
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
      li.className = 'so-search-option px-3 py-2 cursor-pointer hover:bg-surface-low text-right';
      const thumb = document.createElement('span');
      thumb.className = 'so-invoice__thumb so-invoice__thumb--sm';
      if (item.image) {
        const img = document.createElement('img');
        img.src = item.image;
        img.alt = '';
        img.loading = 'lazy';
        thumb.appendChild(img);
      } else {
        thumb.classList.add('is-empty');
      }
      const label = document.createElement('span');
      label.className = 'so-search-option__label';
      label.textContent = item.label || item.value || '';
      li.append(thumb, label);
      li.addEventListener('mousedown', (event) => {
        event.preventDefault();
        addMaterialItem(item);
      });
      resultsEl.appendChild(li);
    };

    const addMaterialItem = (item) => {
      if (!item || typeof window.portalTokenPickerAdd !== 'function') return;
      const guid = String(item.value || '').toLowerCase();
      if (!guid) return;
      const normalized = {
        ...item,
        value: guid,
      };
      const added = window.portalTokenPickerAdd(MANUAL_PICKER_ID, [normalized]);
      if (added > 0 && statusEl) {
        statusEl.textContent = 'تمت إضافة: ' + (item.label || item.value);
      }
      labelMap[guid] = {
        guid,
        name: item.name || item.label || guid,
        code: item.code || '',
        image: item.image || '',
        unitSalePriceUsd: item.unitSalePriceUsd ?? item.half ?? item.Half ?? null,
        unitSalePriceSyp: item.unitSalePriceSyp ?? item.whole ?? item.Whole ?? null,
        unitPurchasePriceUsd: item.unitPurchasePriceUsd ?? item.endUser ?? item.EndUser ?? null,
        currencyVal: item.currencyVal ?? item.CurrencyVal ?? null,
      };
      if (!overridesState[guid]) {
        overridesState[guid] = { discount_type: 'percent', discount_percent: '10' };
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
