/**
 * Stagnant inventory — cards, per-item discount, draft/activate, image zoom.
 */
(function () {
  const BOUND = 'data-stagnant-bound';

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
      card.classList.toggle('ring-2', on);
      card.classList.toggle('ring-primary/40', on);
      card.classList.toggle('border-primary/50', on);
      card.classList.toggle('bg-primary/[0.03]', on);
    });
  }

  function applyDefaultDiscount(root = document) {
    const defaultInput = root.querySelector('[data-stagnant-default-discount]');
    if (!(defaultInput instanceof HTMLInputElement)) return;
    const value = defaultInput.value;
    root.querySelectorAll('[data-stagnant-item-discount]').forEach((input) => {
      if (!(input instanceof HTMLInputElement)) return;
      if (!input.dataset.touched) {
        input.value = value;
      }
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

    form.querySelectorAll('[data-stagnant-item-discount]').forEach((input) => {
      if (!(input instanceof HTMLInputElement)) return;
      input.addEventListener('input', () => {
        input.dataset.touched = '1';
      });
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
