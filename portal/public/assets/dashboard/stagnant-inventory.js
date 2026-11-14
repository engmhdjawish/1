/**
 * Stagnant inventory — table + large preview with nav,
 * bidirectional % ↔ USD (type freely; format on blur),
 * SYP from discounted USD (round up to 5),
 * red warning when sale-after-discount < purchase.
 */
(function () {
  const BOUND = 'data-stagnant-bound';
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
    const rounded = Math.round(n * 100) / 100;
    return String(rounded);
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

  function selectedChecks(root = document) {
    return root.querySelectorAll('[data-stagnant-check]:checked');
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

  function rowSaleUsd(row) {
    return parseLoose(row.getAttribute('data-sale-usd'));
  }

  function rowPurchaseUsd(row) {
    return parseLoose(row.getAttribute('data-purchase-usd'));
  }

  function rowRate(row) {
    return parseLoose(row.getAttribute('data-rate')) || 0;
  }

  function sync(root = document) {
    const selected = selectedChecks(root);
    const count = selected.length;

    root.querySelectorAll('[data-stagnant-selected-count]').forEach((el) => {
      el.textContent = String(count);
    });
    root.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (btn instanceof HTMLButtonElement) {
        btn.disabled = count === 0;
      }
    });

    root.querySelectorAll('[data-stagnant-card]').forEach((card) => {
      const check = card.querySelector('[data-stagnant-check]');
      const on = check instanceof HTMLInputElement && check.checked;
      card.classList.toggle('bg-primary/[0.04]', on);
      card.classList.toggle('ring-1', on);
      card.classList.toggle('ring-inset', on);
      card.classList.toggle('ring-primary/30', on);
    });

    syncPreviewSelectFromRow();
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
      priceInput.title = below
        ? 'السعر بعد الخصم أقل من سعر الشراء'
        : 'سعر البيع بالدولار بعد الخصم';
    }
    if (sypEl instanceof HTMLElement) {
      sypEl.classList.toggle('is-below-cost', below);
      if (!below) {
        sypEl.classList.add('text-emerald-800');
      } else {
        sypEl.classList.remove('text-emerald-800');
      }
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
      if (previewPrice instanceof HTMLElement) {
        previewPrice.classList.toggle('is-below-cost', below);
      }
      if (sypBox instanceof HTMLElement) {
        sypBox.classList.toggle('is-below-cost', below);
      }
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

  /**
   * @param {HTMLElement} row
   * @param {string|number} percent
   * @param {{ markTouched?: boolean, source?: HTMLElement|null, commit?: boolean }} options
   */
  function syncRowFromPercent(row, percent, options = {}) {
    const { markTouched = false, source = null, commit = false } = options;
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (!(pctInput instanceof HTMLInputElement)) return;

    const raw = String(percent ?? '').trim();
    const parsed = parseLoose(raw);
    if (parsed === null && !commit) {
      // Allow intermediate typing in % without wiping the price yet.
      if (source !== pctInput) pctInput.value = raw;
      syncPreviewPricingFromRow({ skipSource: source });
      return;
    }

    const pct = Math.max(0, Math.min(95, parsed ?? 0));
    if (commit || source !== pctInput) {
      pctInput.value = formatPct(pct);
    } else if (raw === '') {
      // keep empty while typing
    } else if (source !== pctInput) {
      pctInput.value = formatPct(pct);
    }
    if (markTouched) pctInput.dataset.touched = '1';

    const saleUsd = rowSaleUsd(row);
    if (!(priceInput instanceof HTMLInputElement) || !(saleUsd > 0) || parsed === null) {
      updateSypPreview(row, null);
      syncPreviewPricingFromRow({ skipSource: source });
      return;
    }

    const after = Math.max(0, saleUsd * (1 - pct / 100));
    if (source !== priceInput) {
      priceInput.value = formatUsd(after);
    }
    updateSypPreview(row, after);
    syncPreviewPricingFromRow({ skipSource: source });
  }

  /**
   * @param {HTMLElement} row
   * @param {string|number} priceValue
   * @param {{ source?: HTMLElement|null, commit?: boolean }} options
   */
  function syncRowFromPrice(row, priceValue, options = {}) {
    const { source = null, commit = false } = options;
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (!(pctInput instanceof HTMLInputElement) || !(priceInput instanceof HTMLInputElement)) return;

    const saleUsd = rowSaleUsd(row);
    if (!(saleUsd > 0)) return;

    const raw = String(priceValue ?? '').trim();
    const parsed = parseLoose(raw);
    if (parsed === null && !commit) {
      syncPreviewPricingFromRow({ skipSource: source });
      return;
    }

    let after = parsed === null ? 0 : parsed;
    after = Math.max(0, Math.min(saleUsd, after));

    if (commit || source !== priceInput) {
      priceInput.value = formatUsd(after);
    }

    const pct = Math.max(0, Math.min(95, Math.round((1 - after / saleUsd) * 10000) / 100));
    if (source !== pctInput) {
      pctInput.value = formatPct(pct);
    }
    pctInput.dataset.touched = '1';
    updateSypPreview(row, after);
    syncPreviewPricingFromRow({ skipSource: source });
  }

  function applyDefaultDiscount(root = document) {
    const defaultInput = root.querySelector('[data-stagnant-default-discount]');
    if (!(defaultInput instanceof HTMLInputElement)) return;
    const value = defaultInput.value;
    root.querySelectorAll('[data-stagnant-card]').forEach((row) => {
      const pctInput = row.querySelector('[data-stagnant-item-discount]');
      if (!(pctInput instanceof HTMLInputElement)) return;
      if (pctInput.dataset.touched) return;
      syncRowFromPercent(row, value, { markTouched: false, commit: true });
    });
  }

  function syncPreviewSelectFromRow() {
    const modal = previewModal();
    if (!modal || !isPreviewOpen() || !activeRow) return;
    const select = modal.querySelector('[data-stagnant-preview-select]');
    const check = activeRow.querySelector('[data-stagnant-check]');
    if (!(select instanceof HTMLInputElement) || !(check instanceof HTMLInputElement)) return;
    select.checked = check.checked;
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
          if (skipSource !== previewPrice) {
            previewPrice.value = priceInput.value;
          }
        } else {
          previewPrice.disabled = true;
          previewPrice.value = '';
        }
      }
      if (previewSyp instanceof HTMLElement) {
        previewSyp.textContent = sypEl instanceof HTMLElement ? sypEl.textContent || '—' : '—';
      }

      const after = priceInput instanceof HTMLInputElement ? parseLoose(priceInput.value) : null;
      applyBelowCost(activeRow, after);
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

    if (pos instanceof HTMLElement) {
      pos.textContent = index >= 0 ? `${index + 1} / ${rows.length}` : '';
    }
    if (prevBtn instanceof HTMLButtonElement) {
      prevBtn.disabled = index <= 0;
    }
    if (nextBtn instanceof HTMLButtonElement) {
      nextBtn.disabled = index < 0 || index >= rows.length - 1;
    }
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

    const name = row.getAttribute('data-name') || '';
    const code = row.getAttribute('data-code') || '';
    const meta = row.getAttribute('data-meta') || '';
    const qty = row.getAttribute('data-qty') || '';
    const age = row.getAttribute('data-age') || '';
    const saleUsd = row.getAttribute('data-sale-usd') || '';
    const saleSyp = row.getAttribute('data-sale-syp') || '';
    const purchase = row.getAttribute('data-purchase-usd') || '';
    const fullUrl = row.getAttribute('data-image-full') || '';
    const thumbUrl = row.getAttribute('data-image-thumb') || '';

    const setText = (sel, text) => {
      const el = modal.querySelector(sel);
      if (el instanceof HTMLElement) el.textContent = text;
    };

    setText('[data-stagnant-preview-name]', name);
    setText('[data-stagnant-preview-code]', code);
    setText('[data-stagnant-preview-meta]', meta);
    setText('[data-stagnant-preview-qty]', qty !== '' ? Number(qty).toLocaleString('en-US') : '—');
    setText('[data-stagnant-preview-age]', age || '—');
    setText('[data-stagnant-preview-sale-usd]', formatMoney(saleUsd));
    setText('[data-stagnant-preview-sale-syp]', formatMoney(saleSyp, 0));
    setText('[data-stagnant-preview-purchase]', formatMoney(purchase));

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
        img.alt = name;
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

    syncPreviewSelectFromRow();
    syncPreviewPricingFromRow();
    updateNavControls();
  }

  function stepPreview(delta) {
    const rows = allRows();
    if (!activeRow || rows.length === 0) return;
    const index = rows.indexOf(activeRow);
    if (index < 0) return;
    const next = rows[index + delta];
    if (next) openPreview(next);
  }

  function bindPreview(form) {
    const modal = previewModal();
    if (!modal) return;

    if (modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }

    if (modal.getAttribute(BOUND) === '1') return;
    modal.setAttribute(BOUND, '1');

    form.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
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

    const prevBtn = modal.querySelector('[data-stagnant-preview-prev]');
    const nextBtn = modal.querySelector('[data-stagnant-preview-next]');
    if (prevBtn instanceof HTMLButtonElement) {
      prevBtn.addEventListener('click', () => stepPreview(-1));
    }
    if (nextBtn instanceof HTMLButtonElement) {
      nextBtn.addEventListener('click', () => stepPreview(1));
    }

    document.addEventListener('keydown', (event) => {
      if (!isPreviewOpen()) return;
      const tag = event.target instanceof HTMLElement ? event.target.tagName : '';
      const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
        || (event.target instanceof HTMLElement && event.target.isContentEditable);

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

    const select = modal.querySelector('[data-stagnant-preview-select]');
    if (select instanceof HTMLInputElement) {
      select.addEventListener('change', () => {
        if (!activeRow) return;
        const check = activeRow.querySelector('[data-stagnant-check]');
        if (!(check instanceof HTMLInputElement)) return;
        check.checked = select.checked;
        sync(document);
      });
    }

    const previewPct = modal.querySelector('[data-stagnant-preview-discount]');
    if (previewPct instanceof HTMLInputElement) {
      previewPct.addEventListener('input', () => {
        if (!activeRow || syncingPreview) return;
        syncRowFromPercent(activeRow, previewPct.value, {
          markTouched: true,
          source: previewPct,
        });
      });
      previewPct.addEventListener('blur', () => {
        if (!activeRow) return;
        syncRowFromPercent(activeRow, previewPct.value, {
          markTouched: true,
          source: previewPct,
          commit: true,
        });
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
        syncRowFromPrice(activeRow, previewPrice.value, {
          source: previewPrice,
          commit: true,
        });
      });
    }
  }

  function init(root = document) {
    const form = root.querySelector?.('#stagnant-offer-form') || document.getElementById('stagnant-offer-form');
    if (!form) return;

    if (form.getAttribute(BOUND) === '1') {
      sync(document);
      return;
    }
    form.setAttribute(BOUND, '1');

    form.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.matches('[data-stagnant-check]')) {
        sync(document);
      }
    });

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
          syncRowFromPercent(row, pctInput.value, {
            markTouched: true,
            source: pctInput,
          });
        });
        pctInput.addEventListener('blur', () => {
          syncRowFromPercent(row, pctInput.value, {
            markTouched: true,
            source: pctInput,
            commit: true,
          });
        });
      }
      if (priceInput instanceof HTMLInputElement) {
        priceInput.addEventListener('input', () => {
          syncRowFromPrice(row, priceInput.value, { source: priceInput });
        });
        priceInput.addEventListener('blur', () => {
          syncRowFromPrice(row, priceInput.value, {
            source: priceInput,
            commit: true,
          });
        });
      }
    });

    document.querySelectorAll('[data-stagnant-select-all]').forEach((btn) => {
      if (btn.getAttribute(BOUND) === '1') return;
      btn.setAttribute(BOUND, '1');
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-stagnant-check]').forEach((el) => {
          if (el instanceof HTMLInputElement) el.checked = true;
        });
        sync(document);
      });
    });

    document.querySelectorAll('[data-stagnant-clear]').forEach((btn) => {
      if (btn.getAttribute(BOUND) === '1') return;
      btn.setAttribute(BOUND, '1');
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-stagnant-check]').forEach((el) => {
          if (el instanceof HTMLInputElement) el.checked = false;
        });
        sync(document);
      });
    });

    bindPreview(form);
    applyDefaultDiscount(form);
    sync(document);
  }

  window.portalStagnantInventoryInit = (root = document) => {
    const existing = document.getElementById('stagnant-offer-form');
    if (!existing) return;
    if (existing.getAttribute(BOUND) !== '1') {
      init(root);
      return;
    }
    sync(document);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(document));
  } else {
    init(document);
  }
})();
