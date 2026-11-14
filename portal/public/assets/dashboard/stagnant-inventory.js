/**
 * Stagnant inventory — table rows, bidirectional % ↔ USD price,
 * large-image preview with select + discount controls,
 * SYP preview from discounted USD (round up to 5).
 */
(function () {
  const BOUND = 'data-stagnant-bound';
  let activeRow = null;

  function roundUpTo5(amount) {
    if (!(amount > 0)) return 0;
    return Math.ceil(amount / 5) * 5;
  }

  function parseNum(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }

  function formatUsd(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function formatSyp(n) {
    return Math.round(n).toLocaleString('en-US');
  }

  function formatMoney(value, decimals = 2) {
    const n = parseNum(value);
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

  function rowSaleUsd(row) {
    return parseNum(row.getAttribute('data-sale-usd'));
  }

  function rowRate(row) {
    return parseNum(row.getAttribute('data-rate')) || 0;
  }

  function updateSypPreview(row, saleUsdAfter) {
    const sypEl = row.querySelector('[data-stagnant-item-syp]');
    if (!(sypEl instanceof HTMLElement)) return;
    const rate = rowRate(row);
    if (!(saleUsdAfter > 0) || !(rate > 0)) {
      sypEl.textContent = '—';
      return;
    }
    sypEl.textContent = formatSyp(roundUpTo5(saleUsdAfter * rate));
  }

  function syncRowFromPercent(row, percent, markTouched) {
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (!(pctInput instanceof HTMLInputElement)) return;

    const pct = Math.max(0, Math.min(95, Number(percent) || 0));
    pctInput.value = String(pct);
    if (markTouched) pctInput.dataset.touched = '1';

    const saleUsd = rowSaleUsd(row);
    if (!(priceInput instanceof HTMLInputElement) || !(saleUsd > 0)) {
      updateSypPreview(row, null);
      syncPreviewPricingFromRow();
      return;
    }

    const after = Math.max(0, saleUsd * (1 - pct / 100));
    priceInput.value = formatUsd(after);
    updateSypPreview(row, after);
    syncPreviewPricingFromRow();
  }

  function syncRowFromPrice(row, priceValue) {
    const pctInput = row.querySelector('[data-stagnant-item-discount]');
    const priceInput = row.querySelector('[data-stagnant-item-price]');
    if (!(pctInput instanceof HTMLInputElement) || !(priceInput instanceof HTMLInputElement)) return;

    const saleUsd = rowSaleUsd(row);
    if (!(saleUsd > 0)) return;

    let after = parseNum(priceValue);
    if (after === null) return;
    after = Math.max(0, Math.min(saleUsd, after));
    priceInput.value = formatUsd(after);

    const pct = Math.max(0, Math.min(95, Math.round((1 - after / saleUsd) * 10000) / 100));
    pctInput.value = String(pct);
    pctInput.dataset.touched = '1';
    updateSypPreview(row, after);
    syncPreviewPricingFromRow();
  }

  function applyDefaultDiscount(root = document) {
    const defaultInput = root.querySelector('[data-stagnant-default-discount]');
    if (!(defaultInput instanceof HTMLInputElement)) return;
    const value = defaultInput.value;
    root.querySelectorAll('[data-stagnant-card]').forEach((row) => {
      const pctInput = row.querySelector('[data-stagnant-item-discount]');
      if (!(pctInput instanceof HTMLInputElement)) return;
      if (pctInput.dataset.touched) return;
      syncRowFromPercent(row, value, false);
    });
  }

  function syncPreviewSelectFromRow() {
    const modal = previewModal();
    if (!modal || modal.hidden || !activeRow) return;
    const select = modal.querySelector('[data-stagnant-preview-select]');
    const check = activeRow.querySelector('[data-stagnant-check]');
    if (!(select instanceof HTMLInputElement) || !(check instanceof HTMLInputElement)) return;
    select.checked = check.checked;
  }

  function syncPreviewPricingFromRow() {
    const modal = previewModal();
    if (!modal || modal.hidden || !activeRow) return;

    const pctInput = activeRow.querySelector('[data-stagnant-item-discount]');
    const priceInput = activeRow.querySelector('[data-stagnant-item-price]');
    const sypEl = activeRow.querySelector('[data-stagnant-item-syp]');
    const previewPct = modal.querySelector('[data-stagnant-preview-discount]');
    const previewPrice = modal.querySelector('[data-stagnant-preview-price]');
    const previewSyp = modal.querySelector('[data-stagnant-preview-syp]');

    if (previewPct instanceof HTMLInputElement && pctInput instanceof HTMLInputElement) {
      previewPct.value = pctInput.value;
    }
    if (previewPrice instanceof HTMLInputElement) {
      if (priceInput instanceof HTMLInputElement && !priceInput.disabled) {
        previewPrice.disabled = false;
        previewPrice.value = priceInput.value;
      } else {
        previewPrice.disabled = true;
        previewPrice.value = '';
      }
    }
    if (previewSyp instanceof HTMLElement) {
      previewSyp.textContent = sypEl instanceof HTMLElement ? sypEl.textContent || '—' : '—';
    }
  }

  function closePreview() {
    const modal = previewModal();
    if (!modal) return;
    modal.hidden = true;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
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

    syncPreviewSelectFromRow();
    syncPreviewPricingFromRow();

    modal.hidden = false;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function bindPreview(form) {
    const modal = previewModal();
    if (!modal || modal.getAttribute(BOUND) === '1') return;
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

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && modal && !modal.hidden) {
        closePreview();
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
        if (!activeRow) return;
        syncRowFromPercent(activeRow, previewPct.value, true);
      });
    }

    const previewPrice = modal.querySelector('[data-stagnant-preview-price]');
    if (previewPrice instanceof HTMLInputElement) {
      previewPrice.addEventListener('input', () => {
        if (!activeRow) return;
        syncRowFromPrice(activeRow, previewPrice.value);
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
          syncRowFromPercent(row, pctInput.value, true);
        });
      }
      if (priceInput instanceof HTMLInputElement) {
        priceInput.addEventListener('input', () => {
          syncRowFromPrice(row, priceInput.value);
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
