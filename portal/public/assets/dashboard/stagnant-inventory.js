/**
 * Stagnant inventory — add-to-candidate tray, bidirectional % ↔ USD,
 * large preview with nav, SYP round-up-to-5, below-cost warning.
 */
(function () {
  const BOUND = 'data-stagnant-bound';
  const TRAY_SAVED_SESSION_KEY = 'portal.stagnant-offer-saved';
  const TRAY_CANDIDATES_SESSION_KEY = 'portal.stagnant-offer-candidates';
  /** @type {Map<string, {guid:string, name:string, code:string, image:string, saleUsd:number|null, purchaseUsd:number|null, rate:number, percent:number, priceUsd:number|null, packages:number|null}>} */
  const candidates = new Map();
  /** Insertion order for tray rows — always append new candidates at the end. */
  /** @type {string[]} */
  const candidateOrder = [];
  let activeRow = null;
  let syncingPreview = false;
  let savingOffer = false;

  function markOfferSavedForLaterVisit() {
    try {
      sessionStorage.setItem(TRAY_SAVED_SESSION_KEY, '1');
    } catch (_) {
      /* storage blocked */
    }
  }

  function consumeSavedTrayFlag() {
    try {
      if (sessionStorage.getItem(TRAY_SAVED_SESSION_KEY) === '1') {
        sessionStorage.removeItem(TRAY_SAVED_SESSION_KEY);
        return true;
      }
    } catch (_) {
      /* storage blocked */
    }
    return false;
  }

  function shouldClearTrayOnEntry(root = document) {
    if (consumeSavedTrayFlag()) {
      return true;
    }
    const form = root.querySelector?.('#stagnant-offer-form')
      || (root instanceof Document ? root.getElementById('stagnant-offer-form') : null)
      || document.getElementById('stagnant-offer-form');
    if (form?.getAttribute('data-stagnant-clear-tray') === '1') {
      return true;
    }
    if (typeof window !== 'undefined' && window.location.search.includes('saved=1')) {
      return true;
    }
    return false;
  }

  function persistCandidates() {
    try {
      const items = [];
      candidateOrder.forEach((guid) => {
        const item = candidates.get(guid);
        if (item) items.push(item);
      });
      if (items.length === 0) {
        sessionStorage.removeItem(TRAY_CANDIDATES_SESSION_KEY);
        return;
      }
      sessionStorage.setItem(TRAY_CANDIDATES_SESSION_KEY, JSON.stringify({
        order: candidateOrder.slice(),
        items,
      }));
    } catch (_) {
      /* storage blocked */
    }
  }

  function clearPersistedCandidates() {
    try {
      sessionStorage.removeItem(TRAY_CANDIDATES_SESSION_KEY);
    } catch (_) {
      /* storage blocked */
    }
  }

  function loadCandidatesFromSession() {
    try {
      const raw = sessionStorage.getItem(TRAY_CANDIDATES_SESSION_KEY);
      if (!raw) return false;
      const data = JSON.parse(raw);
      if (!data || !Array.isArray(data.items) || !Array.isArray(data.order)) return false;
      candidates.clear();
      candidateOrder.length = 0;
      data.items.forEach((item) => {
        if (!item || typeof item !== 'object') return;
        const guid = String(item.guid || '').toLowerCase();
        if (!guid) return;
        candidates.set(guid, {
          guid,
          name: String(item.name || guid),
          code: String(item.code || ''),
          image: String(item.image || ''),
          saleUsd: typeof item.saleUsd === 'number' ? item.saleUsd : null,
          purchaseUsd: typeof item.purchaseUsd === 'number' ? item.purchaseUsd : null,
          rate: typeof item.rate === 'number' ? item.rate : 0,
          percent: typeof item.percent === 'number' ? item.percent : 15,
          priceUsd: typeof item.priceUsd === 'number' ? item.priceUsd : null,
          packages: typeof item.packages === 'number' ? item.packages : null,
        });
      });
      data.order.forEach((guid) => {
        const key = String(guid || '').toLowerCase();
        if (key && candidates.has(key) && !candidateOrder.includes(key)) {
          candidateOrder.push(key);
        }
      });
      candidates.forEach((_item, guid) => {
        if (!candidateOrder.includes(guid)) candidateOrder.push(guid);
      });
      return candidates.size > 0;
    } catch (_) {
      return false;
    }
  }

  function restoreHiddenMaterialRows(root = document) {
    const scope = root instanceof Document ? root : root.ownerDocument || document;
    scope.querySelectorAll('[data-stagnant-card]').forEach((row) => {
      if (row instanceof HTMLElement && (row.hidden || row.classList.contains('is-in-tray'))) {
        markRowInTray(row, false);
      }
    });
  }

  /** Re-hide current-page material rows that are still in the candidates tray. */
  function hideRowsForTrayCandidates(root = document) {
    const scope = root instanceof Document ? root : root.ownerDocument || document;
    scope.querySelectorAll('[data-stagnant-card]').forEach((row) => {
      if (!(row instanceof HTMLElement)) return;
      const guid = (row.getAttribute('data-material-guid') || '').toLowerCase();
      markRowInTray(row, !!(guid && candidates.has(guid)));
    });
  }

  function clearAllCandidates(root = document) {
    candidateOrder.length = 0;
    candidates.clear();
    clearPersistedCandidates();
    restoreHiddenMaterialRows(root);
    renderTray();
    syncSubmitState(root);
    updateNavControls();
  }

  function stashOriginalSubmitLabels(form) {
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (!(btn instanceof HTMLButtonElement)) return;
      if (!btn.getAttribute('data-stagnant-original-html')) {
        btn.setAttribute('data-stagnant-original-html', btn.innerHTML || '');
      }
    });
  }

  function resetSavingUi(form) {
    savingOffer = false;
    if (!(form instanceof HTMLFormElement)) {
      form = document.getElementById('stagnant-offer-form');
    }
    if (!(form instanceof HTMLFormElement)) return;

    form.classList.remove('is-saving');
    form.setAttribute('aria-busy', 'false');
    form.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (!(btn instanceof HTMLButtonElement)) return;
      const original = btn.getAttribute('data-stagnant-original-html')
        || btn.dataset.stagnantLabel
        || '';
      if (original) {
        btn.innerHTML = original;
      }
      delete btn.dataset.stagnantLabel;
      delete btn.dataset.dashboardPrevLabel;
      delete btn.dataset.dashboardLoadingLabelApplied;
      btn.classList.remove('is-loading');
      btn.removeAttribute('aria-busy');
    });
    form.querySelectorAll('[data-stagnant-add], [data-stagnant-add-visible], [data-stagnant-clear-tray]').forEach((el) => {
      if (el instanceof HTMLButtonElement) el.disabled = false;
    });
    const hint = form.querySelector('[data-stagnant-saving-hint]')
      || document.querySelector('[data-stagnant-saving-hint]');
    if (hint instanceof HTMLElement) {
      hint.classList.add('hidden');
      hint.textContent = '';
    }
    syncSubmitState(document);
  }

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

  /** Visible materials only (candidates / leaving rows are excluded). */
  function allRows(includeHidden = false) {
    return Array.from(document.querySelectorAll('[data-stagnant-card]')).filter((row) => {
      if (!(row instanceof HTMLElement)) return false;
      if (includeHidden) return true;
      if (row.hidden || row.classList.contains('is-in-tray') || row.classList.contains('is-tray-leaving')) {
        return false;
      }
      const guid = (row.getAttribute('data-material-guid') || '').toLowerCase();
      if (guid && candidates.has(guid)) return false;
      return true;
    });
  }

  const TRAY_ANIM_MS = 240;

  function prefersReducedMotion() {
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (_) {
      return false;
    }
  }

  function afterAnim(el, ms, cb) {
    if (!(el instanceof HTMLElement) || prefersReducedMotion()) {
      cb();
      return;
    }
    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      el.removeEventListener('animationend', onEnd);
      clearTimeout(tid);
      cb();
    };
    const onEnd = (event) => {
      if (event.target === el) finish();
    };
    el.addEventListener('animationend', onEnd);
    const tid = setTimeout(finish, ms + 80);
  }

  function markRowInTray(row, inTray) {
    if (!(row instanceof HTMLElement)) return;
    row.classList.remove('is-tray-leaving', 'is-tray-entering');
    // Priority: remove from materials table while it lives in the candidates tray.
    row.hidden = !!inTray;
    row.classList.toggle('is-in-tray', inTray);
    if (inTray) row.setAttribute('data-stagnant-in-tray', '1');
    else row.removeAttribute('data-stagnant-in-tray');
    const addBtn = row.querySelector('[data-stagnant-add]');
    if (addBtn instanceof HTMLButtonElement) {
      addBtn.textContent = 'إضافة';
      addBtn.disabled = false;
      addBtn.classList.remove('is-added');
    }
    const badge = row.querySelector('[data-stagnant-tray-badge]');
    if (badge instanceof HTMLElement) {
      badge.hidden = true;
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

  function traySection() {
    return document.querySelector('[data-stagnant-tray]');
  }

  function syncTraySectionChrome() {
    const section = traySection();
    if (!(section instanceof HTMLElement)) return;
    section.classList.toggle('is-tray-empty', candidates.size === 0);
    const toggle = section.querySelector('[data-stagnant-tray-toggle]');
    if (toggle instanceof HTMLButtonElement) {
      const collapsed = section.classList.contains('is-tray-collapsed');
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
  }

  function syncSubmitState(root = document) {
    const count = candidates.size;
    root.querySelectorAll('[data-stagnant-selected-count]').forEach((el) => {
      el.textContent = String(count);
    });
    root.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (btn instanceof HTMLButtonElement) {
        btn.disabled = savingOffer || count === 0;
      }
    });
    const hint = root.querySelector('[data-stagnant-saving-hint]');
    if (hint instanceof HTMLElement) {
      hint.classList.toggle('hidden', !savingOffer);
      hint.textContent = savingOffer ? 'جاري الحفظ…' : '';
    }
  }

  function setSavingState(form, saving, submitter) {
    savingOffer = saving;
    if (!saving) {
      resetSavingUi(form);
      return;
    }
    stashOriginalSubmitLabels(form);
    form.classList.add('is-saving');
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (!(btn instanceof HTMLButtonElement)) return;
      if (!btn.dataset.stagnantLabel) {
        btn.dataset.stagnantLabel = btn.getAttribute('data-stagnant-original-html') || btn.innerHTML || '';
      }
      const label = btn.getAttribute('data-loading-label') || 'جاري الحفظ…';
      if (btn === submitter || !submitter) {
        btn.textContent = label;
      }
      btn.disabled = true;
      btn.classList.add('is-loading');
    });
    form.querySelectorAll('[data-stagnant-add], [data-stagnant-add-visible], [data-stagnant-clear-tray]').forEach((el) => {
      if (el instanceof HTMLButtonElement) el.disabled = true;
    });
    syncSubmitState(document);
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
      updateTrayItemPricing(item);
      persistCandidates();
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
      updateTrayItemPricing(item);
      persistCandidates();
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

  function rowPackages(row) {
    const raw = parseLoose(row.getAttribute('data-qty'));
    if (raw === null || raw < 0) return null;
    return Math.round(raw * 10000) / 10000;
  }

  function addCandidateFromRow(row, options = {}) {
    if (!(row instanceof HTMLElement)) return false;
    const animate = options.animate !== false && !prefersReducedMotion();
    const guid = (row.getAttribute('data-material-guid') || '').toLowerCase();
    if (!guid) return false;
    if (candidates.has(guid)) {
      markRowInTray(row, true);
      return false;
    }
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
      packages: rowPackages(row),
    });
    candidateOrder.push(guid);

    const hideMaterialRow = () => {
      markRowInTray(row, true);
    };
    if (animate) {
      row.classList.remove('is-tray-entering');
      row.classList.add('is-tray-leaving');
      afterAnim(row, TRAY_ANIM_MS, hideMaterialRow);
    } else {
      hideMaterialRow();
    }

    // Animate into candidates list — never scroll/jump the viewport here.
    appendTrayItem(candidates.get(guid), { animate });
    persistCandidates();
    syncSubmitState();
    syncTraySectionChrome();
    syncPreviewPricingFromRow();
    updateNavControls();
    return true;
  }

  function showEmptyTrayMessage(list) {
    if (!(list instanceof HTMLElement) || candidates.size > 0) return;
    // Wait until leaving rows finish — do not wipe siblings mid-animation.
    if (list.querySelector('[data-tray-guid]')) return;
    if (list.querySelector('[data-stagnant-tray-empty]')) return;
    list.innerHTML = '';
    const empty = document.createElement('p');
    empty.className = 'text-sm text-text-muted px-4 py-8 text-center';
    empty.setAttribute('data-stagnant-tray-empty', '');
    empty.textContent = 'لا مواد مرشّحة بعد — اضغط «إضافة» بجانب المادة.';
    list.appendChild(empty);
  }

  function restoreMaterialRow(row, animate) {
    if (!(row instanceof HTMLElement)) return;
    markRowInTray(row, false);
    if (!animate) return;
    row.classList.remove('is-tray-leaving');
    row.classList.add('is-tray-entering');
    afterAnim(row, TRAY_ANIM_MS, () => {
      row.classList.remove('is-tray-entering');
    });
  }

  function removeCandidate(guid, options = {}) {
    const animate = options.animate !== false && !prefersReducedMotion();
    candidates.delete(guid);
    const orderIdx = candidateOrder.indexOf(guid);
    if (orderIdx >= 0) candidateOrder.splice(orderIdx, 1);
    const row = document.querySelector('[data-stagnant-card][data-material-guid="' + guid + '"]');
    const trayRow = document.querySelector('[data-tray-guid="' + guid + '"]');
    const list = document.querySelector('[data-stagnant-tray-list]');

    const finishRemove = () => {
      trayRow?.remove();
      showEmptyTrayMessage(list);
      restoreMaterialRow(row, animate);
      if (isPreviewOpen() && activeRow === row) syncPreviewPricingFromRow();
      updateNavControls();
    };

    persistCandidates();
    syncSubmitState();
    syncTraySectionChrome();

    if (animate && trayRow instanceof HTMLElement) {
      trayRow.classList.remove('is-tray-item-enter');
      trayRow.classList.add('is-tray-item-leave');
      afterAnim(trayRow, TRAY_ANIM_MS, finishRemove);
    } else {
      finishRemove();
    }
  }

  function updateTrayItemPricing(item) {
    if (!item) return;
    const trayRow = document.querySelector('[data-tray-guid="' + item.guid + '"]');
    if (!(trayRow instanceof HTMLElement)) {
      appendTrayItem(item, { animate: false });
      return;
    }
    const pctInput = trayRow.querySelector('input[name^="material_discount"]');
    const priceInput = trayRow.querySelector('[data-tray-price]');
    const sypEl = trayRow.querySelector('[data-tray-syp]');
    if (pctInput instanceof HTMLInputElement && document.activeElement !== pctInput) {
      pctInput.value = formatPct(item.percent);
    }
    if (priceInput instanceof HTMLInputElement && document.activeElement !== priceInput) {
      priceInput.value = item.priceUsd !== null ? formatUsd(item.priceUsd) : '';
    }
    if (sypEl instanceof HTMLElement) {
      if (item.priceUsd !== null && item.rate > 0) {
        sypEl.textContent = formatSyp(roundUpTo5(item.priceUsd * item.rate)) + ' ل.س';
      } else {
        sypEl.textContent = '—';
      }
      const below = !!(item.purchaseUsd && item.priceUsd !== null && item.priceUsd < item.purchaseUsd);
      sypEl.classList.toggle('is-below-cost', below);
      if (priceInput instanceof HTMLElement) priceInput.classList.toggle('is-below-cost', below);
    }
  }

  function appendTrayItem(item, options = {}) {
    const animate = !!options.animate && !prefersReducedMotion();
    const list = document.querySelector('[data-stagnant-tray-list]');
    if (!(list instanceof HTMLElement) || !item) return;
    list.querySelector('[data-stagnant-tray-empty]')?.remove();
    if (list.querySelector('[data-tray-guid="' + item.guid + '"]')) {
      updateTrayItemPricing(item);
      return;
    }

    const row = document.createElement('div');
    row.className = 'stagnant-candidates-section__row';
    row.setAttribute('data-tray-guid', item.guid);

    const thumb = document.createElement('div');
    thumb.className = 'w-16 h-16 rounded-lg overflow-hidden bg-slate-100 border border-border-subtle flex-shrink-0';
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
    pctInput.className = 'w-[4.5rem] rounded-lg border border-border-subtle px-2 text-sm tabular-nums';
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
          ? formatSyp(roundUpTo5(item.priceUsd * item.rate)) + ' ل.س'
          : '—';
        sypEl.classList.toggle('is-below-cost', !!(item.purchaseUsd && item.priceUsd < item.purchaseUsd));
        priceInput.classList.toggle('is-below-cost', !!(item.purchaseUsd && item.priceUsd < item.purchaseUsd));
      }
      syncSourceRow(item);
      persistCandidates();
    });
    pctInput.addEventListener('blur', () => {
      item.percent = Math.max(0.01, Math.min(95, parseLoose(pctInput.value) ?? item.percent));
      pctInput.value = formatPct(item.percent);
      if (item.saleUsd && item.saleUsd > 0) {
        item.priceUsd = Math.max(0, item.saleUsd * (1 - item.percent / 100));
        priceInput.value = formatUsd(item.priceUsd);
      }
      syncSourceRow(item);
      persistCandidates();
    });
    pctLabel.appendChild(pctInput);

    const priceLabel = document.createElement('label');
    priceLabel.className = 'text-[11px] font-bold text-slate-600';
    priceLabel.textContent = 'بعد $ ';
    const priceInput = document.createElement('input');
    priceInput.type = 'text';
    priceInput.inputMode = 'decimal';
    priceInput.autocomplete = 'off';
    priceInput.className = 'w-24 rounded-lg border border-border-subtle px-2 text-sm tabular-nums';
    priceInput.setAttribute('data-tray-price', '');
    priceInput.value = item.priceUsd !== null ? formatUsd(item.priceUsd) : '';
    priceInput.disabled = !(item.saleUsd > 0);
    priceInput.addEventListener('input', () => {
      const after = parseLoose(priceInput.value);
      if (after === null || !(item.saleUsd > 0)) return;
      item.priceUsd = Math.max(0, Math.min(item.saleUsd, after));
      item.percent = Math.max(0, Math.min(95, Math.round((1 - item.priceUsd / item.saleUsd) * 10000) / 100));
      pctInput.value = formatPct(item.percent);
      sypEl.textContent = item.rate > 0
        ? formatSyp(roundUpTo5(item.priceUsd * item.rate)) + ' ل.س'
        : '—';
      const below = !!(item.purchaseUsd && item.priceUsd < item.purchaseUsd);
      sypEl.classList.toggle('is-below-cost', below);
      priceInput.classList.toggle('is-below-cost', below);
      syncSourceRow(item);
      persistCandidates();
    });
    priceInput.addEventListener('blur', () => {
      if (!(item.saleUsd > 0)) return;
      item.priceUsd = Math.max(0, Math.min(item.saleUsd, parseLoose(priceInput.value) ?? item.priceUsd ?? 0));
      priceInput.value = formatUsd(item.priceUsd);
      item.percent = Math.max(0, Math.min(95, Math.round((1 - item.priceUsd / item.saleUsd) * 10000) / 100));
      pctInput.value = formatPct(item.percent);
      syncSourceRow(item);
      persistCandidates();
    });
    priceLabel.appendChild(priceInput);

    const sypEl = document.createElement('span');
    sypEl.className = 'text-sm font-extrabold tabular-nums text-emerald-800 min-w-[5rem]';
    sypEl.dir = 'ltr';
    sypEl.setAttribute('data-tray-syp', '');
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

    // Snapshot of «كمية» packages so create_offer can persist qty_mode=fixed.
    const qtyHidden = document.createElement('input');
    qtyHidden.type = 'hidden';
    qtyHidden.name = 'material_qty_packages[' + item.guid + ']';
    qtyHidden.value = item.packages !== null && item.packages !== undefined
      ? String(item.packages)
      : '';

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'h-9 px-3 rounded-lg border border-border-subtle text-sm font-bold text-slate-600 hover:bg-slate-50';
    removeBtn.textContent = 'إزالة';
    removeBtn.addEventListener('click', () => removeCandidate(item.guid));

    row.append(thumb, title, pctLabel, priceLabel, sypEl, guidHidden, qtyHidden, removeBtn);
    list.appendChild(row);
    if (animate) {
      row.classList.add('is-tray-item-enter');
      afterAnim(row, TRAY_ANIM_MS, () => {
        row.classList.remove('is-tray-item-enter');
      });
    }
  }

  function renderTray() {
    const list = document.querySelector('[data-stagnant-tray-list]');
    if (!(list instanceof HTMLElement)) return;
    list.innerHTML = '';

    if (candidateOrder.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'text-sm text-text-muted px-4 py-8 text-center';
      empty.setAttribute('data-stagnant-tray-empty', '');
      empty.textContent = 'لا مواد مرشّحة بعد — اضغط «إضافة» بجانب المادة.';
      list.appendChild(empty);
      syncTraySectionChrome();
      return;
    }

    candidateOrder.forEach((guid) => {
      const item = candidates.get(guid);
      if (item) appendTrayItem(item, { animate: false });
    });
    syncTraySectionChrome();
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
    const rows = allRows(false);
    const index = activeRow && !activeRow.hidden ? rows.indexOf(activeRow) : -1;
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
    const rows = allRows(false);
    if (rows.length === 0) {
      closePreview();
      return;
    }
    let index = activeRow ? rows.indexOf(activeRow) : -1;
    if (index < 0) index = delta > 0 ? -1 : 0;
    const next = rows[index + delta];
    if (next) openPreview(next);
    else if (activeRow?.hidden && rows.length > 0) openPreview(rows[0]);
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
      addCandidateFromRow(current);
      const remaining = allRows(false);
      if (remaining.length > 0) openPreview(remaining[0]);
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

  /**
   * After SPA page/filter navigation or full reload: keep tray Map (or restore from
   * sessionStorage), re-render list, and re-hide current-page rows still in the tray.
   * Only clears when the offer was just saved (saved=1 / clear flag).
   */
  function hydrateTrayForCurrentDom(root = document) {
    if (shouldClearTrayOnEntry(root)) {
      candidateOrder.length = 0;
      candidates.clear();
      clearPersistedCandidates();
      restoreHiddenMaterialRows(root);
    } else if (candidates.size === 0) {
      loadCandidatesFromSession();
    } else {
      persistCandidates();
    }
    hideRowsForTrayCandidates(root);
    renderTray();
    syncSubmitState(root);
    syncTraySectionChrome();
    updateNavControls();
  }

  function init(root = document) {
    const form = root.querySelector?.('#stagnant-offer-form') || document.getElementById('stagnant-offer-form');
    if (!form) return;
    if (form.getAttribute(BOUND) === '1') {
      if (shouldClearTrayOnEntry(root)) {
        clearAllCandidates(root);
      } else {
        hideRowsForTrayCandidates(root);
        renderTray();
      }
      stashOriginalSubmitLabels(form);
      resetSavingUi(form);
      syncSubmitState(root);
      syncTraySectionChrome();
      return;
    }
    form.setAttribute(BOUND, '1');
    hydrateTrayForCurrentDom(root);
    stashOriginalSubmitLabels(form);
    resetSavingUi(form);

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

    form.addEventListener('submit', (event) => {
      if (savingOffer) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }
      if (candidates.size === 0) {
        event.preventDefault();
        return;
      }
      const submitter = event.submitter instanceof HTMLButtonElement
        ? event.submitter
        : form.querySelector('[data-stagnant-submit]:not([disabled])');
      setSavingState(form, true, submitter instanceof HTMLButtonElement ? submitter : null);
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

    form.querySelector('[data-stagnant-add-visible]')?.addEventListener('click', () => {
      allRows(false).forEach((row) => {
        const guid = (row.getAttribute('data-material-guid') || '').toLowerCase();
        if (!guid || candidates.has(guid)) return;
        addCandidateFromRow(row);
      });
    });
    form.querySelector('[data-stagnant-clear-tray]')?.addEventListener('click', () => {
      const list = form.querySelector('[data-stagnant-tray-list]');
      const trayRows = list
        ? Array.from(list.querySelectorAll('[data-tray-guid]'))
        : [];
      if (trayRows.length === 0 || prefersReducedMotion()) {
        clearAllCandidates(form);
        return;
      }
      // Animate all candidate rows out together, then clear state once.
      let remaining = trayRows.length;
      trayRows.forEach((trayRow) => {
        if (!(trayRow instanceof HTMLElement)) {
          remaining -= 1;
          return;
        }
        trayRow.classList.remove('is-tray-item-enter');
        trayRow.classList.add('is-tray-item-leave');
        afterAnim(trayRow, TRAY_ANIM_MS, () => {
          remaining -= 1;
          if (remaining <= 0) clearAllCandidates(form);
        });
      });
    });

    form.querySelector('[data-stagnant-tray-toggle]')?.addEventListener('click', () => {
      const section = traySection();
      if (!(section instanceof HTMLElement)) return;
      const collapsed = section.classList.toggle('is-tray-collapsed');
      const toggle = section.querySelector('[data-stagnant-tray-toggle]');
      if (toggle instanceof HTMLButtonElement) {
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      }
    });
    bindPreview(form);
    applyDefaultDiscount(form);
    hideRowsForTrayCandidates(form);
    renderTray();
    syncSubmitState(document);
  }

  window.portalStagnantInventoryInit = (root = document) => {
    const existing = document.getElementById('stagnant-offer-form');
    if (!existing) return;
    init(root);
  };

  document.addEventListener('dashboard:ajax-done', (event) => {
    const detail = event.detail || {};
    const form = detail.form;
    const active = document.getElementById('stagnant-offer-form');
    const isStagnantForm = form instanceof HTMLFormElement && (
      form.id === 'stagnant-offer-form'
      || form.hasAttribute('data-stagnant-offer-form')
    );
    // Always clear stuck «جاري الحفظ…» after any AJAX finish while this form was saving,
    // including ok:false / thrown fetchJson errors (HTTP 400).
    if (isStagnantForm || savingOffer || (active && active.classList.contains('is-saving'))) {
      resetSavingUi(active || form);
    }
    if (!isStagnantForm) {
      return;
    }
    if (detail.ok && detail.redirect) {
      markOfferSavedForLaterVisit();
      clearAllCandidates(document);
    }
  });

  window.addEventListener('pageshow', (event) => {
    const form = document.getElementById('stagnant-offer-form');
    if (!(form instanceof HTMLFormElement)) return;
    if (event.persisted && shouldClearTrayOnEntry(document)) {
      clearAllCandidates(document);
    }
    resetSavingUi(form);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(document));
  } else {
    init(document);
  }
})();
