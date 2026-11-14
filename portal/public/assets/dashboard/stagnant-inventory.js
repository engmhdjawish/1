/**
 * Stagnant inventory — table rows, bidirectional % ↔ USD price,
 * SYP preview from discounted USD (round up to 5), draft/activate, image zoom.
 */
(function () {
  const BOUND = 'data-stagnant-bound';

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

  function selectedChecks(root = document) {
    return root.querySelectorAll('[data-stagnant-check]:checked');
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
      return;
    }

    const after = Math.max(0, saleUsd * (1 - pct / 100));
    priceInput.value = formatUsd(after);
    updateSypPreview(row, after);
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

  function bindImageZoom(root = document) {
    if (window.StoreImageZoom && typeof window.StoreImageZoom.bind === 'function') {
      window.StoreImageZoom.bind(root);
    }
  }

  function init(root = document) {
    const form = root.querySelector?.('#stagnant-offer-form') || document.getElementById('stagnant-offer-form');
    if (!form) return;

    if (form.getAttribute(BOUND) === '1') {
      sync(document);
      bindImageZoom(document);
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

    applyDefaultDiscount(form);
    sync(document);
    bindImageZoom(document);
  }

  window.portalStagnantInventoryInit = (root = document) => {
    const existing = document.getElementById('stagnant-offer-form');
    if (!existing) return;
    if (existing.getAttribute(BOUND) !== '1') {
      init(root);
      return;
    }
    sync(document);
    bindImageZoom(document);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(document));
  } else {
    init(document);
  }
})();
