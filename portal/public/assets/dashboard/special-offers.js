/**
 * Special offers editor — selection mode toggle, discount fields, manual material search.
 */
(function () {
  'use strict';

  const boundRoots = new WeakSet();

  window.portalSpecialOffersInit = (root = document) => {
    const form = root.querySelector('#special-offer-form');
    if (!form || !form.isConnected) {
      return;
    }
    // Re-bind when SPA navigation reused a detached form entry in the WeakSet.
    if (boundRoots.has(form) && form.getAttribute('data-so-js-bound') === '1') {
      return;
    }
    boundRoots.add(form);
    form.setAttribute('data-so-js-bound', '1');

    const scope = form;
    const modeSelect = scope.querySelector('#selection_mode');
    const filterPanel = scope.querySelector('#filter-mode-panel');
    const manualPanel = scope.querySelector('#manual-mode-panel');
    const pricingScope = scope.querySelector('#pricing_scope');
    const perMaterialPanel = scope.querySelector('#per-material-pricing-panel');
    const perMaterialRows = scope.querySelector('#per-material-pricing-rows');

    const readJson = (id, fallback) => {
      // Prefer nodes inside the live form/main so SPA body clones of JSON scripts are ignored.
      const node = scope.querySelector(id) || root.querySelector(id);
      if (!node || !node.isConnected) return fallback;
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

    const isManualMode = () => modeSelect?.value === 'manual';
    const isPerMaterialMode = () => pricingScope?.value === 'per_material' && isManualMode();

    const applyInvoiceChrome = () => {
      const priced = isPerMaterialMode();
      perMaterialPanel?.classList.toggle('so-invoice--unified', !priced);
      const kicker = scope.querySelector('[data-so-invoice-kicker]');
      const title = scope.querySelector('[data-so-invoice-title]');
      const hint = scope.querySelector('[data-so-invoice-hint]');
      if (kicker) kicker.textContent = priced ? 'فاتورة العرض' : 'مواد العرض';
      if (title) title.textContent = priced ? 'مواد العرض وأسعارها' : 'المواد المشمولة بالعرض';
      if (hint) {
        hint.textContent = priced
          ? 'الدولار ثابت · الليرة بعد الخصم = دولار × سعر الصرف مع التقريب لأعلى 5. عدّل النسبة أو السعر.'
          : 'أضف مواداً من البحث أعلاه. الحسم الموحّد من أعلى النموذج يُطبَّق على كل المواد — يمكن ضبط كمية العرض لكل مادة.';
      }
      // Unified offer: keep discount inputs out of POST (disabled) so only qty caps submit.
      perMaterialRows?.querySelectorAll('[data-override-guid]').forEach((row) => {
        row.querySelectorAll('[data-field="discount_type"], [data-field="discount_percent"], [data-field="sale_after_usd"], [data-field="fixed_amount_syp"], [data-field="fixed_amount_usd"], [data-field="fixed_price_syp"], [data-field="fixed_price_usd"]').forEach((el) => {
          if (el instanceof HTMLInputElement || el instanceof HTMLSelectElement) {
            el.disabled = !priced;
            if (el.getAttribute('data-field') === 'discount_percent') {
              el.toggleAttribute('required', priced);
            }
          }
        });
      });
    };

    const syncPanels = () => {
      if (pricingScope?.value === 'per_material' && modeSelect && modeSelect.value !== 'manual') {
        modeSelect.value = 'manual';
      }
      const isManual = isManualMode();
      if (!isManual && pricingScope?.value === 'per_material') {
        pricingScope.value = 'offer';
      }
      filterPanel?.classList.toggle('hidden', isManual);
      manualPanel?.classList.toggle('hidden', !isManual);
      const perMaterial = isPerMaterialMode();
      scope.querySelector('#selection-mode-article')?.classList.toggle('hidden', perMaterial);
      scope.querySelector('#manual-mode-heading')?.classList.toggle('hidden', perMaterial);
      // Always hide chip chrome in manual mode — the materials table is the UI.
      const pickerWrap = scope.querySelector('[data-so-manual-picker-wrap]');
      const picker = scope.querySelector('[data-picker-id="so-manual-materials"]');
      if (pickerWrap) {
        pickerWrap.classList.add('hidden', 'so-manual-picker--invoice');
      } else {
        picker?.classList.add('hidden');
      }
      if (picker) {
        picker.setAttribute('data-suppress-chips', '1');
        // Clear any chip chrome that painted before suppress was applied.
        picker.querySelector('[data-role="chips"]')?.replaceChildren();
      }
      scope.querySelectorAll('.so-unified-discount').forEach((el) => {
        if (perMaterial) {
          el.classList.add('hidden');
        }
      });
      if (perMaterial) {
        ['#field-percent', '#field-amount-syp', '#field-amount-usd', '#field-syp', '#field-usd', '#offer-discount-type-wrap'].forEach((sel) => {
          scope.querySelector(sel)?.classList.add('hidden');
        });
      } else {
        syncDiscount();
      }
      syncPricingScope();
    };
    modeSelect?.addEventListener('change', syncPanels);

    const discountType = scope.querySelector('#discount_type');
    const syncDiscount = () => {
      const type = discountType?.value || 'percent';
      scope.querySelector('#field-percent')?.classList.toggle('hidden', type !== 'percent');
      scope.querySelector('#field-amount-syp')?.classList.toggle('hidden', type !== 'fixed_amount');
      scope.querySelector('#field-amount-usd')?.classList.toggle('hidden', type !== 'fixed_amount');
      scope.querySelector('#field-syp')?.classList.toggle('hidden', type !== 'fixed_price');
      scope.querySelector('#field-usd')?.classList.toggle('hidden', type !== 'fixed_price');
    };
    discountType?.addEventListener('change', syncDiscount);
    syncDiscount();

    const captureOverrideInputs = () => {
      if (!perMaterialRows) return;
      perMaterialRows.querySelectorAll('[data-override-guid]').forEach((row) => {
        const guid = String(row.getAttribute('data-override-guid') || '').toLowerCase();
        if (!guid) return;
        const prev = overridesState[guid] && typeof overridesState[guid] === 'object'
          ? overridesState[guid]
          : {};
        const type = row.querySelector('[data-field="discount_type"]')?.value || prev.discount_type || 'percent';
        const qtyModeEl = row.querySelector('[data-field="qty_mode"]');
        const qtyCapEl = row.querySelector('[data-field="qty_cap_packages"]');
        const qtyModeFromDom = qtyModeEl instanceof HTMLSelectElement || qtyModeEl instanceof HTMLInputElement
          ? String(qtyModeEl.value || '').trim()
          : '';
        const qtyCapFromDom = qtyCapEl instanceof HTMLInputElement ? String(qtyCapEl.value ?? '') : null;
        // Never silently fall back to unlimited — that wiped stagnant fixed caps on rebuild.
        let qtyMode = qtyModeFromDom
          || String(prev.qty_mode || '').trim()
          || 'fixed';
        if (!['unlimited', 'stock', 'fixed'].includes(qtyMode)) {
          qtyMode = 'fixed';
        }
        let qtyCap = qtyCapFromDom !== null ? qtyCapFromDom : String(prev.qty_cap_packages ?? '');
        if (qtyMode === 'fixed' && (qtyCap === '' || qtyCap == null)) {
          const meta = labelMap[guid] || {};
          if (meta.availablePackages != null && meta.availablePackages !== '') {
            qtyCap = String(meta.availablePackages);
          } else if (prev.qty_cap_packages != null && prev.qty_cap_packages !== '') {
            qtyCap = String(prev.qty_cap_packages);
          }
        }
        overridesState[guid] = {
          discount_type: type,
          discount_percent: row.querySelector('[data-field="discount_percent"]')?.value || prev.discount_percent || '',
          fixed_price_syp: row.querySelector('[data-field="fixed_price_syp"]')?.value || prev.fixed_price_syp || '',
          fixed_price_usd: row.querySelector('[data-field="fixed_price_usd"]')?.value || prev.fixed_price_usd || '',
          fixed_amount_syp: row.querySelector('[data-field="fixed_amount_syp"]')?.value || prev.fixed_amount_syp || '',
          fixed_amount_usd: row.querySelector('[data-field="fixed_amount_usd"]')?.value || prev.fixed_amount_usd || '',
          qty_mode: qtyMode,
          qty_cap_packages: qtyCap,
          is_exhausted: !!(prev.is_exhausted || row.querySelector('.so-invoice__exhausted')),
        };
      });
    };

    const collectGuidSources = () => {
      const fromPicker = typeof window.portalTokenPickerGetSelected === 'function'
        ? window.portalTokenPickerGetSelected('so-manual-materials').map((g) => String(g).toLowerCase())
        : [];
      const fromDom = Array.from(scope.querySelectorAll('input[name="manual_material_guids[]"]'))
        .map((input) => String(input.value || '').toLowerCase());
      const fromRows = Array.from(perMaterialRows?.querySelectorAll('[data-override-guid]') || [])
        .map((row) => String(row.getAttribute('data-override-guid') || '').toLowerCase());
      return Array.from(new Set(
        [...fromPicker, ...fromDom, ...fromRows, ...seedGuids, ...Object.keys(overridesState)]
          .map((g) => String(g || '').toLowerCase())
          .filter(Boolean)
      ));
    };

    const ensureSeededSelection = () => {
      let selected = collectGuidSources();
      if (selected.length > 0 && typeof window.portalTokenPickerAdd === 'function') {
        const already = new Set(
          (typeof window.portalTokenPickerGetSelected === 'function'
            ? window.portalTokenPickerGetSelected('so-manual-materials')
            : []
          ).map((g) => String(g).toLowerCase()).filter(Boolean)
        );
        // Only push missing GUIDs — calling Add with a full rebuild triggers MutationObserver loops.
        const missing = selected.filter((guid) => !already.has(guid));
        if (missing.length > 0) {
          const items = missing.map((guid) => {
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
        }
        const fromPicker = typeof window.portalTokenPickerGetSelected === 'function'
          ? window.portalTokenPickerGetSelected('so-manual-materials').map((g) => String(g).toLowerCase())
          : [];
        if (fromPicker.length > 0) {
          selected = Array.from(new Set(fromPicker.filter(Boolean)));
        }
      }
      return selected;
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

      // Prefer stored override; otherwise default new invoice rows to fixed = current packages.
      let qtyMode = ['unlimited', 'stock', 'fixed'].includes(String(current.qty_mode || ''))
        ? String(current.qty_mode)
        : 'fixed';
      if (current.qty_mode == null || current.qty_mode === '') {
        qtyMode = 'fixed';
        if ((current.qty_cap_packages == null || current.qty_cap_packages === '') && meta.availablePackages != null) {
          current.qty_cap_packages = meta.availablePackages;
        }
      }
      const qtyCell = document.createElement('td');
      qtyCell.className = 'so-invoice__qty';
      const qtyWrap = document.createElement('div');
      qtyWrap.className = 'so-invoice__qty-fields';
      const qtySelect = document.createElement('select');
      qtySelect.name = `material_override[${guid}][qty_mode]`;
      qtySelect.setAttribute('data-field', 'qty_mode');
      qtySelect.className = 'so-invoice__select so-invoice__select--qty';
      [
        ['unlimited', 'بلا حد'],
        ['stock', 'المخزون المتاح'],
        ['fixed', 'كمية محددة'],
      ].forEach(([value, text]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;
        option.selected = qtyMode === value;
        qtySelect.appendChild(option);
      });
      qtyWrap.appendChild(qtySelect);
      const qtyCapBox = document.createElement('label');
      qtyCapBox.className = 'so-invoice__value-field' + (qtyMode === 'fixed' ? '' : ' hidden');
      qtyCapBox.setAttribute('data-show-for-qty', 'fixed');
      const qtyCapCaption = document.createElement('span');
      qtyCapCaption.className = 'text-text-muted whitespace-nowrap';
      qtyCapCaption.textContent = 'طرد';
      const qtyCapInput = document.createElement('input');
      qtyCapInput.type = 'text';
      qtyCapInput.inputMode = 'decimal';
      qtyCapInput.autocomplete = 'off';
      qtyCapInput.name = `material_override[${guid}][qty_cap_packages]`;
      qtyCapInput.setAttribute('data-field', 'qty_cap_packages');
      qtyCapInput.className = 'so-invoice__input';
      qtyCapInput.placeholder = 'مثلاً 20';
      qtyCapInput.value = current.qty_cap_packages != null ? String(current.qty_cap_packages) : '';
      qtyCapBox.append(qtyCapCaption, qtyCapInput);
      qtyWrap.appendChild(qtyCapBox);
      if (current.is_exhausted) {
        const exhausted = document.createElement('span');
        exhausted.className = 'so-invoice__exhausted';
        exhausted.textContent = 'نفدت — خرجت من العرض';
        qtyWrap.appendChild(exhausted);
      }
      qtySelect.addEventListener('change', () => {
        const mode = qtySelect.value || 'unlimited';
        qtyCapBox.classList.toggle('hidden', mode !== 'fixed');
        overridesState[guid] = {
          ...(overridesState[guid] || {}),
          qty_mode: mode,
          qty_cap_packages: qtyCapInput.value,
        };
      });
      qtyCapInput.addEventListener('input', () => {
        overridesState[guid] = {
          ...(overridesState[guid] || {}),
          qty_mode: qtySelect.value || 'unlimited',
          qty_cap_packages: qtyCapInput.value,
        };
      });
      qtyCell.appendChild(qtyWrap);
      tr.appendChild(qtyCell);

      const sypCell = document.createElement('td');
      sypCell.className = 'tabular-nums font-extrabold text-emerald-800 so-invoice__after';
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

    let syncRowsBusy = false;
    const syncPerMaterialRows = () => {
      if (!perMaterialRows || !perMaterialPanel || syncRowsBusy) return;
      syncRowsBusy = true;
      try {
        captureOverrideInputs();
        let selected = ensureSeededSelection();
        const existing = Array.from(perMaterialRows.querySelectorAll('[data-override-guid]'))
          .map((row) => String(row.getAttribute('data-override-guid') || '').toLowerCase())
          .filter(Boolean);

        // Never wipe server-rendered invoice rows if seeding still looks empty.
        if (selected.length === 0 && existing.length > 0) {
          selected = existing.slice();
          ensureSeededSelection();
        }

        const selectedSet = new Set(selected);
        const existingSet = new Set(existing);
        const sameGuids = selected.length === existing.length
          && selected.every((guid) => existingSet.has(guid))
          && existing.every((guid) => selectedSet.has(guid));

        // Rebuild if GUID set changed OR labelMap gained sale/purchase prices after enrich.
        let pricesStale = false;
        if (sameGuids) {
          perMaterialRows.querySelectorAll('[data-override-guid]').forEach((row) => {
            const guid = String(row.getAttribute('data-override-guid') || '').toLowerCase();
            const meta = labelMap[guid] || {};
            const saleAttr = row.getAttribute('data-sale-usd') || '';
            const purchaseAttr = row.getAttribute('data-purchase-usd') || '';
            const saleMeta = meta.unitSalePriceUsd != null ? String(meta.unitSalePriceUsd) : '';
            const purchaseMeta = meta.unitPurchasePriceUsd != null ? String(meta.unitPurchasePriceUsd) : '';
            if (saleAttr !== saleMeta || purchaseAttr !== purchaseMeta) {
              pricesStale = true;
            }
          });
        }

        const countEl = scope.querySelector('#so-invoice-count');
        const countLabel = selected.length === 0
          ? 'لا مواد'
          : (selected.length === 1 ? 'مادة واحدة' : selected.length + ' مواد');
        if (countEl) countEl.textContent = countLabel;

        // Keep server-rendered rows when GUID sets and prices already match.
        if (sameGuids && !pricesStale) {
          bindInvoiceRowBehaviors(perMaterialRows);
          applyInvoiceChrome();
          return;
        }

        perMaterialRows.innerHTML = '';
        if (!selected.length) {
          const empty = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 9;
          cell.className = 'so-invoice__empty';
          cell.textContent = 'أضف مادة واحدة على الأقل من البحث أعلاه.';
          empty.appendChild(cell);
          perMaterialRows.appendChild(empty);
          applyInvoiceChrome();
          return;
        }
        selected.forEach((guid, index) => {
          const key = String(guid).toLowerCase();
          const meta = labelMap[key] || { name: key };
          perMaterialRows.appendChild(renderOverrideRow(key, meta, index));
        });
        applyInvoiceChrome();
      } finally {
        syncRowsBusy = false;
      }
    };

    const bindInvoiceRowBehaviors = (rowsRoot) => {
      if (!(rowsRoot instanceof HTMLElement) || rowsRoot.getAttribute('data-so-bound') === '1') return;
      rowsRoot.setAttribute('data-so-bound', '1');
      rowsRoot.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const removeBtn = target.closest('[data-so-remove-row], .so-invoice__remove');
        if (!(removeBtn instanceof HTMLElement)) return;
        const row = removeBtn.closest('[data-override-guid]');
        if (!(row instanceof HTMLElement)) return;
        const guid = String(row.getAttribute('data-override-guid') || '').toLowerCase();
        row.remove();
        if (guid) delete overridesState[guid];
        if (guid && typeof window.portalTokenPickerRemove === 'function') {
          window.portalTokenPickerRemove('so-manual-materials', guid);
        }
        syncPerMaterialRows();
      });
      rowsRoot.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const field = target.getAttribute('data-field') || '';
        const row = target.closest('[data-override-guid]');
        if (!(row instanceof HTMLElement)) return;
        if (field === 'discount_type') {
          const selected = target instanceof HTMLSelectElement ? target.value : 'percent';
          row.querySelectorAll('[data-show-for]').forEach((el) => {
            const visible = el.getAttribute('data-show-for') === selected;
            el.classList.toggle('hidden', !visible);
          });
          return;
        }
        if (field === 'qty_mode') {
          const mode = target instanceof HTMLSelectElement ? target.value : 'unlimited';
          row.querySelectorAll('[data-show-for-qty]').forEach((el) => {
            const visible = el.getAttribute('data-show-for-qty') === mode;
            el.classList.toggle('hidden', !visible);
          });
        }
      });
      rowsRoot.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        const row = target.closest('[data-override-guid]');
        if (!(row instanceof HTMLElement)) return;
        const saleUsd = parseLoose(row.getAttribute('data-sale-usd'));
        const purchaseUsd = parseLoose(row.getAttribute('data-purchase-usd'));
        const rate = parseLoose(row.getAttribute('data-rate')) || 0;
        const type = row.querySelector('[data-field="discount_type"]')?.value || 'percent';
        const pctInput = row.querySelector('[data-field="discount_percent"]');
        const afterInput = row.querySelector('[data-field="sale_after_usd"]');
        const sypCell = row.querySelector('[data-after-syp]');
        let afterUsd = null;
        if (type === 'percent' && saleUsd !== null && saleUsd > 0 && pctInput instanceof HTMLInputElement) {
          if (target === pctInput && afterInput instanceof HTMLInputElement) {
            const pct = Math.max(0, Math.min(95, parseLoose(pctInput.value) ?? 0));
            afterUsd = Math.max(0, saleUsd * (1 - pct / 100));
            afterInput.value = formatUsd(afterUsd);
          } else if (target === afterInput) {
            afterUsd = parseLoose(afterInput.value);
            if (afterUsd !== null && pctInput instanceof HTMLInputElement) {
              afterUsd = Math.max(0, Math.min(saleUsd, afterUsd));
              pctInput.value = String(Math.max(0, Math.min(95, Math.round((1 - afterUsd / saleUsd) * 10000) / 100)));
            }
          } else {
            afterUsd = afterInput instanceof HTMLInputElement ? parseLoose(afterInput.value) : null;
          }
        } else if (type === 'fixed_price') {
          afterUsd = parseLoose(row.querySelector('[data-field="fixed_price_usd"]')?.value);
          if (afterUsd !== null && rate > 0) {
            const sypInput = row.querySelector('[data-field="fixed_price_syp"]');
            if (sypInput instanceof HTMLInputElement && document.activeElement !== sypInput) {
              sypInput.value = String(roundUpTo5(afterUsd * rate));
            }
          }
        }
        if (sypCell instanceof HTMLElement) {
          if (afterUsd !== null && rate > 0) {
            sypCell.textContent = formatSyp(roundUpTo5(afterUsd * rate));
            const below = purchaseUsd !== null && purchaseUsd > 0 && afterUsd < purchaseUsd;
            sypCell.classList.toggle('is-below-cost', below);
            sypCell.classList.toggle('text-emerald-800', !below);
            afterInput?.classList.toggle('is-below-cost', below);
          }
        }
      });
    };

    const syncPricingScope = () => {
      const isManual = isManualMode();
      perMaterialPanel?.classList.toggle('hidden', !isManual);
      applyInvoiceChrome();
      if (isManual) {
        syncPerMaterialRows();
        applyInvoiceChrome();
      }
    };
    pricingScope?.addEventListener('change', () => {
      if (pricingScope.value === 'per_material' && modeSelect && modeSelect.value !== 'manual') {
        modeSelect.value = 'manual';
      }
      syncPanels();
    });

    // Keep table rows in sync when hidden GUID inputs change (debounced; ignore self-writes).
    const picker = scope.querySelector('[data-picker-id="so-manual-materials"]');
    const hiddenHost = picker?.querySelector('[data-role="hidden-inputs"]');
    if (hiddenHost && typeof MutationObserver !== 'undefined') {
      let observerTimer = null;
      const observer = new MutationObserver(() => {
        if (syncRowsBusy || !isManualMode()) return;
        if (observerTimer) clearTimeout(observerTimer);
        observerTimer = setTimeout(() => {
          observerTimer = null;
          if (!syncRowsBusy && isManualMode()) {
            syncPerMaterialRows();
            applyInvoiceChrome();
          }
        }, 0);
      });
      observer.observe(hiddenHost, { childList: true });
    }

    syncPanels();
    syncPricingScope();
    bindInvoiceRowBehaviors(perMaterialRows);
    // Late pass: token picker may finish seeding hidden inputs after first sync.
    window.setTimeout(() => {
      if (isManualMode()) {
        syncPerMaterialRows();
        applyInvoiceChrome();
      }
    }, 0);
    window.setTimeout(() => {
      if (isManualMode()) {
        syncPerMaterialRows();
        applyInvoiceChrome();
      }
    }, 150);

    form.addEventListener('submit', (event) => {
      if (!isManualMode()) return;
      const selected = typeof window.portalTokenPickerGetSelected === 'function'
        ? window.portalTokenPickerGetSelected('so-manual-materials')
        : [];
      const rowCount = perMaterialRows?.querySelectorAll('[data-override-guid]')?.length || 0;
      if (selected.length > 0 || rowCount > 0) return;
      event.preventDefault();
      const status = scope.querySelector('#so-material-search-status');
      if (status) {
        status.textContent = isPerMaterialMode()
          ? 'سعر لكل مادة يتطلب اختيار مادة واحدة على الأقل في الجدول.'
          : 'اختر مادة واحدة على الأقل في جدول المواد.';
      }
    });

    const searchInput = scope.querySelector('#so-material-search');
    const resultsEl = scope.querySelector('#so-material-search-results');
    const searchWrap = scope.querySelector('#so-material-search-wrap');
    const statusEl = scope.querySelector('#so-material-search-status');
    if (!searchInput) {
      // Invoice/pricing UI is already bound above; search is optional when panel absent.
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
        return new Set(
          window.portalTokenPickerGetSelected(MANUAL_PICKER_ID).map((g) => String(g).toLowerCase())
        );
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

    const pricesFromItem = (item) => ({
      unitSalePriceUsd: item.unitSalePriceUsd ?? item.half ?? item.Half ?? null,
      unitSalePriceSyp: item.unitSalePriceSyp ?? item.whole ?? item.Whole ?? null,
      unitPurchasePriceUsd: item.unitPurchasePriceUsd ?? item.endUser ?? item.EndUser ?? null,
      currencyVal: item.currencyVal ?? item.CurrencyVal ?? null,
      warehouseQuantity: item.warehouseQuantity ?? item.WarehouseQuantity ?? null,
      packageConversionFactor: item.packageConversionFactor ?? item.PackageConversionFactor ?? null,
      availablePackages: item.availablePackages ?? null,
    });

    const packagesFromMeta = (meta) => {
      if (meta?.availablePackages != null && Number.isFinite(Number(meta.availablePackages))) {
        return Math.max(0, Number(meta.availablePackages));
      }
      const warehouse = Number(meta?.warehouseQuantity);
      const packaging = Number(meta?.packageConversionFactor);
      if (Number.isFinite(warehouse) && warehouse >= 0) {
        const factor = Number.isFinite(packaging) && packaging > 0 ? packaging : 1;
        return Math.max(0, Math.round((warehouse / factor) * 10000) / 10000);
      }
      return null;
    };

    const applyLabelMeta = (guid, item) => {
      const prices = pricesFromItem(item || {});
      const prev = labelMap[guid] || {};
      labelMap[guid] = {
        guid,
        name: item?.name || item?.label || prev.name || guid,
        code: item?.code || prev.code || '',
        image: item?.image || prev.image || '',
        unitSalePriceUsd: prices.unitSalePriceUsd ?? prev.unitSalePriceUsd ?? null,
        unitSalePriceSyp: prices.unitSalePriceSyp ?? prev.unitSalePriceSyp ?? null,
        unitPurchasePriceUsd: prices.unitPurchasePriceUsd ?? prev.unitPurchasePriceUsd ?? null,
        currencyVal: prices.currencyVal ?? prev.currencyVal ?? null,
        warehouseQuantity: prices.warehouseQuantity ?? prev.warehouseQuantity ?? null,
        packageConversionFactor: prices.packageConversionFactor ?? prev.packageConversionFactor ?? null,
        availablePackages: prices.availablePackages ?? prev.availablePackages ?? packagesFromMeta({
          warehouseQuantity: prices.warehouseQuantity ?? prev.warehouseQuantity,
          packageConversionFactor: prices.packageConversionFactor ?? prev.packageConversionFactor,
        }),
      };
      return labelMap[guid];
    };

    const needsPriceEnrich = (meta) => {
      if (!meta) return true;
      return meta.unitSalePriceUsd == null
        && meta.unitSalePriceSyp == null
        && meta.unitPurchasePriceUsd == null;
    };

    const enrichMaterialPrices = async (guid) => {
      try {
        const response = await fetch(
          '/dashboard/home-sections-api.php?guid=' + encodeURIComponent(guid),
          { headers: { Accept: 'application/json' }, credentials: 'same-origin' }
        );
        const data = await response.json();
        if (!data?.ok || !data.item) return false;
        applyLabelMeta(guid, data.item);
        return true;
      } catch (_) {
        return false;
      }
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
      const meta = applyLabelMeta(guid, item);
      if (!overridesState[guid]) {
        const packages = packagesFromMeta(meta);
        overridesState[guid] = {
          discount_type: 'percent',
          discount_percent: '10',
          // Default: fixed qty = current warehouse packages (كمية ثابتة = الرصيد الحالي).
          qty_mode: 'fixed',
          qty_cap_packages: packages != null ? String(packages) : '',
        };
      }
      const refreshInvoice = () => {
        if (isManualMode()) {
          syncPerMaterialRows();
          applyInvoiceChrome();
          perMaterialRows?.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      };
      refreshInvoice();
      if (needsPriceEnrich(meta)) {
        enrichMaterialPrices(guid).then((ok) => {
          if (ok) refreshInvoice();
        });
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
        if (!item || !item.value) return;
        const value = String(item.value).toLowerCase();
        item.value = value;
        if (selected.has(value)) return;
        if (searchItems.some((row) => String(row.value).toLowerCase() === value)) return;
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
        // Defer so scrollIntoView from highlight does not chain-load every page on the main thread.
        window.setTimeout(() => {
          if (!searchHasMore || searchLoading) return;
          const stillNearBottom =
            resultsEl.scrollTop + resultsEl.clientHeight >= resultsEl.scrollHeight - 48;
          if (stillNearBottom) runMaterialSearch(true);
        }, 50);
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
