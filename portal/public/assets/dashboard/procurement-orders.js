/**
 * Procurement — order lines: client filters and material policy dialog.
 */
(function () {
  'use strict';

  const boundRoots = new WeakSet();

  window.portalProcurementOrdersInit = (root = document) => {
    const linesRoot = root.querySelector('#proc-order-lines-root');
    if (!linesRoot || boundRoots.has(linesRoot)) {
      return;
    }
    boundRoots.add(linesRoot);

    const filterQ = linesRoot.querySelector('#proc-order-filter-q');
    const filterLifecycle = linesRoot.querySelector('#proc-order-filter-lifecycle');
    const filterBelowMin = linesRoot.querySelector('#proc-order-filter-below-min');
    const filterOpenOnly = linesRoot.querySelector('#proc-order-filter-open-only');
    const filterReset = linesRoot.querySelector('#proc-order-filter-reset');
    const visibleCount = linesRoot.querySelector('#proc-order-lines-visible-count');
    const rows = () => Array.from(linesRoot.querySelectorAll('.proc-order-line'));

    const applyFilters = () => {
      const q = (filterQ?.value || '').trim().toLowerCase();
      const lc = (filterLifecycle?.value || '').trim();
      const belowOnly = !!filterBelowMin?.checked;
      const openOnly = !!filterOpenOnly?.checked;
      let shown = 0;

      rows().forEach((row) => {
        const hay = row.getAttribute('data-search') || '';
        const rowLc = row.getAttribute('data-lifecycle') || '';
        const below = row.getAttribute('data-below-min') === '1';
        const open = row.getAttribute('data-line-open') === '1';

        let visible = true;
        if (q !== '' && !hay.includes(q)) visible = false;
        if (lc !== '' && rowLc !== lc) visible = false;
        if (belowOnly && !below) visible = false;
        if (openOnly && !open) visible = false;

        row.classList.toggle('hidden', !visible);
        if (visible) shown++;
      });

      if (visibleCount) visibleCount.textContent = String(shown);
    };

    [filterQ, filterLifecycle, filterBelowMin, filterOpenOnly].forEach((el) => {
      el?.addEventListener('input', applyFilters);
      el?.addEventListener('change', applyFilters);
    });

    filterReset?.addEventListener('click', () => {
      if (filterQ) filterQ.value = '';
      if (filterLifecycle) filterLifecycle.value = '';
      if (filterBelowMin) filterBelowMin.checked = false;
      if (filterOpenOnly) filterOpenOnly.checked = false;
      applyFilters();
    });

    const dialog = root.querySelector('#proc-order-material-dialog');
    const dialogGuid = root.querySelector('#proc-order-dialog-guid');
    const dialogTitle = root.querySelector('#proc-order-dialog-title');
    const dialogSubtitle = root.querySelector('#proc-order-dialog-subtitle');
    const dialogLifecycle = root.querySelector('#proc-order-dialog-lifecycle');
    const dialogMin = root.querySelector('#proc-order-dialog-min');
    const dialogMax = root.querySelector('#proc-order-dialog-max');
    const dialogNotes = root.querySelector('#proc-order-dialog-notes');
    const dialogCancel = root.querySelector('#proc-order-dialog-cancel');

    linesRoot.querySelectorAll('.proc-order-edit-material').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!dialog || !dialogGuid) return;
        const code = btn.getAttribute('data-material-code') || '';
        const name = btn.getAttribute('data-material-name') || '';
        dialogGuid.value = btn.getAttribute('data-material-guid') || '';
        if (dialogTitle) dialogTitle.textContent = name || 'ضبط المادة';
        if (dialogSubtitle) dialogSubtitle.textContent = code !== '' ? 'كود: ' + code : '';
        if (dialogLifecycle) dialogLifecycle.value = btn.getAttribute('data-lifecycle') || 'new';
        if (dialogMin) dialogMin.value = btn.getAttribute('data-min') || '';
        if (dialogMax) dialogMax.value = btn.getAttribute('data-max') || '';
        if (dialogNotes) dialogNotes.value = btn.getAttribute('data-notes') || '';
        if (typeof dialog.showModal === 'function') dialog.showModal();
      });
    });

    dialogCancel?.addEventListener('click', () => dialog?.close());
    dialog?.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.portalProcurementOrdersInit(document));
  } else {
    window.portalProcurementOrdersInit(document);
  }
})();
