/**
 * Stagnant inventory decision tool — selection counter + select-all helpers.
 */
(function () {
  const BOUND = 'data-stagnant-bound';

  function sync(root) {
    const selected = root.querySelectorAll('[data-stagnant-check]:checked');
    root.querySelectorAll('[data-stagnant-selected-count]').forEach((el) => {
      el.textContent = String(selected.length);
    });
    root.querySelectorAll('[data-stagnant-submit]').forEach((btn) => {
      if (btn instanceof HTMLButtonElement) {
        btn.disabled = selected.length === 0;
      }
    });
  }

  function init(root = document) {
    const form = root.querySelector?.('#stagnant-offer-form') || document.getElementById('stagnant-offer-form');
    if (!form || form.getAttribute(BOUND) === '1') {
      if (form) sync(document);
      return;
    }
    form.setAttribute(BOUND, '1');

    form.addEventListener('change', (event) => {
      if (event.target && event.target.matches?.('[data-stagnant-check]')) {
        sync(document);
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

    sync(document);
  }

  window.portalStagnantInventoryInit = (root = document) => {
    // After AJAX nav the form is new; clear bound flag if missing form replaced.
    const existing = document.getElementById('stagnant-offer-form');
    if (existing && existing.getAttribute(BOUND) !== '1') {
      init(root);
      return;
    }
    if (!existing) {
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
