/**
 * Stagnant inventory — add-to-candidate tray, bidirectional % ↔ USD,
 * large preview with nav, SYP round-up-to-5, below-cost warning.
 */
(function () {
  const BOUND = 'data-stagnant-bound';
  /** @type {Map<string, {guid:string, name:string, code:string, saleUsd:number|null, purchaseUsd:number|null, rate:number, percent:number, priceUsd:number|null}>} */
  const candidates = new Map();
  let activeRow = null;
  let syncingPreview = false;

  function roundUpTo5(amount) {
    if (!(amount > 0)) return 0;
    return Math.ceil(amount / 5) * 5;
  }

  function parseLoose(value) {
    const text = String(value ?? '').trim().replace(',', '.');
    if (text === '' || text === '.' || text === '-') return null;
    const n = Number(text);
    return Number.isFinite(n) ? n : null;
  }

  function formatUsd(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function formatPct(n) {
    return String(Math.round(n * 100) / 100);
  }

  function formatSyp(n) {
    return Math.round(n).toLocaleString('en-US');
  }

  function formatMoney(value, decimals = 2) {
    const n = parseLoose(value);
    if (n === null) return '—';
    return n.toLocaleString('en-US', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  function previewModal() {
    return document.querySelector('[data-stagnant-preview-modal]');
  }

  function isPreviewOpen() {
    const modal = previewModal();
    return !!(modal && !modal.hidden && modal.classList.contains('is-open'));
  }

  function allRows() {
    return Array.from(document.querySelectorAll('[data-stagnant-card]'));
  }

  function markRowInTray(row, inTray) {
    if (!(row instanceof HTMLElement)) return;
    row.classList.toggle('is-in-tray', inTray);
    if (inTray) row.setAttribute('data-stagnant-in-tray', '1');
    else row.removeAttribute('data-stagnant-in-tray');
    const addBtn = row.querySelector('[data-stagnant-add]');
    if (addBtn instanceof HTMLButtonElement) {
      addBtn.textContent = inTray ? 'مُضافة' : 'إضافة';
      addBtn.disabled = inTray;
      addBtn.classList.toggle('is-added', inTray);
    }
    const badge = row.querySelector('[data-stagnant-tray-badge]');
    if (badge instanceof HTMLElement) {
      badge.hidden = !inTray;
    }
  }

  function rowSaleUsd(row) {
    return parseLoose(row.getAttribute('data-sale-usd'));
  }

  function rowPurchaseUsd(row) {
    return parseLoose(row.getAttribute('data-purchase-usd'));
  }

  function rowRate(row) {
    return parseLoose(row.getAttribute('data-rate')) || 0;
  }

  function defaultDiscountValue(root = document) {
    const input = root.querySelector('[data-stagnant-default-discount]');
    const n = input instanceof HTMLInputElement ? parseLoose(input.value) : null;
    return n === null ? 15 : Math.max(0, Math.min(95, n));
  }

  function syncSubmitState(root = document) {
    const count = candidates.size;
    root.querySelectorAll('[data-stagnant-selected-count]').forEach((el) => {
      el.textContent = String(count);
    });
    root.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (btn instanceof HTMLButtonElement) btn.disabled = count === 0;
    });
  }

  function applyBelowCost(row, saleAfterUsd) {
    const purchase = rowPurchaseUsd(row);
    const below = saleAfterUsd !== null
      && purchase !== null
      && purchase > 0
      && saleAfterUsd < purchase;

    const priceInput = row.querySelector('[data-stagnant-item-price]');
    const sypEl = row.querySelector('[data-stagnant-item-syp]');
    if (priceInput instanceof HTMLElement) {
      priceInput.classList.toggle('is-below-cost', below);
    }
    if (sypEl instanceof HTMLElement) {
      sypEl.classList.toggle('is-below-cost', below);
      sypEl.classList.toggle('text-emerald-800', !below);
    }

    if (isPreviewOpen() && activeRow === row) {
      const modal = previewModal();
      const warn = modal?.querySelector('[data-stagnant-preview-below-cost]');
      const previewPrice = modal?.querySelector('[data-stagnant-preview-price]');
      const sypBox = modal?.querySelector('[data-stagnant-preview-syp-box]');
      if (warn instanceof HTMLElement) {
        warn.hidden = !below;
        warn.classList.toggle('is-visible', below);
      }
      if (previewPrice instanceof HTMLElement) previewPrice.classList.toggle('is-below-cost', below);
      if (sypBox instanceof HTMLElement) sypBox.classList.toggle('is-below-cost', below);
    }
    return below;
  }

  function updateSypPreview(row, saleUsdAfter) {
    const sypEl = row.querySelector('[data-stagnant-item-syp]');
    if (!(sypEl instanceof HTMLElement)) return;
    const rate = rowRate(row);
    if (!(saleUsdAfter > 0) || !(rate > 0)) {
      sypEl.textContent = '—';
      applyBelowCost(row, null);
      return;
    }
    sypEl.textContent = formatSyp(roundUpTo5(saleUsdAfter * rate));
    applyBelowCost(row, saleUsdAfter);
  }

  function syncRowFromPercent(row, percent, options = {}) {
    const { markTouched = false, source = null, commit = false } = options;
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (!(pctInput instanceof HTMLInputElement)) return;

    const raw = String(percent ?? '').trim();
    const parsed = parseLoose(raw);
    if (parsed === null && !commit) {
      syncPreviewPricingFromRow({ skipSource: source });
      return;
    }

    const pct = Math.max(0, Math.min(95, parsed ?? 0));
    if (commit || source !== pctInput) pctInput.value = formatPct(pct);
    if (markTouched) pctInput.dataset.touched = '1';

    const saleUsd = rowSaleUsd(row);
    if (!(priceInput instanceof HTMLInputElement) || !(saleUsd > 0) || parsed === null) {
      updateSypPreview(row, null);
      syncPreviewPricingFromRow({ skipSource: source });
      return;
    }

    const after = Math.max(0, saleUsd * (1 - pct / 100));
    if (source !== priceInput) priceInput.value = formatUsd(after);
    updateSypPreview(row, after);
    syncPreviewPricingFromRow({ skipSource: source });

    const guid = row.getAttribute('data-material-guid') || '';
    if (guid && candidates.has(guid)) {
      const item = candidates.get(guid);
      item.percent = pct;
      item.priceUsd = after;
      renderTray();
    }
  }

  function syncRowFromPrice(row, priceValue, options = {}) {
    const { source = null, commit = false } = options;
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (!(pctInput instanceof HTMLInputElement) || !(priceInput instanceof HTMLInputElement)) return;

    const saleUsd = rowSaleUsd(row);
    if (!(saleUsd > 0)) return;

    const parsed = parseLoose(priceValue);
    if (parsed === null && !commit) {
      syncPreviewPricingFromRow({ skipSource: source });
      return;
    }

    let after = parsed === null ? 0 : parsed;
    after = Math.max(0, Math.min(saleUsd, after));
    if (commit || source !== priceInput) priceInput.value = formatUsd(after);

    const pct = Math.max(0, Math.min(95, Math.round((1 - after / saleUsd) * 10000) / 100));
    if (source !== pctInput) pctInput.value = formatPct(pct);
    pctInput.dataset.touched = '1';
    updateSypPreview(row, after);
    syncPreviewPricingFromRow({ skipSource: source });

    const guid = row.getAttribute('data-material-guid') || '';
    if (guid && candidates.has(guid)) {
      const item = candidates.get(guid);
      item.percent = pct;
      item.priceUsd = after;
      renderTray();
    }
  }

  function applyDefaultDiscount(root = document) {
    const value = defaultDiscountValue(root);
    root.querySelectorAll('[data-stagnant-card]').forEach((row) => {
      const pctInput = row.querySelector('[data-stagnant-item-discount]');
      if (!(pctInput instanceof HTMLInputElement) || pctInput.dataset.touched) return;
      syncRowFromPercent(row, value, { commit: true });
    });
  }

  function readRowPricing(row) {
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    const saleUsd = rowSaleUsd(row);
    let percent = pctInput instanceof HTMLInputElement ? parseLoose(pctInput.value) : null;
    if (percent === null) percent = defaultDiscountValue();
    percent = Math.max(0.01, Math.min(95, percent));
    let priceUsd = priceInput instanceof HTMLInputElement ? parseLoose(priceInput.value) : null;
    if (priceUsd === null && saleUsd !== null) {
      priceUsd = Math.max(0, saleUsd * (1 - percent / 100));
    }
    return { percent, priceUsd };
  }

  function addCandidateFromRow(row) {
    if (!(row instanceof HTMLElement)) return false;
    const guid = (row.getAttribute('data-material-guid') || '').toLowerCase();
    if (!guid) return false;
    const pricing = readRowPricing(row);
    candidates.set(guid, {
      guid,
      name: row.getAttribute('data-name') || guid,
      code: row.getAttribute('data-code') || '',
      image: row.getAttribute('data-image-thumb') || row.getAttribute('data-image-full') || '',
      saleUsd: rowSaleUsd(row),
      purchaseUsd: rowPurchaseUsd(row),
      rate: rowRate(row),
      percent: pricing.percent,
      priceUsd: pricing.priceUsd,
    });
    markRowInTray(row, true);
    renderTray();
    syncSubmitState();
    syncPreviewPricingFromRow();
    updateNavControls();
    return true;
  }

  function removeCandidate(guid) {
    candidates.delete(guid);
    const row = document.querySelector('[data-stagnant-card][data-material-guid="' + guid + '"]');
    if (row instanceof HTMLElement) {
      markRowInTray(row, false);
    }
    renderTray();
    syncSubmitState();
    if (isPreviewOpen() && activeRow === row) syncPreviewPricingFromRow();
    updateNavControls();
  }

  function renderTray() {
    const list = document.querySelector('[data-stagnant-tray-list]');
    if (!(list instanceof HTMLElement)) return;
    list.innerHTML = '';

    if (candidates.size === 0) {
      const empty = document.createElement('p');
      empty.className = 'text-xs text-text-muted px-3 py-4 text-center';
      empty.setAttribute('data-stagnant-tray-empty', '');
      empty.textContent = 'لا مواد مرشّحة بعد — اضغط «إضافة» بجانب المادة.';
      list.appendChild(empty);
      return;
    }

    candidates.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'flex flex-wrap items-center gap-2 px-3 py-2 border-b border-border-subtle last:border-b-0 bg-white/80';
      row.setAttribute('data-tray-guid', item.guid);

      const thumb = document.createElement('div');
      thumb.className = 'w-14 h-14 rounded-lg overflow-hidden bg-slate-100 border border-border-subtle flex-shrink-0';
      if (item.image) {
        const img = document.createElement('img');
        img.src = item.image;
        img.alt = item.name;
        img.loading = 'lazy';
        img.className = 'w-full h-full object-cover';
        thumb.appendChild(img);
      }

      const title = document.createElement('div');
      title.className = 'min-w-[10rem] flex-1';
      title.innerHTML = '<div class="text-sm font-bold text-slate-900 leading-snug"></div>'
        + '<div class="text-[11px] font-mono text-text-muted" dir="ltr"></div>';
      title.querySelector('div').textContent = item.name;
      title.querySelectorAll('div')[1].textContent = item.code;

      const pctLabel = document.createElement('label');
      pctLabel.className = 'text-[11px] font-bold text-slate-600';
      pctLabel.textContent = 'خصم % ';
      const pctInput = document.createElement('input');
      pctInput.type = 'text';
      pctInput.inputMode = 'decimal';
      pctInput.autocomplete = 'off';
      pctInput.className = 'w-16 h-8 rounded-lg border border-border-subtle px-2 text-sm tabular-nums ms-1';
      pctInput.name = 'material_discount[' + item.guid + ']';
      pctInput.value = formatPct(item.percent);
      pctInput.addEventListener('input', () => {
        const pct = parseLoose(pctInput.value);
        if (pct === null) return;
        item.percent = Math.max(0, Math.min(95, pct));
        if (item.saleUsd && item.saleUsd > 0) {
          item.priceUsd = Math.max(0, item.saleUsd * (1 - item.percent / 100));
          priceInput.value = formatUsd(item.priceUsd);
          sypEl.textContent = item.rate > 0
            ? formatSyp(roundUpTo5(item.priceUsd * item.rate))
            : '—';
          sypEl.classList.toggle('is-below-cost', !!(item.purchaseUsd && item.priceUsd < item.purchaseUsd));
          priceInput.classList.toggle('is-below-cost', !!(item.purchaseUsd && item.priceUsd < item.purchaseUsd));
        }
        syncSourceRow(item);
      });
      pctInput.addEventListener('blur', () => {
        item.percent = Math.max(0.01, Math.min(95, parseLoose(pctInput.value) ?? item.percent));
        pctInput.value = formatPct(item.percent);
        if (item.saleUsd && item.saleUsd > 0) {
          item.priceUsd = Math.max(0, item.saleUsd * (1 - item.percent / 100));
          priceInput.value = formatUsd(item.priceUsd);
        }
        syncSourceRow(item);
      });
      pctLabel.appendChild(pctInput);

      const priceLabel = document.createElement('label');
      priceLabel.className = 'text-[11px] font-bold text-slate-600';
      priceLabel.textContent = 'بعد $ ';
      const priceInput = document.createElement('input');
      priceInput.type = 'text';
      priceInput.inputMode = 'decimal';
      priceInput.autocomplete = 'off';
      priceInput.className = 'w-20 h-8 rounded-lg border border-border-subtle px-2 text-sm tabular-nums ms-1';
      priceInput.value = item.priceUsd !== null ? formatUsd(item.priceUsd) : '';
      priceInput.disabled = !(item.saleUsd > 0);
      priceInput.addEventListener('input', () => {
        const after = parseLoose(priceInput.value);
        if (after === null || !(item.saleUsd > 0)) return;
        item.priceUsd = Math.max(0, Math.min(item.saleUsd, after));
        item.percent = Math.max(0, Math.min(95, Math.round((1 - item.priceUsd / item.saleUsd) * 10000) / 100));
        pctInput.value = formatPct(item.percent);
        sypEl.textContent = item.rate > 0
          ? formatSyp(roundUpTo5(item.priceUsd * item.rate))
          : '—';
        const below = !!(item.purchaseUsd && item.priceUsd < item.purchaseUsd);
        sypEl.classList.toggle('is-below-cost', below);
        priceInput.classList.toggle('is-below-cost', below);
        syncSourceRow(item);
      });
      priceInput.addEventListener('blur', () => {
        if (!(item.saleUsd > 0)) return;
        item.priceUsd = Math.max(0, Math.min(item.saleUsd, parseLoose(priceInput.value) ?? item.priceUsd ?? 0));
        priceInput.value = formatUsd(item.priceUsd);
        item.percent = Math.max(0, Math.min(95, Math.round((1 - item.priceUsd / item.saleUsd) * 10000) / 100));
        pctInput.value = formatPct(item.percent);
        syncSourceRow(item);
      });
      priceLabel.appendChild(priceInput);

      const sypEl = document.createElement('span');
      sypEl.className = 'text-xs font-extrabold tabular-nums text-emerald-800 min-w-[4rem]';
      sypEl.dir = 'ltr';
      if (item.priceUsd !== null && item.rate > 0) {
        sypEl.textContent = formatSyp(roundUpTo5(item.priceUsd * item.rate)) + ' ل.س';
        if (item.purchaseUsd && item.priceUsd < item.purchaseUsd) {
          sypEl.classList.add('is-below-cost');
          priceInput.classList.add('is-below-cost');
        }
      } else {
        sypEl.textContent = '—';
      }

      const guidHidden = document.createElement('input');
      guidHidden.type = 'hidden';
      guidHidden.name = 'material_guids[]';
      guidHidden.value = item.guid;

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'h-8 px-2 rounded-lg border border-border-subtle text-xs font-bold text-slate-600 hover:bg-slate-50';
      removeBtn.textContent = 'إزالة';
      removeBtn.addEventListener('click', () => removeCandidate(item.guid));

      row.append(thumb, title, pctLabel, priceLabel, sypEl, guidHidden, removeBtn);
      list.appendChild(row);
    });
  }

  function syncSourceRow(item) {
    const row = document.querySelector('[data-stagnant-card][data-material-guid="' + item.guid + '"]');
    if (!(row instanceof HTMLElement)) return;
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (pctInput instanceof HTMLInputElement) {
      pctInput.value = formatPct(item.percent);
      pctInput.dataset.touched = '1';
    }
    if (priceInput instanceof HTMLInputElement && item.priceUsd !== null) {
      priceInput.value = formatUsd(item.priceUsd);
    }
    updateSypPreview(row, item.priceUsd);
  }

  function syncPreviewSelectFromRow() {
    // Preview uses Add button now; no checkbox sync.
  }

  function syncPreviewPricingFromRow(options = {}) {
    if (syncingPreview) return;
    const { skipSource = null } = options;
    const modal = previewModal();
    if (!modal || !isPreviewOpen() || !activeRow) return;
    syncingPreview = true;
    try {
      const pctInput = activeRow.querySelector('[data-stagnant-item-discount]');
      const priceInput = activeRow.querySelector('[data-stagnant-item-price]');
      const sypEl = activeRow.querySelector('[data-stagnant-item-syp]');
      const previewPct = modal.querySelector('[data-stagnant-preview-discount]');
      const previewPrice = modal.querySelector('[data-stagnant-preview-price]');
      const previewSyp = modal.querySelector('[data-stagnant-preview-syp]');
      if (previewPct instanceof HTMLInputElement && pctInput instanceof HTMLInputElement && skipSource !== previewPct) {
        previewPct.value = pctInput.value;
      }
      if (previewPrice instanceof HTMLInputElement) {
        if (priceInput instanceof HTMLInputElement && !priceInput.disabled) {
          previewPrice.disabled = false;
          if (skipSource !== previewPrice) previewPrice.value = priceInput.value;
        } else {
          previewPrice.disabled = true;
          previewPrice.value = '';
        }
      }
      if (previewSyp instanceof HTMLElement) {
        previewSyp.textContent = sypEl instanceof HTMLElement ? sypEl.textContent || '—' : '—';
      }
      applyBelowCost(activeRow, priceInput instanceof HTMLInputElement ? parseLoose(priceInput.value) : null);
      const addBtn = modal.querySelector('[data-stagnant-preview-add]');
      if (addBtn instanceof HTMLButtonElement) {
        const guid = activeRow.getAttribute('data-material-guid') || '';
        const already = guid !== '' && candidates.has(guid);
        addBtn.textContent = already ? 'مُضافة للمرشّحين' : 'إضافة إلى المرشّحين';
        addBtn.disabled = already;
      }
    } finally {
      syncingPreview = false;
    }
  }

  function updateNavControls() {
    const modal = previewModal();
    if (!modal) return;
    const rows = allRows();
    const index = activeRow ? rows.indexOf(activeRow) : -1;
    const prevBtn = modal.querySelector('[data-stagnant-preview-prev]');
    const nextBtn = modal.querySelector('[data-stagnant-preview-next]');
    const pos = modal.querySelector('[data-stagnant-preview-position]');
    if (pos instanceof HTMLElement) pos.textContent = index >= 0 ? `${index + 1} / ${rows.length}` : '';
    if (prevBtn instanceof HTMLButtonElement) prevBtn.disabled = index <= 0;
    if (nextBtn instanceof HTMLButtonElement) nextBtn.disabled = index < 0 || index >= rows.length - 1;
  }

  function closePreview() {
    const modal = previewModal();
    if (!modal) return;
    modal.hidden = true;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    activeRow = null;
  }

  function openPreview(row) {
    const modal = previewModal();
    if (!modal || !(row instanceof HTMLElement)) return;
    activeRow = row;

    const setText = (sel, text) => {
      const el = modal.querySelector(sel);
      if (el instanceof HTMLElement) el.textContent = text;
    };
    setText('[data-stagnant-preview-name]', row.getAttribute('data-name') || '');
    setText('[data-stagnant-preview-code]', row.getAttribute('data-code') || '');
    setText('[data-stagnant-preview-meta]', row.getAttribute('data-meta') || '');
    const qty = row.getAttribute('data-qty') || '';
    setText('[data-stagnant-preview-qty]', qty !== '' ? Number(qty).toLocaleString('en-US') : '—');
    setText('[data-stagnant-preview-age]', row.getAttribute('data-age') || '—');
    setText('[data-stagnant-preview-sale-usd]', formatMoney(row.getAttribute('data-sale-usd')));
    setText('[data-stagnant-preview-sale-syp]', formatMoney(row.getAttribute('data-sale-syp'), 0));
    setText('[data-stagnant-preview-purchase]', formatMoney(row.getAttribute('data-purchase-usd')));

    const fullUrl = row.getAttribute('data-image-full') || '';
    const thumbUrl = row.getAttribute('data-image-thumb') || '';
    const img = modal.querySelector('[data-stagnant-preview-image]');
    const empty = modal.querySelector('[data-stagnant-preview-image-empty]');
    if (img instanceof HTMLImageElement) {
      if (fullUrl) {
        img.classList.remove('hidden');
        if (empty instanceof HTMLElement) empty.classList.add('hidden');
        const prefer = row.querySelector('img');
        if (window.StoreImageZoom?.loadProgressive) {
          window.StoreImageZoom.loadProgressive(img, fullUrl, thumbUrl || fullUrl, {
            preferElement: prefer instanceof HTMLImageElement ? prefer : undefined,
          });
        } else {
          img.src = fullUrl;
        }
        img.alt = row.getAttribute('data-name') || '';
      } else {
        img.removeAttribute('src');
        img.classList.add('hidden');
        if (empty instanceof HTMLElement) empty.classList.remove('hidden');
      }
    }

    modal.hidden = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    syncPreviewPricingFromRow();
    updateNavControls();
  }

  function stepPreview(delta) {
    const rows = allRows();
    if (rows.length === 0) {
      closePreview();
      return;
    }
    let index = activeRow ? rows.indexOf(activeRow) : -1;
    if (index < 0) index = delta > 0 ? -1 : 0;
    const next = rows[index + delta];
    if (next) openPreview(next);
  }

  function bindPreview(form) {
    const modal = previewModal();
    if (!modal) return;
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    if (modal.getAttribute(BOUND) === '1') return;
    modal.setAttribute(BOUND, '1');

    form.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.closest('[data-stagnant-add]')) return;
      const trigger = target.closest('[data-stagnant-preview]');
      if (!(trigger instanceof HTMLElement)) return;
      const row = trigger.closest('[data-stagnant-card]');
      if (!(row instanceof HTMLElement)) return;
      event.preventDefault();
      openPreview(row);
    });

    modal.querySelectorAll('[data-stagnant-preview-close]').forEach((el) => {
      el.addEventListener('click', closePreview);
    });
    modal.querySelector('[data-stagnant-preview-prev]')?.addEventListener('click', () => stepPreview(-1));
    modal.querySelector('[data-stagnant-preview-next]')?.addEventListener('click', () => stepPreview(1));
    modal.querySelector('[data-stagnant-preview-add]')?.addEventListener('click', () => {
      if (!activeRow) return;
      const current = activeRow;
      const rows = allRows();
      const index = rows.indexOf(current);
      addCandidateFromRow(current);
      const next = index >= 0 ? rows[index + 1] : null;
      if (next) openPreview(next);
      else closePreview();
    });

    document.addEventListener('keydown', (event) => {
      if (!isPreviewOpen()) return;
      const tag = event.target instanceof HTMLElement ? event.target.tagName : '';
      const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
      if (event.key === 'Escape') {
        closePreview();
        return;
      }
      if (typing) return;
      if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
        event.preventDefault();
        stepPreview(-1);
      } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
        event.preventDefault();
        stepPreview(1);
      }
    });

    const previewPct = modal.querySelector('[data-stagnant-preview-discount]');
    if (previewPct instanceof HTMLInputElement) {
      previewPct.addEventListener('input', () => {
        if (!activeRow || syncingPreview) return;
        syncRowFromPercent(activeRow, previewPct.value, { markTouched: true, source: previewPct });
      });
      previewPct.addEventListener('blur', () => {
        if (!activeRow) return;
        syncRowFromPercent(activeRow, previewPct.value, { markTouched: true, source: previewPct, commit: true });
      });
    }
    const previewPrice = modal.querySelector('[data-stagnant-preview-price]');
    if (previewPrice instanceof HTMLInputElement) {
      previewPrice.addEventListener('input', () => {
        if (!activeRow || syncingPreview) return;
        syncRowFromPrice(activeRow, previewPrice.value, { source: previewPrice });
      });
      previewPrice.addEventListener('blur', () => {
        if (!activeRow) return;
        syncRowFromPrice(activeRow, previewPrice.value, { source: previewPrice, commit: true });
      });
    }
  }

  function init(root = document) {
    const form = root.querySelector?.('#stagnant-offer-form') || document.getElementById('stagnant-offer-form');
    if (!form) return;
    if (form.getAttribute(BOUND) === '1') {
      syncSubmitState(document);
      return;
    }
    form.setAttribute(BOUND, '1');

    form.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const modeBtn = target.closest('[data-stagnant-mode]');
      if (modeBtn instanceof HTMLButtonElement) {
        const modeInput = form.querySelector('[data-stagnant-save-mode]');
        if (modeInput instanceof HTMLInputElement) {
          modeInput.value = modeBtn.getAttribute('data-stagnant-mode') || 'draft';
        }
      }
      const addBtn = target.closest('[data-stagnant-add]');
      if (addBtn instanceof HTMLButtonElement) {
        const row = addBtn.closest('[data-stagnant-card]');
        if (row instanceof HTMLElement) addCandidateFromRow(row);
      }
    });

    const defaultDiscount = form.querySelector('[data-stagnant-default-discount]');
    if (defaultDiscount instanceof HTMLInputElement) {
      defaultDiscount.addEventListener('input', () => applyDefaultDiscount(form));
      defaultDiscount.addEventListener('change', () => applyDefaultDiscount(form));
    }

    form.querySelectorAll('[data-stagnant-card]').forEach((row) => {
      const pctInput = row.querySelector('[data-stagnant-item-discount]');
      const priceInput = row.querySelector('[data-stagnant-item-price]');
      if (pctInput instanceof HTMLInputElement) {
        pctInput.addEventListener('input', () => {
          syncRowFromPercent(row, pctInput.value, { markTouched: true, source: pctInput });
        });
        pctInput.addEventListener('blur', () => {
          syncRowFromPercent(row, pctInput.value, { markTouched: true, source: pctInput, commit: true });
        });
      }
      if (priceInput instanceof HTMLInputElement) {
        priceInput.addEventListener('input', () => {
          syncRowFromPrice(row, priceInput.value, { source: priceInput });
        });
        priceInput.addEventListener('blur', () => {
          syncRowFromPrice(row, priceInput.value, { source: priceInput, commit: true });
        });
      }
    });

    document.querySelector('[data-stagnant-add-visible]')?.addEventListener('click', () => {
      allRows().forEach((row) => {
        const guid = (row.getAttribute('data-material-guid') || '').toLowerCase();
        if (!guid || candidates.has(guid)) return;
        addCandidateFromRow(row);
      });
    });
    document.querySelector('[data-stagnant-clear-tray]')?.addEventListener('click', () => {
      Array.from(candidates.keys()).forEach((guid) => removeCandidate(guid));
    });

    bindPreview(form);
    applyDefaultDiscount(form);
    renderTray();
    syncSubmitState(document);
  }

  window.portalStagnantInventoryInit = (root = document) => {
    const existing = document.getElementById('stagnant-offer-form');
    if (!existing) return;
    if (existing.getAttribute(BOUND) !== '1') {
      init(root);
      return;
    }
    syncSubmitState(document);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(document));
  } else {
    init(document);
  }
})();
